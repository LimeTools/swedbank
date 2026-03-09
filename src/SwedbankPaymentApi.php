<?php

namespace LimeTools\Swedbank;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class SwedbankPaymentApi
{
    protected bool $isSandbox;

    public function __construct(bool $isSandbox = null)
    {
        $this->isSandbox = $isSandbox ?? (Config::get('swedbank.environment') === 'sandbox');
    }

    /**
     * Get the appropriate base URL based on environment
     */
    protected function getBaseUrl(): string
    {
        $environment = $this->isSandbox ? 'sandbox' : 'production';
        return Config::get("swedbank.endpoints.{$environment}.base_url");
    }

    /**
     * Generate JWS (JSON Web Signature) for authentication
     * 
     * @param array|string $payload The request payload
     * @param string $url The full URL for the request
     * @param string $clientId The client ID
     * @param string $privateKey The private key for signing
     * @return string The JWS signature in detached format
     */
    protected function generateJWS(array|string $payload, string $url, string $clientId, string $privateKey): string
    {
        // Create JWT header
        $header = [
            'b64' => false,
            'crit' => ['b64'],
            'iat' => time(),
            'alg' => 'RS512',
            'url' => $url,
            'kid' => 'LT:' . $clientId
        ];

        // Encode header
        $headerEncoded = $this->base64UrlEncode(json_encode($header));

        // Prepare payload
        if (!empty($payload) && is_array($payload)) {
            $payload = json_encode($payload);
        }

        // For detached JWS, sign the header + payload
        $signature = '';
        $privateKey = trim($privateKey);
        $privateKeyResource = openssl_pkey_get_private($privateKey);

        if (!$privateKeyResource) {
            throw new \Exception('Invalid private key provided');
        }

        $dataToSign = $headerEncoded . '.' . $payload;

        if (!openssl_sign($dataToSign, $signature, $privateKeyResource, OPENSSL_ALGO_SHA512)) {
            throw new \Exception('Failed to sign JWS');
        }

        $signatureEncoded = $this->base64UrlEncode($signature);

        // Return detached JWS format: <header>..<signature>
        return $headerEncoded . '..' . $signatureEncoded;
    }

    /**
     * Base64 URL encoding (RFC 4648)
     */
    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Build request body for Swedbank PaymentCreationRequestV3 (flat schema).
     * Accepts either the API format (amount, currency, description, redirectUrl, notificationUrl, locale)
     * or a legacy format (amount.value, amount.currency, creditor.bic, remittanceInformationUnstructured, redirectUri, etc.).
     *
     * @param array $paymentData
     * @param string $bic BIC for the provider (e.g. HABALT22)
     * @return array Normalized payload for POST body
     */
    protected function buildPaymentCreationBody(array $paymentData, string $bic): array
    {
        // Already in API format (flat amount, currency, redirectUrl, notificationUrl, locale)
        if (isset($paymentData['redirectUrl']) && isset($paymentData['notificationUrl']) && isset($paymentData['locale'])) {
            return array_filter([
                'amount' => (string) ($paymentData['amount'] ?? '0'),
                'currency' => $paymentData['currency'] ?? 'EUR',
                'description' => $paymentData['description'] ?? null,
                'reference' => $paymentData['reference'] ?? null,
                'redirectUrl' => $paymentData['redirectUrl'],
                'notificationUrl' => $paymentData['notificationUrl'],
                'locale' => $paymentData['locale'],
            ], fn ($v) => $v !== null && $v !== '');
        }

        // Legacy format: amount as array, creditor.bic, redirectUri, remittanceInformationUnstructured
        $amount = $paymentData['amount'] ?? [];
        $amountValue = is_array($amount) ? ($amount['value'] ?? '0') : (string) $amount;
        $currency = is_array($amount) ? ($amount['currency'] ?? 'EUR') : ($paymentData['currency'] ?? 'EUR');

        $redirectUrl = $paymentData['redirectUri'] ?? $paymentData['redirectUrl'] ?? '';
        if ($redirectUrl === '') {
            throw new \InvalidArgumentException(
                'Payment data must include redirectUrl or redirectUri.'
            );
        }
        // API requires notificationUrl; default to redirectUrl if not provided (use a dedicated webhook URL in production)
        $notificationUrl = $paymentData['notificationUrl'] ?? $paymentData['notificationUri'] ?? $redirectUrl;

        $body = [
            'amount' => (string) $amountValue,
            'currency' => $currency,
            'description' => $paymentData['remittanceInformationUnstructured'] ?? $paymentData['description'] ?? '',
            'redirectUrl' => $redirectUrl,
            'notificationUrl' => $notificationUrl,
            'locale' => $paymentData['locale'] ?? 'en',
        ];

        if (!empty($paymentData['reference'])) {
            $body['reference'] = $paymentData['reference'];
        }

        return $body;
    }

    /**
     * Create payment initiation (V3 API)
     *
     * Request body must match PaymentCreationRequestV3: amount (string), currency, description or reference,
     * redirectUrl, notificationUrl, locale. JWS is computed over the exact JSON body.
     *
     * @param array $paymentData Payment data (see README for legacy and API format)
     * @param string $clientId Client ID
     * @param string $privateKey Private key for signing
     * @return string Redirect URL for user to complete payment
     * @throws \Exception
     */
    public function createPaymentInitiation(array $paymentData, string $clientId, string $privateKey): string
    {
        $bic = $paymentData['creditor']['bic'] ?? $paymentData['bic'] ?? '';
        if ($bic === '') {
            throw new \InvalidArgumentException('Payment data must include creditor.bic or bic');
        }

        $url = rtrim($this->getBaseUrl(), '/') . '/v3/transactions/providers/' . $bic;
        $body = $this->buildPaymentCreationBody($paymentData, $bic);

        // Use exact JSON string for both signing and body so JWS validates (RFC: body "exactly as it is")
        $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES);
        $jws = $this->generateJWS($bodyJson, $url, $clientId, $privateKey);

