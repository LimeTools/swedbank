<?php

/**
 * EveryPay Payment Gateway Configuration
 *
 * Separate configuration for EveryPay integration.
 *
 * @phpstan-ignore-file
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | Supported values:
    | - "demo"       -> https://igw-demo.every-pay.com/api
    | - "production" -> https://pay.every-pay.eu/api
    |
    */

    // @phpstan-ignore-next-line
    'environment' => env('EVERYPAY_ENVIRONMENT', 'demo'),

    /*
    |--------------------------------------------------------------------------
    | Demo (sandbox) environment
    |--------------------------------------------------------------------------
    */
    'demo' => [
        // @phpstan-ignore-next-line
        'api_username' => env('EVERYPAY_DEMO_API_USERNAME'),
        // @phpstan-ignore-next-line
        'api_secret' => env('EVERYPAY_DEMO_API_SECRET'),
        // @phpstan-ignore-next-line
        'account_name' => env('EVERYPAY_DEMO_ACCOUNT_NAME'),
        'base_url' => 'https://igw-demo.every-pay.com/api',
    ],

    /*
    |--------------------------------------------------------------------------
    | Production environment
    |--------------------------------------------------------------------------
    */
    'production' => [
        // @phpstan-ignore-next-line
        'api_username' => env('EVERYPAY_PRODUCTION_API_USERNAME'),
        // @phpstan-ignore-next-line
        'api_secret' => env('EVERYPAY_PRODUCTION_API_SECRET'),
        // @phpstan-ignore-next-line
        'account_name' => env('EVERYPAY_PRODUCTION_ACCOUNT_NAME'),
        'base_url' => 'https://pay.every-pay.eu/api',
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | Optional default values applied if they are not provided explicitly
    | in the payment payload.
    |
    */
    'defaults' => [
        'locale' => 'en',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        // @phpstan-ignore-next-line
        'enabled' => env('EVERYPAY_LOGGING_ENABLED', true),
        // @phpstan-ignore-next-line
        'level' => env('EVERYPAY_LOG_LEVEL', 'info'),
        // @phpstan-ignore-next-line
        'channel' => env('EVERYPAY_LOG_CHANNEL', 'swedbank'),
    ],
];

