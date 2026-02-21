<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Services\CryptoBalanceService;
use App\Services\RiskAssessmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Exception;

class CryptoBalanceController extends Controller
{
    public function __construct(
        protected CryptoBalanceService $balanceService,
        protected RiskAssessmentService $riskService
    ) {}

    /**
     * Получить балансы пользователя
     */
    public function balances(): JsonResponse
    {
        $user = Auth::user();
        $wallets = $user->wallets()->with('transactions.latest')->get();

        $balances = $wallets->map(function (Wallet $wallet) {
            return [
                'id' => $wallet->id,
                'currency' => $wallet->currency,
                'address' => $wallet->address,
                'balance' => $this->balanceService->getBalance($wallet),
                'status' => $wallet->status,
                'created_at' => $wallet->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $balances,
        ]);
    }

    /**
     * Создать кошелек
     */
    public function createWallet(Request $request): JsonResponse
    {
        $request->validate([
            'currency' => 'required|string|max:10',
            'address' => 'required|string|max:255',
        ]);

        $user = Auth::user();

        try {
            $wallet = $this->balanceService->createWallet(
                $user->id,
                $request->currency,
                $request->address
            );

            return response()->json([
                'message' => 'Wallet created successfully',
                'data' => [
                    'id' => $wallet->id,
                    'currency' => $wallet->currency,
                    'address' => $wallet->address,
                    'status' => $wallet->status,
                ],
            ], 201);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to create wallet',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Получить баланс конкретного кошелька
     */
    public function walletBalance(int $walletId): JsonResponse
    {
        $user = Auth::user();
        $wallet = $user->wallets()->findOrFail($walletId);

        return response()->json([
            'data' => [
                'wallet_id' => $wallet->id,
                'currency' => $wallet->currency,
                'balance' => $this->balanceService->getBalance($wallet),
            ],
        ]);
    }

    /**
     * Зачисление средств (депозит)
     */
    public function deposit(Request $request): JsonResponse
    {
        $request->validate([
            'wallet_id' => 'required|exists:wallets,id',
            'amount' => 'required|numeric|gt:0',
            'tx_hash' => 'required|string|max:255',
            'from_address' => 'required|string|max:255',
        ]);

        $user = Auth::user();
        $wallet = $user->wallets()->findOrFail($request->wallet_id);

        try {
            $transaction = $this->balanceService->deposit(
                $wallet,
                (string) $request->amount,
                $request->tx_hash,
                $request->from_address
            );

            return response()->json([
                'message' => 'Deposit completed successfully',
                'data' => [
                    'transaction_id' => $transaction->id,
                    'amount' => $transaction->amount,
                    'tx_hash' => $transaction->tx_hash,
                    'status' => $transaction->status,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to process deposit',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Вывод средств
     */
    public function withdraw(Request $request): JsonResponse
    {
        $request->validate([
            'wallet_id' => 'required|exists:wallets,id',
            'amount' => 'required|numeric|gt:0',
            'to_address' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
        ]);

        $user = Auth::user();
        $wallet = $user->wallets()->findOrFail($request->wallet_id);

        try {
            $transaction = $this->balanceService->withdraw(
                $wallet,
                (string) $request->amount,
                $request->to_address,
                $request->description ?? ''
            );

            $statusCode = $transaction->status === Transaction::STATUS_FAILED ? 400 : 201;

            return response()->json([
                'message' => $transaction->status === Transaction::STATUS_FAILED
                    ? 'Withdrawal rejected by risk assessment'
                    : 'Withdrawal initiated',
                'data' => [
                    'transaction_id' => $transaction->id,
                    'amount' => $transaction->amount,
                    'fee' => $transaction->fee,
                    'to_address' => $transaction->to_address,
                    'status' => $transaction->status,
                    'risk_level' => $transaction->risk_level,
                    'risk_score' => $transaction->risk_score,
                ],
            ], $statusCode);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to process withdrawal',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Подтверждение вывода (для транзакций на проверке)
     */
    public function confirmWithdraw(int $transactionId): JsonResponse
    {
        $user = Auth::user();
        $transaction = Transaction::where('user_id', $user->id)
            ->findOrFail($transactionId);

        try {
            $transaction = $this->balanceService->confirmWithdraw($transaction);

            return response()->json([
                'message' => 'Withdrawal confirmed',
                'data' => [
                    'transaction_id' => $transaction->id,
                    'status' => $transaction->status,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to confirm withdrawal',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Отмена вывода
     */
    public function cancelWithdraw(int $transactionId): JsonResponse
    {
        $user = Auth::user();
        $transaction = Transaction::where('user_id', $user->id)
            ->findOrFail($transactionId);

        try {
            $transaction = $this->balanceService->cancelWithdraw($transaction);

            return response()->json([
                'message' => 'Withdrawal cancelled',
                'data' => [
                    'transaction_id' => $transaction->id,
                    'status' => $transaction->status,
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to cancel withdrawal',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Внутренний платеж
     */
    public function payment(Request $request): JsonResponse
    {
        $request->validate([
            'from_wallet_id' => 'required|exists:wallets,id',
            'to_address' => 'required|string|max:255',
            'amount' => 'required|numeric|gt:0',
            'description' => 'nullable|string|max:500',
        ]);

        $user = Auth::user();
        $fromWallet = $user->wallets()->findOrFail($request->from_wallet_id);

        // Найти кошелек получателя по адресу
        $toWallet = Wallet::where('address', $request->to_address)->first();

        if (!$toWallet) {
            return response()->json([
                'message' => 'Recipient wallet not found',
            ], 404);
        }

        try {
            $transactions = $this->balanceService->payment(
                $fromWallet,
                $toWallet,
                (string) $request->amount,
                $request->description ?? ''
            );

            return response()->json([
                'message' => 'Payment completed successfully',
                'data' => [
                    'from_transaction' => [
                        'id' => $transactions['from_transaction']->id,
                        'amount' => $transactions['from_transaction']->amount,
                        'status' => $transactions['from_transaction']->status,
                    ],
                    'to_transaction' => [
                        'id' => $transactions['to_transaction']->id,
                        'amount' => $transactions['to_transaction']->amount,
                        'status' => $transactions['to_transaction']->status,
                    ],
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Failed to process payment',
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * История транзакций
     */
    public function transactions(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = Transaction::where('user_id', $user->id)
            ->with('wallet');

        // Фильтры
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('wallet_id')) {
            $query->where('wallet_id', $request->wallet_id);
        }

        $transactions = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => $transactions->items(),
            'meta' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ]);
    }

    /**
     * Детали транзакции
     */
    public function transaction(int $transactionId): JsonResponse
    {
        $user = Auth::user();
        $transaction = Transaction::where('user_id', $user->id)
            ->with('wallet')
            ->findOrFail($transactionId);

        return response()->json([
            'data' => $transaction,
        ]);
    }

    /**
     * Статистика рисков пользователя
     */
    public function riskStats(): JsonResponse
    {
        $user = Auth::user();
        $stats = $this->riskService->getUserRiskStats($user->id);

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * История рисков пользователя
     */
    public function riskHistory(Request $request): JsonResponse
    {
        $user = Auth::user();
        $limit = $request->get('limit', 50);

        $history = $this->riskService->getUserRiskHistory($user->id, $limit);

        return response()->json([
            'data' => $history,
        ]);
    }
}