        $response = Http::withHeaders([
            'x-jws-signature' => $jws,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',

            'X-Request-ID' => uniqid('req_', true),
        ])->withBody($bodyJson, 'application/json')->post($url);

        if (!$response->successful()) {
            $this->logError('Swedbank payment initiation failed', [
                'response' => $response->body(),
                'status' => $response->status(),
                'payment_data' => $paymentData,
                'url' => $url,
            ]);
            throw new \Exception('Failed to create payment initiation: ' . $response->body());
        }

        $paymentResponse = $response->json();

        // V3 response: urls.redirect and urls.status
        return $paymentResponse['urls']['redirect'] ?? $paymentResponse['_links']['scaRedirect']['href'] ?? '';
    }

    /**
     * Get payment status (V3 API)
     * 
     * @param string $statusUrl The status URL returned from payment initiation
     * @param string $clientId Client ID
     * @param string $privateKey Private key for signing
     * @return array Payment status response
     * @throws \Exception
     */
    public function getPaymentStatus(string $statusUrl, string $clientId, string $privateKey): array
    {
        $jws = $this->generateJWS('', $statusUrl, $clientId, $privateKey);

        $response = Http::withHeaders([
            'x-jws-signature' => $jws,
            'Accept' => 'application/json',
            'X-Request-ID' => uniqid('req_', true)
        ])->get($statusUrl);

        if (!$response->successful()) {
            $this->logError('Swedbank payment status request failed', [
                'url' => $statusUrl,
                'response' => $response->body(),
                'status' => $response->status(),
            ]);
            throw new \Exception('Failed to get payment status: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Get payment providers list (V3 API)
     * 
     * @param string $country Country code (e.g., 'LT', 'LV', 'EE')
     * @param string $clientId Client ID
     * @param string $privateKey Private key for signing
     * @return array List of payment providers
     * @throws \Exception
     */
    public function getPaymentProviders(string $country, string $clientId, string $privateKey): array
    {
        $url = rtrim($this->getBaseUrl(), '/') . '/v3/agreement/providers';

        $jws = $this->generateJWS('', $url, $clientId, $privateKey);

        $response = Http::withHeaders([
            'x-jws-signature' => $jws,
            'Accept' => 'application/json',
        ])->get($url);

        if (!$response->successful()) {
            $this->logError('Swedbank providers request failed', [
                'country' => $country,
                'response' => $response->body(),
                'status' => $response->status(),
                'url' => $url,
            ]);
            throw new \Exception('Failed to get payment providers: ' . $response->body());
        }

        $providers = $response->json();

        // Format providers response
        $formattedProviders = collect($providers ?? [])->map(function ($provider, $key) {
            return [
                'id' => $key,
                'name' => $provider['names']['shortNames']['lt'] ?? $provider['names']['shortNames']['en'] ?? '',
                'country' => $provider['country'] ?? '',
                'bic' => $provider['bic'] ?? '',
                'logo' => $provider['urls']['logo'] ?? '',
                'url' => $provider['urls']['payment'] ?? '',
            ];
        })->toArray();

        return $formattedProviders;
    }

    /**
     * Get payment initiation form data for specific provider (V3 API)
     * 
     * @param string $bic Bank Identifier Code
     * @param array $paymentData Payment data according to Swedbank V3 API specification
     * @param string $clientId Client ID
     * @param string $privateKey Private key for signing
     * @return array Payment initiation form response
     * @throws \Exception
     */
    public function getPaymentInitiationForm(string $bic, array $paymentData, string $clientId, string $privateKey): array
    {
        $url = rtrim($this->getBaseUrl(), '/') . '/v3/transactions/providers/' . $bic;
        $body = $this->buildPaymentCreationBody(array_merge($paymentData, ['bic' => $bic]), $bic);
        $bodyJson = json_encode($body, JSON_UNESCAPED_SLASHES);
        $jws = $this->generateJWS($bodyJson, $url, $clientId, $privateKey);

        $response = Http::withHeaders([
            'x-jws-signature' => $jws,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Request-ID' => uniqid('req_', true),
        ])->withBody($bodyJson, 'application/json')->post($url);

        if (!$response->successful()) {
            $this->logError('Swedbank payment initiation form failed', [
                'bic' => $bic,
                'response' => $response->body(),
                'status' => $response->status()
            ]);
            throw new \Exception('Failed to get payment initiation form: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Log error if logging is enabled
     */
    protected function logError(string $message, array $context = []): void
    {
        if (Config::get('swedbank.logging.enabled', true)) {
            Log::channel(Config::get('swedbank.logging.channel', 'swedbank'))
                ->error($message, $context);
        }
    }

    /**
     * Log info if logging is enabled
     */
    protected function logInfo(string $message, array $context = []): void
    {
        if (Config::get('swedbank.logging.enabled', true)) {
            Log::channel(Config::get('swedbank.logging.channel', 'swedbank'))
                ->info($message, $context);
        }
    }
}

