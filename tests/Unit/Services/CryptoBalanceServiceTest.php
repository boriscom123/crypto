<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use App\Models\RiskLog;
use App\Services\CryptoBalanceService;
use App\Services\RiskAssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CryptoBalanceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CryptoBalanceService $balanceService;
    protected RiskAssessmentService $riskService;
    protected User $user;
    protected Wallet $wallet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->riskService = new RiskAssessmentService();
        $this->balanceService = new CryptoBalanceService($this->riskService);

        $this->user = User::factory()->create();
        $this->wallet = $this->balanceService->createWallet(
            $this->user->id,
            'BTC',
            'test_wallet_address_123'
        );
    }

    public function test_create_wallet(): void
    {
        $wallet = $this->balanceService->createWallet(
            $this->user->id,
            'ETH',
            'eth_address_456'
        );

        $this->assertEquals('ETH', $wallet->currency);
        $this->assertEquals('eth_address_456', $wallet->address);
        $this->assertEquals('0', $wallet->balance);
        $this->assertEquals(Wallet::STATUS_ACTIVE, $wallet->status);
    }

    public function test_deposit(): void
    {
        $txHash = '0x' . bin2hex(random_bytes(32));
        $fromAddress = 'sender_address_789';
        $amount = '1.5';

        $transaction = $this->balanceService->deposit(
            $this->wallet,
            $amount,
            $txHash,
            $fromAddress
        );

        $this->wallet->refresh();

        $this->assertEquals(Transaction::TYPE_DEPOSIT, $transaction->type);
        $this->assertEquals($amount, $transaction->amount);
        $this->assertEquals(Transaction::STATUS_COMPLETED, $transaction->status);
        $this->assertEquals($txHash, $transaction->tx_hash);
        $this->assertEquals($amount, $this->wallet->balance);
    }

    public function test_withdraw_success(): void
    {
        // Сначала делаем депозит
        $this->balanceService->deposit(
            $this->wallet,
            '10',
            '0xdeposit123',
            'sender_address'
        );

        $toAddress = 'recipient_address_abc';
        $amount = '1';

        $transaction = $this->balanceService->withdraw(
            $this->wallet,
            $amount,
            $toAddress,
            'Test withdrawal'
        );

        $this->wallet->refresh();

        $this->assertEquals(Transaction::TYPE_WITHDRAW, $transaction->type);
        $this->assertEquals($amount, $transaction->amount);
        $this->assertGreaterThan(0, $transaction->fee);
        $this->assertContains($transaction->status, [
            Transaction::STATUS_PENDING,
            Transaction::STATUS_RISK_REVIEW,
        ]);
        $this->assertEquals($toAddress, $transaction->to_address);

        // Проверка блокировки средств
        $totalAmount = bcadd($amount, $transaction->fee, 18);
        $this->assertEquals($totalAmount, $this->wallet->locked_balance);
    }

    public function test_withdraw_insufficient_balance(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient balance');

        $this->balanceService->withdraw(
            $this->wallet,
            '100',
            'recipient_address'
        );
    }

    public function test_withdraw_inactive_wallet(): void
    {
        $this->wallet->update(['status' => Wallet::STATUS_FROZEN]);

        $this->expectException(\Exception::class);

        $this->balanceService->withdraw(
            $this->wallet,
            '1',
            'recipient_address'
        );
    }

    public function test_confirm_withdraw(): void
    {
        // Депозит и вывод
        $this->balanceService->deposit(
            $this->wallet,
            '10',
            '0xdeposit123',
            'sender'
        );

        $withdrawTx = $this->balanceService->withdraw(
            $this->wallet,
            '1',
            'recipient'
        );

        $this->balanceService->confirmWithdraw($withdrawTx);

        $withdrawTx->refresh();
        $this->wallet->refresh();

        $this->assertEquals(Transaction::STATUS_PROCESSING, $withdrawTx->status);
        $this->assertEquals('0', $this->wallet->locked_balance);
    }

    public function test_cancel_withdraw(): void
    {
        // Депозит и вывод
        $this->balanceService->deposit(
            $this->wallet,
            '10',
            '0xdeposit123',
            'sender'
        );

        $withdrawTx = $this->balanceService->withdraw(
            $this->wallet,
            '1',
            'recipient'
        );

        $this->balanceService->cancelWithdraw($withdrawTx);

        $withdrawTx->refresh();
        $this->wallet->refresh();

        $this->assertEquals(Transaction::STATUS_CANCELLED, $withdrawTx->status);
        $this->assertEquals('0', $this->wallet->locked_balance);
    }

    public function test_payment(): void
    {
        // Создаем второго пользователя и кошелек
        $user2 = User::factory()->create();
        $wallet2 = $this->balanceService->createWallet(
            $user2->id,
            'BTC',
            'wallet2_address'
        );

        // Депозит на первый кошелек
        $this->balanceService->deposit(
            $this->wallet,
            '10',
            '0xdeposit123',
            'sender'
        );

        $amount = '2';
        $description = 'Payment for services';

        $result = $this->balanceService->payment(
            $this->wallet,
            $wallet2,
            $amount,
            $description
        );

        $this->wallet->refresh();
        $wallet2->refresh();

        $this->assertInstanceOf(Transaction::class, $result['from_transaction']);
        $this->assertInstanceOf(Transaction::class, $result['to_transaction']);
        $this->assertEquals(Transaction::STATUS_COMPLETED, $result['from_transaction']->status);
        $this->assertEquals(Transaction::STATUS_COMPLETED, $result['to_transaction']->status);

        // Проверка балансов
        $this->assertEquals(bcsub('10', $amount, 18), $this->wallet->balance);
        $this->assertEquals($amount, $wallet2->balance);
    }

    public function test_payment_insufficient_balance(): void
    {
        $user2 = User::factory()->create();
        $wallet2 = $this->balanceService->createWallet(
            $user2->id,
            'BTC',
            'wallet2_address'
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient balance');

        $this->balanceService->payment(
            $this->wallet,
            $wallet2,
            '100',
            'Payment'
        );
    }

    public function test_freeze_wallet(): void
    {
        $this->balanceService->freezeWallet($this->wallet, 'Suspicious activity');

        $this->wallet->refresh();

        $this->assertEquals(Wallet::STATUS_FROZEN, $this->wallet->status);

        // Проверка создания risk log
        $riskLog = RiskLog::where('wallet_id', $this->wallet->id)
            ->latest()
            ->first();

        $this->assertNotNull($riskLog);
        $this->assertEquals(RiskLog::LEVEL_CRITICAL, $riskLog->risk_level);
    }

    public function test_unfreeze_wallet(): void
    {
        $this->balanceService->freezeWallet($this->wallet);
        $this->balanceService->unfreezeWallet($this->wallet);

        $this->wallet->refresh();

        $this->assertEquals(Wallet::STATUS_ACTIVE, $this->wallet->status);
    }

    public function test_get_balance(): void
    {
        $this->balanceService->deposit(
            $this->wallet,
            '10',
            '0xdeposit123',
            'sender'
        );

        $this->balanceService->withdraw(
            $this->wallet,
            '2',
            'recipient'
        );

        $balance = $this->balanceService->getBalance($this->wallet);

        $this->assertEquals('10', $balance['total']);
        $this->assertGreaterThan('0', $balance['locked']);
        $this->assertLessThan('10', $balance['available']);
    }

    public function test_lock_and_unlock_funds(): void
    {
        $this->balanceService->deposit(
            $this->wallet,
            '10',
            '0xdeposit123',
            'sender'
        );

        $this->balanceService->lockFunds($this->wallet, '3');

        $this->wallet->refresh();
        $this->assertEquals('3', $this->wallet->locked_balance);

        $this->balanceService->unlockFunds($this->wallet, '2');

        $this->wallet->refresh();
        $this->assertEquals('1', $this->wallet->locked_balance);
    }

    public function test_unlock_insufficient_locked_balance(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient locked balance');

        $this->balanceService->unlockFunds($this->wallet, '100');
    }
}
