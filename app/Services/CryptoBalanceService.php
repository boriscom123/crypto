<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\RiskLog;
use App\Services\RiskAssessmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Exception;

class CryptoBalanceService
{
    public function __construct(
        protected RiskAssessmentService $riskService
    ) {}

    /**
     * Создать кошелек для пользователя
     */
    public function createWallet(int $userId, string $currency, string $address): Wallet
    {
        return Wallet::create([
            'user_id' => $userId,
            'currency' => strtoupper($currency),
            'address' => $address,
            'balance' => 0,
            'locked_balance' => 0,
            'status' => Wallet::STATUS_ACTIVE,
        ]);
    }

    /**
     * Получить или создать кошелек
     */
    public function getOrCreateWallet(int $userId, string $currency, string $address): Wallet
    {
        return Wallet::firstOrCreate(
            [
                'user_id' => $userId,
                'currency' => strtoupper($currency),
                'address' => $address,
            ],
            [
                'balance' => 0,
                'locked_balance' => 0,
                'status' => Wallet::STATUS_ACTIVE,
            ]
        );
    }

    /**
     * Зачисление средств (депозит)
     */
    public function deposit(
        Wallet $wallet,
        string $amount,
        string $txHash,
        string $fromAddress,
        array $metadata = []
    ): Transaction {
        DB::transaction(function () use ($wallet, $amount, $txHash, $fromAddress, $metadata) {
            $wallet->increment('balance', $amount);

            Transaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $wallet->user_id,
                'type' => Transaction::TYPE_DEPOSIT,
                'amount' => $amount,
                'fee' => 0,
                'status' => Transaction::STATUS_COMPLETED,
                'tx_hash' => $txHash,
                'from_address' => $fromAddress,
                'to_address' => $wallet->address,
                'risk_score' => 0,
                'risk_level' => Transaction::RISK_LEVEL_LOW,
                'metadata' => $metadata,
            ]);
        });

        return Transaction::where('tx_hash', $txHash)
            ->where('type', Transaction::TYPE_DEPOSIT)
            ->latest('id')
            ->first();
    }

    /**
     * Инициировать вывод средств с проверкой рисков
     */
    public function withdraw(
        Wallet $wallet,
        string $amount,
        string $toAddress,
        string $description = '',
        array $metadata = []
    ): Transaction {
        return DB::transaction(function () use ($wallet, $amount, $toAddress, $description, $metadata) {
            $this->validateWithdraw($wallet, $amount);

            $fee = $this->calculateFee($amount, $wallet->currency);
            $totalAmount = bcadd($amount, $fee, 18);

            $this->checkSufficientBalance($wallet, $totalAmount);

            // Оценка рисков
            $riskAssessment = $this->riskService->assessWithdrawal(
                $wallet,
                $amount,
                $fee,
                $toAddress
            );

            // Логирование риска
            $riskLog = RiskLog::create([
                'wallet_id' => $wallet->id,
                'user_id' => $wallet->user_id,
                'transaction_id' => null,
                'risk_score' => $riskAssessment['score'],
                'risk_level' => $riskAssessment['level'],
                'risk_factors' => $riskAssessment['factors'],
                'decision' => $riskAssessment['decision'],
                'metadata' => array_merge($metadata, [
                    'withdraw_amount' => $amount,
                    'withdraw_fee' => $fee,
                    'to_address' => $toAddress,
                ]),
            ]);

            // Определение статуса транзакции
            $status = $riskAssessment['decision'] === RiskLog::DECISION_REJECTED
                ? Transaction::STATUS_FAILED
                : ($riskAssessment['decision'] === RiskLog::DECISION_REVIEW
                    ? Transaction::STATUS_RISK_REVIEW
                    : Transaction::STATUS_PENDING);

            // Блокировка средств
            if ($status !== Transaction::STATUS_FAILED) {
                $wallet->increment('locked_balance', $totalAmount);
            }

            $transaction = Transaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $wallet->user_id,
                'type' => Transaction::TYPE_WITHDRAW,
                'amount' => $amount,
                'fee' => $fee,
                'status' => $status,
                'tx_hash' => null,
                'from_address' => $wallet->address,
                'to_address' => $toAddress,
                'description' => $description,
                'risk_score' => $riskAssessment['score'],
                'risk_level' => $riskAssessment['level'],
                'metadata' => $metadata,
            ]);

            $riskLog->update(['transaction_id' => $transaction->id]);

            return $transaction;
        });
    }

    /**
     * Подтверждение вывода средств (после проверки рисков)
     */
    public function confirmWithdraw(Transaction $transaction): Transaction
    {
        return DB::transaction(function () use ($transaction) {
            if (!$transaction->isPending() && !$transaction->isRiskReview()) {
                throw new Exception('Transaction cannot be confirmed in current status');
            }

            $wallet = $transaction->wallet;
            $totalAmount = bcadd($transaction->amount, $transaction->fee, 18);

            // Списание заблокированных средств
            $wallet->decrement('locked_balance', $totalAmount);
            $wallet->decrement('balance', $totalAmount);

            $transaction->update([
                'status' => Transaction::STATUS_PROCESSING,
            ]);

            return $transaction;
        });
    }

    /**
     * Завершение вывода средств (после подтверждения блокчейном)
     */
    public function completeWithdraw(Transaction $transaction, string $txHash): Transaction
    {
        return $transaction->update([
            'status' => Transaction::STATUS_COMPLETED,
            'tx_hash' => $txHash,
        ]);
    }

    /**
     * Отмена вывода средств
     */
    public function cancelWithdraw(Transaction $transaction): Transaction
    {
        return DB::transaction(function () use ($transaction) {
            if (!$transaction->isPending() && !$transaction->isRiskReview()) {
                throw new Exception('Transaction cannot be cancelled in current status');
            }

            $wallet = $transaction->wallet;
            $totalAmount = bcadd($transaction->amount, $transaction->fee, 18);

            // Разблокировка средств
            $wallet->decrement('locked_balance', $totalAmount);

            $transaction->update([
                'status' => Transaction::STATUS_CANCELLED,
            ]);

            return $transaction;
        });
    }

    /**
     * Платеж (внутренний перевод между пользователями)
     */
    public function payment(
        Wallet $fromWallet,
        Wallet $toWallet,
        string $amount,
        string $description = '',
        array $metadata = []
    ): array {
        return DB::transaction(function () use ($fromWallet, $toWallet, $amount, $description, $metadata) {
            $this->validateWithdraw($fromWallet, $amount);
            $this->checkSufficientBalance($fromWallet, $amount);

            // Оценка рисков
            $riskAssessment = $this->riskService->assessPayment(
                $fromWallet,
                $toWallet,
                $amount
            );

            // Логирование риска
            RiskLog::create([
                'wallet_id' => $fromWallet->id,
                'user_id' => $fromWallet->user_id,
                'transaction_id' => null,
                'risk_score' => $riskAssessment['score'],
                'risk_level' => $riskAssessment['level'],
                'risk_factors' => $riskAssessment['factors'],
                'decision' => $riskAssessment['decision'],
                'metadata' => array_merge($metadata, [
                    'payment_amount' => $amount,
                    'to_wallet_id' => $toWallet->id,
                ]),
            ]);

            if ($riskAssessment['decision'] === RiskLog::DECISION_REJECTED) {
                throw new Exception('Payment rejected by risk assessment');
            }

            // Списание средств
            $fromWallet->decrement('balance', $amount);

            // Зачисление средств
            $toWallet->increment('balance', $amount);

            // Создание транзакций
            $fromTransaction = Transaction::create([
                'wallet_id' => $fromWallet->id,
                'user_id' => $fromWallet->user_id,
                'type' => Transaction::TYPE_PAYMENT,
                'amount' => $amount,
                'fee' => 0,
                'status' => Transaction::STATUS_COMPLETED,
                'from_address' => $fromWallet->address,
                'to_address' => $toWallet->address,
                'description' => 'Payment: ' . $description,
                'risk_score' => $riskAssessment['score'],
                'risk_level' => $riskAssessment['level'],
                'metadata' => array_merge($metadata, ['direction' => 'out']),
            ]);

            $toTransaction = Transaction::create([
                'wallet_id' => $toWallet->id,
                'user_id' => $toWallet->user_id,
                'type' => Transaction::TYPE_DEPOSIT,
                'amount' => $amount,
                'fee' => 0,
                'status' => Transaction::STATUS_COMPLETED,
                'from_address' => $fromWallet->address,
                'to_address' => $toWallet->address,
                'description' => 'Received payment: ' . $description,
                'risk_score' => $riskAssessment['score'],
                'risk_level' => $riskAssessment['level'],
                'metadata' => array_merge($metadata, ['direction' => 'in']),
            ]);

            return [
                'from_transaction' => $fromTransaction,
                'to_transaction' => $toTransaction,
            ];
        });
    }

    /**
     * Блокировка средств на кошельке
     */
    public function lockFunds(Wallet $wallet, string $amount): void
    {
        $this->checkSufficientBalance($wallet, $amount);

        $wallet->increment('locked_balance', $amount);
    }

    /**
     * Разблокировка средств на кошельке
     */
    public function unlockFunds(Wallet $wallet, string $amount): void
    {
        if (bccomp($wallet->locked_balance, $amount, 18) < 0) {
            throw new Exception('Insufficient locked balance');
        }

        $wallet->decrement('locked_balance', $amount);
    }

    /**
     * Заморозка кошелька
     */
    public function freezeWallet(Wallet $wallet, string $reason = ''): void
    {
        $wallet->update([
            'status' => Wallet::STATUS_FROZEN,
        ]);

        RiskLog::create([
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'risk_score' => 1.0,
            'risk_level' => RiskLog::LEVEL_CRITICAL,
            'risk_factors' => ['wallet_frozen'],
            'decision' => RiskLog::DECISION_REJECTED,
            'review_notes' => $reason,
        ]);
    }

    /**
     * Разморозка кошелька
     */
    public function unfreezeWallet(Wallet $wallet): void
    {
        $wallet->update([
            'status' => Wallet::STATUS_ACTIVE,
        ]);
    }

    /**
     * Получить баланс кошелька
     */
    public function getBalance(Wallet $wallet): array
    {
        return [
            'total' => $wallet->balance,
            'locked' => $wallet->locked_balance,
            'available' => $wallet->getAvailableBalanceAttribute(),
        ];
    }

    /**
     * Рассчитать комиссию
     */
    protected function calculateFee(string $amount, string $currency): string
    {
        $feePercent = config('crypto.fees.' . strtolower($currency) . '_percent', 0.001);
        $minFee = config('crypto.fees.' . strtolower($currency) . '_min', 0.0001);

        $calculatedFee = bcmul($amount, $feePercent, 18);

        return bccomp($calculatedFee, $minFee, 18) < 0 ? $minFee : $calculatedFee;
    }

    /**
     * Проверка возможности вывода
     */
    protected function validateWithdraw(Wallet $wallet, string $amount): void
    {
        if (!$wallet->isActive()) {
            throw new Exception('Wallet is not active');
        }

        if (bccomp($amount, 0, 18) <= 0) {
            throw new Exception('Amount must be greater than 0');
        }
    }

    /**
     * Проверка достаточности средств
     */
    protected function checkSufficientBalance(Wallet $wallet, string $amount): void
    {
        $available = $wallet->getAvailableBalanceAttribute();

        if (bccomp($available, $amount, 18) < 0) {
            throw new Exception('Insufficient balance');
        }
    }
}
