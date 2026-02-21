<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Services\RiskAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;

class RiskAssessmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RiskAssessmentService $riskService;
    protected User $user;
    protected Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->riskService = new RiskAssessmentService();
        $this->user = User::factory()->create();
        $this->wallet = Wallet::create([
            'user_id' => $this->user->id,
            'currency' => 'BTC',
            'address' => 'test_address',
            'balance' => 100,
            'locked_balance' => 0,
            'status' => Wallet::STATUS_ACTIVE,
        ]);
    }

    public function test_low_risk_assessment(): void
    {
        $result = $this->riskService->assessWithdrawal(
            $this->wallet,
            '0.1',
            '0.0001',
            'known_address_123'
        );

        $this->assertLessThan(0.3, $result['score']);
        $this->assertEquals('low', $result['level']);
        $this->assertEquals('auto_approved', $result['decision']);
    }

    public function test_blacklisted_address(): void
    {
        // Добавляем адрес в черный список через config
        config(['crypto.blacklist' => ['blacklisted_address']]);

        $result = $this->riskService->assessWithdrawal(
            $this->wallet,
            '0.1',
            '0.0001',
            'blacklisted_address'
        );

        $this->assertContains('blacklisted_address', $result['factors']);
        $this->assertGreaterThanOrEqual(0.3, $result['score']);
    }

    public function test_large_amount(): void
    {
        config(['crypto.risk.large_amount_threshold.btc' => '1']);

        $result = $this->riskService->assessWithdrawal(
            $this->wallet,
            '5', // Больше порога в 1 BTC
            '0.001',
            'recipient_address'
        );

        $this->assertContains('large_amount', $result['factors']);
        $this->assertGreaterThanOrEqual(0.25, $result['score']);
    }

    public function test_frequent_transactions(): void
    {
        config([
            'crypto.risk.frequent_transactions_limit' => 3,
            'crypto.risk.frequent_transactions_period' => 24,
        ]);

        // Создаем несколько транзакций
        for ($i = 0; $i < 5; $i++) {
            Transaction::create([
                'wallet_id' => $this->wallet->id,
                'user_id' => $this->user->id,
                'type' => Transaction::TYPE_WITHDRAW,
                'amount' => '0.1',
                'fee' => '0.0001',
                'status' => Transaction::STATUS_COMPLETED,
                'to_address' => 'address_' . $i,
            ]);
        }

        $result = $this->riskService->assessWithdrawal(
            $this->wallet,
            '0.1',
            '0.0001',
            'new_recipient'
        );

        $this->assertContains('frequent_transactions', $result['factors']);
    }

    public function test_new_wallet(): void
    {
        config(['crypto.risk.min_wallet_age_hours' => 24]);

        // Кошелек только что создан, должен быть слишком новым
        $result = $this->riskService->assessWithdrawal(
            $this->wallet,
            '0.1',
            '0.0001',
            'recipient'
        );

        $this->assertContains('wallet_age', $result['factors']);
    }

    public function test_unusual_time(): void
    {
        // Тестируем для необычного времени (ночь)
        config(['crypto.risk.unusual_hours' => [Carbon::now()->hour]]);

        $result = $this->riskService->assessWithdrawal(
            $this->wallet,
            '0.1',
            '0.0001',
            'recipient'
        );

        $this->assertContains('unusual_time', $result['factors']);
    }

    public function test_velocity_limit_exceeded(): void
    {
        config(['crypto.risk.hourly_limit.btc' => '1']);

        // Создаем транзакцию на сумму, близкую к лимиту
        Transaction::create([
            'wallet_id' => $this->wallet->id,
            'user_id' => $this->user->id,
            'type' => Transaction::TYPE_WITHDRAW,
            'amount' => '0.9',
            'fee' => '0.001',
            'status' => Transaction::STATUS_COMPLETED,
            'created_at' => Carbon::now()->subMinutes(30),
        ]);

        $result = $this->riskService->assessWithdrawal(
            $this->wallet,
            '0.5', // Вместе с предыдущей превысит лимит
            '0.001',
            'recipient'
        );

        $this->assertContains('velocity_check', $result['factors']);
    }

    public function test_risk_level_calculation(): void
    {
        // Тестируем расчет уровня риска
        $this->assertEquals('low', $this->getRiskLevel(0.1));
        $this->assertEquals('medium', $this->getRiskLevel(0.4));
        $this->assertEquals('high', $this->getRiskLevel(0.7));
        $this->assertEquals('critical', $this->getRiskLevel(0.9));
    }

    public function test_decision_making(): void
    {
        // Низкий риск - авто одобрение
        $this->assertEquals('auto_approved', $this->makeDecision('low'));

        // Средний риск - одобрение
        $this->assertEquals('approved', $this->makeDecision('medium'));

        // Высокий риск - на проверку
        $this->assertEquals('review', $this->makeDecision('high'));

        // Критический риск - отказ
        $this->assertEquals('rejected', $this->makeDecision('critical'));
    }

    public function test_payment_risk_assessment(): void
    {
        $toWallet = Wallet::create([
            'user_id' => User::factory()->create()->id,
            'currency' => 'BTC',
            'address' => 'to_wallet_address',
            'balance' => 0,
            'locked_balance' => 0,
            'status' => Wallet::STATUS_ACTIVE,
        ]);

        $result = $this->riskService->assessPayment(
            $this->wallet,
            $toWallet,
            '0.1'
        );

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('level', $result);
        $this->assertArrayHasKey('factors', $result);
        $this->assertArrayHasKey('decision', $result);
    }

    public function test_payment_to_frozen_wallet(): void
    {
        $toWallet = Wallet::create([
            'user_id' => User::factory()->create()->id,
            'currency' => 'BTC',
            'address' => 'to_wallet_address',
            'balance' => 0,
            'locked_balance' => 0,
            'status' => Wallet::STATUS_FROZEN,
        ]);

        $result = $this->riskService->assessPayment(
            $this->wallet,
            $toWallet,
            '0.1'
        );

        $this->assertContains('recipient_frozen', $result['factors']);
    }

    public function test_user_risk_stats(): void
    {
        // Создаем несколько risk logs
        for ($i = 0; $i < 5; $i++) {
            \App\Models\RiskLog::create([
                'user_id' => $this->user->id,
                'wallet_id' => $this->wallet->id,
                'risk_score' => 0.1,
                'risk_level' => 'low',
                'risk_factors' => ['test'],
                'decision' => 'auto_approved',
            ]);
        }

        $stats = $this->riskService->getUserRiskStats($this->user->id);

        $this->assertEquals(5, $stats['total_transactions']);
        $this->assertEquals(0, $stats['high_risk_count']);
        $this->assertEquals(0, $stats['rejected_count']);
    }

    public function test_user_risk_history(): void
    {
        for ($i = 0; $i < 10; $i++) {
            \App\Models\RiskLog::create([
                'user_id' => $this->user->id,
                'wallet_id' => $this->wallet->id,
                'risk_score' => 0.1,
                'risk_level' => 'low',
                'risk_factors' => ['test'],
                'decision' => 'auto_approved',
            ]);
        }

        $history = $this->riskService->getUserRiskHistory($this->user->id, 5);

        $this->assertCount(5, $history);
    }

    // Вспомогательные методы для тестирования protected методов
    private function getRiskLevel(float $score): string
    {
        $reflection = new \ReflectionClass($this->riskService);
        $method = $reflection->getMethod('getRiskLevel');
        $method->setAccessible(true);
        return $method->invoke($this->riskService, $score);
    }

    private function makeDecision(string $level): string
    {
        $reflection = new \ReflectionClass($this->riskService);
        $method = $reflection->getMethod('makeDecision');
        $method->setAccessible(true);
        return $method->invokeArgs($this->riskService, [0.5, $level, []]);
    }
}
