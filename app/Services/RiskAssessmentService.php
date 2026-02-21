<?php

namespace App\Services;

use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\RiskLog;
use Carbon\Carbon;

class RiskAssessmentService
{
    /**
     * Пороговые значения для уровней риска
     */
    protected const RISK_THRESHOLDS = [
        'low' => 0.3,
        'medium' => 0.6,
        'high' => 0.8,
    ];

    /**
     * Весовые коэффициенты для факторов риска
     */
    protected const RISK_WEIGHTS = [
        'new_address' => 0.2,
        'large_amount' => 0.25,
        'frequent_transactions' => 0.15,
        'suspicious_pattern' => 0.2,
        'blacklisted_address' => 0.3,
        'unusual_time' => 0.1,
        'velocity_check' => 0.15,
        'wallet_age' => 0.1,
    ];

    /**
     * Оценка риска для вывода средств
     */
    public function assessWithdrawal(
        Wallet $wallet,
        string $amount,
        string $fee,
        string $toAddress
    ): array {
        $riskFactors = [];
        $riskScore = 0.0;

        // Проверка на черный список адресов
        if ($this->isBlacklistedAddress($toAddress)) {
            $riskFactors[] = 'blacklisted_address';
            $riskScore += self::RISK_WEIGHTS['blacklisted_address'];
        }

        // Проверка нового адреса
        if ($this->isNewAddress($wallet, $toAddress)) {
            $riskFactors[] = 'new_address';
            $riskScore += self::RISK_WEIGHTS['new_address'];
        }

        // Проверка крупной суммы
        if ($this->isLargeAmount($wallet, $amount)) {
            $riskFactors[] = 'large_amount';
            $riskScore += self::RISK_WEIGHTS['large_amount'];
        }

        // Проверка частоты транзакций
        if ($this->hasFrequentTransactions($wallet)) {
            $riskFactors[] = 'frequent_transactions';
            $riskScore += self::RISK_WEIGHTS['frequent_transactions'];
        }

        // Проверка подозрительных паттернов
        if ($this->hasSuspiciousPattern($wallet, $amount)) {
            $riskFactors[] = 'suspicious_pattern';
            $riskScore += self::RISK_WEIGHTS['suspicious_pattern'];
        }

        // Проверка необычного времени
        if ($this->isUnusualTime()) {
            $riskFactors[] = 'unusual_time';
            $riskScore += self::RISK_WEIGHTS['unusual_time'];
        }

        // Проверка скорости транзакций (velocity)
        if ($this->exceedsVelocityLimit($wallet, $amount)) {
            $riskFactors[] = 'velocity_check';
            $riskScore += self::RISK_WEIGHTS['velocity_check'];
        }

        // Проверка возраста кошелька
        if ($this->isWalletTooNew($wallet)) {
            $riskFactors[] = 'wallet_age';
            $riskScore += self::RISK_WEIGHTS['wallet_age'];
        }

        // Нормализация риска (максимум 1.0)
        $riskScore = min($riskScore, 1.0);

        // Определение уровня риска
        $riskLevel = $this->getRiskLevel($riskScore);

        // Принятие решения
        $decision = $this->makeDecision($riskScore, $riskLevel, $riskFactors);

        return [
            'score' => $riskScore,
            'level' => $riskLevel,
            'factors' => $riskFactors,
            'decision' => $decision,
        ];
    }

    /**
     * Оценка риска для внутреннего платежа
     */
    public function assessPayment(
        Wallet $fromWallet,
        Wallet $toWallet,
        string $amount
    ): array {
        $riskFactors = [];
        $riskScore = 0.0;

        // Проверка крупной суммы
        if ($this->isLargeAmount($fromWallet, $amount)) {
            $riskFactors[] = 'large_amount';
            $riskScore += self::RISK_WEIGHTS['large_amount'];
        }

        // Проверка частоты транзакций
        if ($this->hasFrequentTransactions($fromWallet)) {
            $riskFactors[] = 'frequent_transactions';
            $riskScore += self::RISK_WEIGHTS['frequent_transactions'];
        }

        // Проверка подозрительных паттернов
        if ($this->hasSuspiciousPattern($fromWallet, $amount)) {
            $riskFactors[] = 'suspicious_pattern';
            $riskScore += self::RISK_WEIGHTS['suspicious_pattern'];
        }

        // Проверка скорости транзакций
        if ($this->exceedsVelocityLimit($fromWallet, $amount)) {
            $riskFactors[] = 'velocity_check';
            $riskScore += self::RISK_WEIGHTS['velocity_check'];
        }

        // Проверка возраста кошелька отправителя
        if ($this->isWalletTooNew($fromWallet)) {
            $riskFactors[] = 'wallet_age';
            $riskScore += self::RISK_WEIGHTS['wallet_age'];
        }

        // Проверка на заморозку кошелька получателя
        if ($toWallet->isFrozen()) {
            $riskFactors[] = 'recipient_frozen';
            $riskScore += 0.5;
        }

        // Нормализация риска
        $riskScore = min($riskScore, 1.0);

        // Определение уровня риска
        $riskLevel = $this->getRiskLevel($riskScore);

        // Принятие решения
        $decision = $this->makeDecision($riskScore, $riskLevel, $riskFactors);

        return [
            'score' => $riskScore,
            'level' => $riskLevel,
            'factors' => $riskFactors,
            'decision' => $decision,
        ];
    }

    /**
     * Проверка адреса в черном списке
     */
    protected function isBlacklistedAddress(string $address): bool
    {
        $blacklist = config('crypto.blacklist', []);
        return in_array(strtolower($address), array_map('strtolower', $blacklist));
    }

