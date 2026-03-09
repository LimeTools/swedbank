<?php

namespace LimeTools\Swedbank;

use Illuminate\Support\ServiceProvider;

class SwedbankPaymentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/swedbank.php',
            'swedbank'
        );

        $this->mergeConfigFrom(
            __DIR__ . '/../config/everypay.php',
            'everypay'
        );

        $this->app->singleton(SwedbankPaymentApi::class, function ($app) {
            $isSandbox = config('swedbank.environment') === 'sandbox';
            return new SwedbankPaymentApi($isSandbox);
        });

        // Register alias for facade
        $this->app->alias(SwedbankPaymentApi::class, 'swedbank.payment');

        // EveryPay API binding
        $this->app->singleton(EveryPayApi::class, function ($app) {
            return new EveryPayApi();
        });

        $this->app->alias(EveryPayApi::class, 'everypay.api');
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/swedbank.php' => config_path('swedbank.php'),
            __DIR__ . '/../config/everypay.php' => config_path('everypay.php'),
        ], 'swedbank-config');
    }
}

