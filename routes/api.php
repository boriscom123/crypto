<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CryptoBalanceController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    // Балансы
    Route::get('/balances', [CryptoBalanceController::class, 'balances']);
    Route::get('/wallet/{walletId}/balance', [CryptoBalanceController::class, 'walletBalance']);
    Route::post('/wallet', [CryptoBalanceController::class, 'createWallet']);

    // Транзакции
    Route::get('/transactions', [CryptoBalanceController::class, 'transactions']);
    Route::get('/transactions/{transactionId}', [CryptoBalanceController::class, 'transaction']);

    // Депозит
    Route::post('/deposit', [CryptoBalanceController::class, 'deposit']);

    // Вывод
    Route::post('/withdraw', [CryptoBalanceController::class, 'withdraw']);
    Route::post('/withdraw/{transactionId}/confirm', [CryptoBalanceController::class, 'confirmWithdraw']);
    Route::post('/withdraw/{transactionId}/cancel', [CryptoBalanceController::class, 'cancelWithdraw']);

    // Платежи
    Route::post('/payment', [CryptoBalanceController::class, 'payment']);

    // Риски
    Route::get('/risk/stats', [CryptoBalanceController::class, 'riskStats']);
    Route::get('/risk/history', [CryptoBalanceController::class, 'riskHistory']);
});