    /**
     * Проверка нового адреса получателя
     */
    protected function isNewAddress(Wallet $wallet, string $address): bool
    {
        $existingTransactions = Transaction::where('wallet_id', $wallet->id)
            ->where('to_address', $address)
            ->where('status', Transaction::STATUS_COMPLETED)
            ->count();

        return $existingTransactions === 0;
    }

    /**
     * Проверка крупной суммы
     */
    protected function isLargeAmount(Wallet $wallet, string $amount): bool
    {
        $threshold = config('crypto.risk.large_amount_threshold.' . strtolower($wallet->currency), '10');
        return bccomp($amount, $threshold, 18) > 0;
    }

    /**
     * Проверка частоты транзакций
     */
    protected function hasFrequentTransactions(Wallet $wallet): bool
    {
        $limit = config('crypto.risk.frequent_transactions_limit', 10);
        $period = config('crypto.risk.frequent_transactions_period', 24);

        $count = Transaction::where('wallet_id', $wallet->id)
            ->where('created_at', '>=', Carbon::now()->subHours($period))
            ->count();

        return $count > $limit;
    }

    /**
     * Проверка подозрительных паттернов
     */
    protected function hasSuspiciousPattern(Wallet $wallet, string $amount): bool
    {
        // Проверка на структурирование (smurfing) - несколько транзакций чуть ниже порога
        $threshold = config('crypto.risk.large_amount_threshold.' . strtolower($wallet->currency), '10');
        $smurfingThreshold = bcmul($threshold, '0.9', 18);

        $suspiciousCount = Transaction::where('wallet_id', $wallet->id)
            ->where('type', Transaction::TYPE_WITHDRAW)
            ->where('created_at', '>=', Carbon::now()->subHours(24))
            ->whereBetween('amount', [
                bcmul($smurfingThreshold, '0.8', 18),
                $smurfingThreshold
            ])
            ->count();

        return $suspiciousCount >= 3;
    }

    /**
     * Проверка необычного времени (ночь по UTC)
     */
    protected function isUnusualTime(): bool
    {
        $hour = Carbon::now()->hour;
        $unusualHours = config('crypto.risk.unusual_hours', [0, 1, 2, 3, 4, 5]);
        return in_array($hour, $unusualHours);
    }

    /**
     * Проверка превышения лимита скорости транзакций
     */
    protected function exceedsVelocityLimit(Wallet $wallet, string $amount): bool
    {
        $dailyLimit = config('crypto.risk.daily_limit.' . strtolower($wallet->currency), '100');
        $hourlyLimit = config('crypto.risk.hourly_limit.' . strtolower($wallet->currency), '10');

        // Проверка дневного лимита
        $dailyTotal = Transaction::where('wallet_id', $wallet->id)
            ->where('type', Transaction::TYPE_WITHDRAW)
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->sum('amount');

        if (bccomp(bcadd($dailyTotal, $amount, 18), $dailyLimit, 18) > 0) {
            return true;
        }

        // Проверка часового лимита
        $hourlyTotal = Transaction::where('wallet_id', $wallet->id)
            ->where('type', Transaction::TYPE_WITHDRAW)
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->sum('amount');

        if (bccomp(bcadd($hourlyTotal, $amount, 18), $hourlyLimit, 18) > 0) {
            return true;
        }

        return false;
    }

    /**
     * Проверка возраста кошелька
     */
    protected function isWalletTooNew(Wallet $wallet): bool
    {
        $minAge = config('crypto.risk.min_wallet_age_hours', 24);
        return $wallet->created_at > Carbon::now()->subHours($minAge);
    }

    /**
     * Определение уровня риска по числовому значению
     */
    protected function getRiskLevel(float $score): string
    {
        if ($score >= self::RISK_THRESHOLDS['high']) {
            return RiskLog::LEVEL_CRITICAL;
        }

        if ($score >= self::RISK_THRESHOLDS['medium']) {
            return RiskLog::LEVEL_HIGH;
        }

        if ($score >= self::RISK_THRESHOLDS['low']) {
            return RiskLog::LEVEL_MEDIUM;
        }

        return RiskLog::LEVEL_LOW;
    }

    /**
     * Принятие решения на основе оценки риска
     */
    protected function makeDecision(float $score, string $level, array $factors): string
    {
        // Автоматическое одобрение для низкого риска
        if ($level === RiskLog::LEVEL_LOW) {
            return RiskLog::DECISION_AUTO_APPROVED;
        }

        // Автоматический отказ для критического риска
        if ($level === RiskLog::LEVEL_CRITICAL) {
            return RiskLog::DECISION_REJECTED;
        }

        // Требует ручной проверки для высокого риска
        if ($level === RiskLog::LEVEL_HIGH) {
            return RiskLog::DECISION_REVIEW;
        }

        // Средний риск - автоматическое одобрение
        return RiskLog::DECISION_APPROVED;
    }

    /**
     * Получить историю рисков для пользователя
     */
    public function getUserRiskHistory(int $userId, int $limit = 50): array
    {
        return RiskLog::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Получить статистику рисков для пользователя
     */
    public function getUserRiskStats(int $userId): array
    {
        $totalTransactions = RiskLog::where('user_id', $userId)->count();
        $highRiskCount = RiskLog::where('user_id', $userId)
            ->whereIn('risk_level', [RiskLog::LEVEL_HIGH, RiskLog::LEVEL_CRITICAL])
            ->count();
        $rejectedCount = RiskLog::where('user_id', $userId)
            ->where('decision', RiskLog::DECISION_REJECTED)
            ->count();

        return [
            'total_transactions' => $totalTransactions,
            'high_risk_count' => $highRiskCount,
            'rejected_count' => $rejectedCount,
            'risk_percentage' => $totalTransactions > 0
                ? round(($highRiskCount / $totalTransactions) * 100, 2)
                : 0,
        ];
    }
}
