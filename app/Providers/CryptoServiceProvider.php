<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\CryptoBalanceService;
use App\Services\RiskAssessmentService;

class CryptoServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(RiskAssessmentService::class, function ($app) {
            return new RiskAssessmentService();
        });

        $this->app->singleton(CryptoBalanceService::class, function ($app) {
            return new CryptoBalanceService(
                $app->make(RiskAssessmentService::class)
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/crypto.php' => config_path('crypto.php'),
        ], 'crypto-config');
    }
}
