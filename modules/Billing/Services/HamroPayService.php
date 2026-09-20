<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\Http;

/**
 * HamroPay checkout client (session + transaction status).
 * Credentials: prefer PaymentGatewayAccount (company/branch), else config/hamropay.php / env.
 */
class HamroPayService
{
    private string $apiBaseUrl;
    private string $gatewayUrl;
    private string $clientId;
    private string $clientApiKey;
    private string $secret;
    private string $merchantId;
    private bool $verifySsl;

    public function __construct()
    {
        $this->apiBaseUrl   = rtrim((string) (config('hamropay.api_base_url') ?? ''), '/');
        $this->gatewayUrl   = (string) (config('hamropay.gateway_url') ?? '');
        $this->clientId     = (string) (config('hamropay.client_id') ?? '');
        $this->clientApiKey = (string) (config('hamropay.client_api_key') ?? '');
        $this->secret       = (string) (config('hamropay.secret') ?? '');
        $this->merchantId   = (string) (config('hamropay.merchant_id') ?? '');
        $this->verifySsl    = (bool) config('hamropay.verify_ssl', true);
    }

    public function forMerchant(array $credentials): self
    {
        $instance = clone $this;
        if (! empty($credentials['merchant_id'])) {
            $instance->merchantId = (string) $credentials['merchant_id'];
        }
        if (! empty($credentials['client_id'])) {
            $instance->clientId = (string) $credentials['client_id'];
        }
        if (! empty($credentials['api_key'])) {
            $instance->clientApiKey = (string) $credentials['api_key'];
        }
        if (! empty($credentials['secret_key'])) {
            $instance->secret = (string) $credentials['secret_key'];
        }

        return $instance;
    }

    public function withEndpoints(?string $apiBaseUrl, ?string $gatewayUrl = null, ?bool $verifySsl = null): self
    {
        $instance = clone $this;
        if (filled($apiBaseUrl)) {
            $instance->apiBaseUrl = rtrim($apiBaseUrl, '/');
        }
        if (filled($gatewayUrl)) {
            $instance->gatewayUrl = $gatewayUrl;
        }
        if ($verifySsl !== null) {
            $instance->verifySsl = $verifySsl;
        }

        return $instance;
    }

    public function sign(string $message): string
    {
        return base64_encode(hash_hmac('sha512', $message, $this->secret, true));
    }

    private function headers(string $signature): array
    {
        return [
            'Client-Id' => $this->clientId,
            'Client-API-Key' => $this->clientApiKey,
            'Signature' => $signature,
            'Content-Type' => 'application/json',
        ];
    }

    public function createSession(array $data, ?string $merchantId = null, ?string $subMerchantId = null): array
    {
        if ($this->apiBaseUrl === '' || $this->clientId === '') {
            return ['message' => 'HamroPay is not configured.', 'http_status' => 503];
        }

        $merchantId = $merchantId ?: $this->merchantId;
        $sig = $this->sign(implode(',', [
            $data['merchantTxnId'],
            $data['transactionAmount'],
            $merchantId,
            $this->clientId,
            $this->clientApiKey,
        ]));

        $payload = array_merge($data, ['merchantId' => $merchantId]);
        if ($subMerchantId && $subMerchantId !== $merchantId) {
            $payload['subMerchantId'] = $subMerchantId;
        }

        $request = Http::withHeaders($this->headers($sig));
        if (! $this->verifySsl) {
            $request = $request->withoutVerifying();
        }

        $response = $request->withOptions(['http_errors' => false])
            ->post("{$this->apiBaseUrl}/v1/checkout/sessionId", $payload);

        return $response->json() ?? [
            'message' => 'HamroPay API error',
            'http_status' => $response->status(),
            'body' => $response->body() ?: '(empty)',
        ];
    }

    public function buildCheckoutParams(
        string $sessionId,
        string $merchantTxnId,
        int $transactionAmount,
        string $remarks = '',
        ?string $merchantId = null,
        ?string $successUrl = null,
        ?string $failureUrl = null,
        ?string $subMerchantId = null
    ): array {
        $merchantId = $merchantId ?: $this->merchantId;

        $token = $this->sign(implode(',', [
            $merchantId,
            $merchantTxnId,
            $sessionId,
            $transactionAmount,
            $this->clientId,
            $this->clientApiKey,
        ]));

        $params = [
            'merchant_id' => $merchantId,
            'session_id' => $sessionId,
            'token' => $token,
            'merchant_transaction_id' => $merchantTxnId,
            'remarks' => $remarks,
            'success_url' => $successUrl ?: (string) config('hamropay.marketplace_success_url'),
            'failure_url' => $failureUrl ?: (string) config('hamropay.marketplace_failure_url'),
        ];

        if ($subMerchantId && $subMerchantId !== $merchantId) {
            $params['sub_merchant_id'] = $subMerchantId;
        }

        return $params;
    }

    public function getTransaction(string $merchantTxnId, ?string $merchantId = null): array
    {
        if ($this->apiBaseUrl === '') {
            return ['message' => 'HamroPay is not configured.'];
        }

        $merchantId = $merchantId ?: $this->merchantId;
        $sig = $this->sign(implode(',', [
            $merchantTxnId,
            $merchantId,
            $this->clientId,
            $this->clientApiKey,
        ]));

        $request = Http::withHeaders($this->headers($sig));
        if (! $this->verifySsl) {
            $request = $request->withoutVerifying();
        }

        $response = $request->post("{$this->apiBaseUrl}/v1/checkout/transaction", [
            'merchantId' => $merchantId,
            'merchantTxnId' => $merchantTxnId,
        ]);

        return $response->json() ?? ['message' => 'Empty response from HamroPay API'];
    }

    public function getGatewayUrl(): string
    {
        return $this->gatewayUrl !== '' ? $this->gatewayUrl : (string) config('hamropay.gateway_url', '');
    }

    public function platformMerchantId(): string
    {
        return $this->merchantId;
    }
}
