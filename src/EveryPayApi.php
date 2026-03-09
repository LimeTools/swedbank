<?php

namespace LimeTools\Swedbank;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EveryPayApi
{
    protected string $environment;

    public function __construct(?string $environment = null)
    {
        $this->environment = $environment ?? Config::get('everypay.environment', 'demo');
    }

    protected function getBaseUrl(): string
    {
        $env = $this->environment === 'production' ? 'production' : 'demo';

        return rtrim((string) Config::get("everypay.{$env}.base_url"), '/');
    }

    protected function getCredentials(): array
    {
        $env = $this->environment === 'production' ? 'production' : 'demo';

        $apiUsername = (string) Config::get("everypay.{$env}.api_username");
        $apiSecret = (string) Config::get("everypay.{$env}.api_secret");

        if ($apiUsername === '' || $apiSecret === '') {
            throw new \RuntimeException('EveryPay API credentials are not configured.');
        }

        return [$apiUsername, $apiSecret];
    }

    protected function getDefaultAccountName(): ?string
    {
        $env = $this->environment === 'production' ? 'production' : 'demo';

        return Config::get("everypay.{$env}.account_name");
    }

    /**
     * Create a one-off payment (v4 API).
     *
     * This wraps POST /v4/payments/oneoff as described in the EveryPay
     * documentation and OpenAPI spec.
     *
     * @param array $payload Request payload; required keys are the same as the API:
     *                       account_name, amount, api_username, customer_url,
     *                       nonce, order_reference, timestamp.
     *                       If some of these are omitted, sensible defaults are
     *                       filled from configuration where possible.
     *
     * @return array Decoded JSON response from EveryPay.
     *
     * @throws \Throwable
     */
    public function createOneOffPayment(array $payload): array
    {
        [$apiUsername, $apiSecret] = $this->getCredentials();
        $baseUrl = $this->getBaseUrl();

        $body = $payload;

        // Fill in sensible defaults from config if not given explicitly
        $body['api_username'] ??= $apiUsername;
        $body['account_name'] ??= $this->getDefaultAccountName();
        $body['nonce'] ??= bin2hex(random_bytes(8));
        $body['timestamp'] ??= gmdate('Y-m-d\TH:i:s\Z');
        $body['locale'] ??= Config::get('everypay.defaults.locale', 'en');

        $url = $baseUrl . '/v4/payments/oneoff';

        $response = Http::withBasicAuth($apiUsername, $apiSecret)
            ->asJson()
            ->withHeaders([
                'Accept' => 'application/json',
            ])
            ->post($url, $body);

        if (! $response->successful()) {
            $this->logError('EveryPay one-off payment creation failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $body,
            ]);

            throw new \RuntimeException('Failed to create EveryPay payment: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Get payment status by payment_reference.
     *
     * Wraps GET /v4/payments/{payment_reference}.
     *
     * @param string $paymentReference
     * @return array
     */
    public function getPaymentStatus(string $paymentReference): array
    {
        [$apiUsername, $apiSecret] = $this->getCredentials();
        $baseUrl = $this->getBaseUrl();

        $url = $baseUrl . '/v4/payments/' . urlencode($paymentReference);

        $response = Http::withBasicAuth($apiUsername, $apiSecret)
            ->acceptJson()
            ->get($url, [
                'api_username' => $apiUsername,
            ]);

        if (! $response->successful()) {
            $this->logError('EveryPay payment status request failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('Failed to get EveryPay payment status: ' . $response->body());
        }

        return $response->json();
    }

    protected function logError(string $message, array $context = []): void
    {
        if (Config::get('everypay.logging.enabled', true)) {
            Log::channel(Config::get('everypay.logging.channel', 'swedbank'))
                ->error($message, $context);
        }
    }

    protected function logInfo(string $message, array $context = []): void
    {
        if (Config::get('everypay.logging.enabled', true)) {
            Log::channel(Config::get('everypay.logging.channel', 'swedbank'))
                ->info($message, $context);
        }
    }
}

