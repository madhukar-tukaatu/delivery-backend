<?php

declare(strict_types=1);

namespace Modules\POD\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\POD\Events\PodPaymentUpdated;
use Modules\POD\Models\PodPaymentSession;
use Modules\Setting\Services\MarketplaceApiKeyIssuer;
use Modules\Setting\Services\MarketplacePaymentUrlResolver;
use Throwable;

/**
 * Async: ask the store's marketplace to create the doorstep POD QR.
 *
 * Host: marketplaces.api_base_url.
 * Headers X-Tukaatu-Key / X-Tukaatu-Secret: the issued marketplace_api_keys
 * row (key_encrypted + secret_encrypted), not marketplaces.api_key/api_secret
 * and not .env. Path: /api/v1/gateway/payments/pod-qr unless
 * marketplaces.meta.pod_payment_request_path is a non-legacy override.
 *
 * Body: merchant_order_id, external_order_id, tracking_number, amount,
 * session_id, payment_session_id, callback_url.
 */
class RequestTukaatuPodPaymentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public array $backoff = [15, 60, 180, 600];

    public function __construct(public int $sessionId)
    {
    }

    public function handle(): void
    {
        $session = PodPaymentSession::query()
            ->with(['merchant', 'shipment'])
            ->find($this->sessionId);

        if (! $session) {
            return;
        }

        if (in_array($session->status, ['paid', 'failed', 'cancelled', 'expired', 'refunded'], true)) {
            return;
        }

        // If QR already present (ready callback arrived first), skip outbound.
        if (filled($session->qr_payload) || filled($session->checkout_url)) {
            return;
        }

        $target = $this->resolveTarget($session);
        if ($target['error'] !== null) {
            $session->update([
                'status' => 'failed',
                'failed_at' => now(),
                'last_error' => $target['error'],
                'response_payload' => array_merge((array) $session->response_payload, [
                    'outbound' => $this->outboundInfo($target, null, $target['error']),
                ]),
            ]);
            event(new PodPaymentUpdated($session->fresh()));
            Log::warning('RequestTukaatuPodPaymentJob: marketplace POD target missing', [
                'session_id' => $session->payment_session_id,
                'marketplace_id' => $target['marketplace_id'],
                'marketplace_code' => $target['marketplace_code'],
                'host' => $target['host'],
                'key_prefix' => $target['key_prefix'] ?? '',
                'credential_source' => $target['credential_source'] ?? '',
            ]);

            return;
        }

        $url = $target['url'];
        $apiKey = $target['api_key'];
        $apiSecret = $target['api_secret'];

        // Which marketplace API this store's POD request goes to
        // (FCA stores -> api.fca.com.np, Tukaatu stores -> api.tukaatu.com).
        // Never log header values.
        Log::info('POD pod-qr request', [
            'session_id' => $session->payment_session_id,
            'delivery_assignment_id' => $session->delivery_assignment_id ?? null,
            'shipment_id' => $session->shipment_id,
            'merchant_id' => $session->merchant_id,
            'merchant_name' => $session->merchant?->name,
            'external_store_id' => $session->external_store_id ?: $session->merchant?->external_store_id,
            'marketplace_id' => $target['marketplace_id'],
            'marketplace_slug' => $target['marketplace_code'],
            'marketplace_name' => $target['marketplace_name'],
            'url' => $url,
            'has_auth_headers' => $apiKey !== '' || $apiSecret !== '',
        ]);

        $shipment = $session->shipment;
        $amount = round((float) $session->amount, 2);

        $body = [
            'external_store_id' => (string) ($session->external_store_id ?: $session->merchant?->external_store_id ?: ''),
            'merchant_order_id' => (string) ($session->merchant_order_id ?: $shipment?->merchant_order_id ?: ''),
            'external_order_id' => (string) (
                $shipment?->external_order_id
                ?? $session->merchant_order_id
                ?? $shipment?->merchant_order_id
                ?? ''
            ),
            'tracking_number' => (string) ($session->tracking_number ?: $shipment?->tracking_number ?: ''),
            'amount' => $amount,
            // Correlation helpers (Tukaatu may ignore unknown fields):
            'session_id' => $session->payment_session_id,
            'payment_session_id' => $session->payment_session_id,
            'callback_url' => rtrim((string) config('app.url'), '/') . '/api/v1/express/callback',
        ];

        $timeout = (int) config('services.tukaatu.timeout', 20);
        $verifySsl = (bool) config('services.tukaatu.verify_ssl', true);

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        if ($apiKey !== '') {
            $headers['X-Tukaatu-Key'] = $apiKey;
        }
        if ($apiSecret !== '') {
            $headers['X-Tukaatu-Secret'] = $apiSecret;
        }

        try {
            $request = Http::withHeaders($headers)->timeout($timeout);

            if (! $verifySsl) {
                $request = $request->withoutVerifying();
            }

            $response = $request->post($url, $body);
        } catch (Throwable $e) {
            Log::warning('POD pod-qr response', [
                'session_id' => $session->payment_session_id,
                'marketplace_name' => $target['marketplace_name'],
                'url' => $url,
                'http_status' => null,
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
                'has_payment_url' => false,
            ]);
            $session->update([
                'status' => 'failed',
                'failed_at' => now(),
                'last_error' => 'Marketplace payment API is unreachable',
                'response_payload' => array_merge((array) $session->response_payload, [
                    'outbound' => $this->outboundInfo($target, null, 'Marketplace payment API is unreachable'),
                    'tukaatu_request' => [
                        'url' => $url,
                        'body' => $body,
                        'http_status' => null,
                        'connection_error' => $e->getMessage(),
                        'at' => now()->toIso8601String(),
                    ],
                ]),
            ]);
            event(new PodPaymentUpdated($session->fresh()));

            return;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            $payload = ['raw' => $response->body()];
        }

        $status = (int) $response->status();
        $providerMessage = $this->extractProviderMessage($payload, $status);

        $session->update([
            'response_payload' => array_merge((array) $session->response_payload, [
                'outbound' => $this->outboundInfo($target, $status, $providerMessage),
                'tukaatu_request' => [
                    'url' => $url,
                    'body' => $body,
                    'http_status' => $status,
                    'response' => $payload,
                    'at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        $responsePaymentUrl = data_get($payload, 'data.payment_url') ?? data_get($payload, 'payment_url')
            ?? data_get($payload, 'data.checkout_url') ?? data_get($payload, 'checkout_url');
        $responseOk = $response->successful()
            && (! array_key_exists('success', $payload) || in_array($payload['success'], [true, 1, '1', 'true'], true));
        $responseLog = [
            'session_id' => $session->payment_session_id,
            'marketplace_name' => $target['marketplace_name'],
            'url' => $url,
            'http_status' => $status,
            'success' => $responseOk,
            'message' => $this->safeMessage($providerMessage),
            'has_payment_url' => is_string($responsePaymentUrl) && trim($responsePaymentUrl) !== '',
        ];
        $responseOk
            ? Log::info('POD pod-qr response', $responseLog)
            : Log::warning('POD pod-qr response', $responseLog);

        $reportedSuccess = $payload['success'] ?? null;
        $successFlag = ! array_key_exists('success', $payload)
            || $reportedSuccess === true
            || $reportedSuccess === 1
            || $reportedSuccess === '1'
            || $reportedSuccess === 'true';

        if (! $response->successful() || ! $successFlag) {
            $lastError = $this->mapProviderFailure($status, $providerMessage);

            Log::warning('RequestTukaatuPodPaymentJob: non-success response', [
                'session_id' => $session->payment_session_id,
                'status' => $status,
                'marketplace_id' => $target['marketplace_id'],
                'provider_message' => $providerMessage,
            ]);

            // 4xx and 5xx are terminal for this attempt so the rider stops polling.
            // An explicit GET ?refresh=1 (or a new session) retries.
            $session->update([
                'status' => 'failed',
                'failed_at' => now(),
                'last_error' => $lastError,
            ]);
            event(new PodPaymentUpdated($session->fresh()));

            return;
        }

        // Live pod-qr shape: success + data.payment_url is ready even when qr_string is null.
        $data = is_array(data_get($payload, 'data')) ? data_get($payload, 'data') : $payload;
        $qrRaw = data_get($data, 'qr_string');
        if ($qrRaw === null) {
            $qrRaw = data_get($data, 'qr_payload', data_get($data, 'payment.qr.payload'));
        }
        $qr = is_string($qrRaw) ? trim($qrRaw) : '';
        $qrImage = trim((string) (
            data_get($data, 'qr_image_url')
            ?? data_get($data, 'payment.qr.image_url')
            ?? ''
        ));
        $paymentUrl = trim((string) (
            data_get($data, 'payment_url')
            ?? data_get($data, 'checkout_url')
            ?? data_get($data, 'payment.checkout_url')
            ?? ''
        ));
        $merchantTxnId = trim((string) (
            data_get($data, 'merchant_txn_id')
            ?? data_get($data, 'merchantTxnId')
            ?? ''
        ));
        $params = data_get($data, 'params');
        $params = is_array($params) ? $params : null;
        $expiresRaw = data_get($data, 'expires_at');
        $amountRaw = data_get($data, 'amount');

        $stored = (array) $session->response_payload;
        $stored['provider_checkout'] = [
            'merchant_txn_id' => $merchantTxnId !== '' ? $merchantTxnId : null,
            'amount' => is_numeric($amountRaw) ? 0 + $amountRaw : $amountRaw,
            'qr_string' => $qr !== '' ? $qr : null,
            'payment_url' => $paymentUrl !== '' ? $paymentUrl : null,
            'params' => $params,
            'expires_at' => is_string($expiresRaw) && trim($expiresRaw) !== '' ? trim($expiresRaw) : null,
        ];

        $updates = [
            'last_error' => null,
            'response_payload' => $stored,
        ];
        if ($qr !== '') {
            $updates['qr_payload'] = $qr;
        }
        if ($qrImage !== '') {
            $updates['qr_image_url'] = $qrImage;
        }
        if ($paymentUrl !== '') {
            $updates['checkout_url'] = $paymentUrl;
        }
        if ($merchantTxnId !== '') {
            $updates['merchant_txn_id'] = $merchantTxnId;
        }
        if (is_string($expiresRaw) && trim($expiresRaw) !== '') {
            try {
                $updates['expires_at'] = \Illuminate\Support\Carbon::parse($expiresRaw);
            } catch (Throwable) {
                // Keep the raw value in provider_checkout.expires_at.
            }
        }

        $ready = $paymentUrl !== '' || $qr !== '';
        if ($ready) {
            $updates['status'] = $session->status === 'paid' ? 'paid' : 'ready';
        } else {
            $updates['status'] = 'failed';
            $updates['failed_at'] = now();
            $updates['last_error'] = 'Marketplace did not return a payment URL.';
        }

        $session->update($updates);
        event(new PodPaymentUpdated($session->fresh()));
    }

    /**
     * Outbound summary stored on the session (no headers, keys or secrets).
     *
     * @return array<string, mixed>
     */
    private function outboundInfo(array $target, ?int $httpStatus, ?string $message): array
    {
        return [
            'url' => $target['url'] !== '' ? $target['url'] : null,
            'marketplace_id' => $target['marketplace_id'],
            'marketplace_slug' => $target['marketplace_code'],
            'marketplace_name' => $target['marketplace_name'] ?? null,
            'http_status' => $httpStatus,
            'message' => $this->safeMessage($message),
            'requested_at' => now()->toIso8601String(),
        ];
    }

    private function safeMessage(?string $message): ?string
    {
        $message = is_string($message) ? trim($message) : '';
        if ($message === '') {
            return null;
        }

        if (preg_match('/api[_\-]?key|secret|password|token|authorization/i', $message)) {
            return 'Marketplace rejected the request (details hidden).';
        }

        return mb_substr($message, 0, 300);
    }

    /**
     * Short rider-facing last_error from marketplace HTTP failure (no secrets).
     */
    private function mapProviderFailure(int $status, ?string $providerMessage): string
    {
        if (in_array($status, [401, 403], true)) {
            return 'Marketplace rejected the API key';
        }

        $providerMessage = is_string($providerMessage) ? trim($providerMessage) : '';
        if ($providerMessage !== '') {
            if (preg_match('/api[_\-]?key|secret|password|token|authorization/i', $providerMessage)) {
                return 'Marketplace payment request failed (HTTP '.$status.')';
            }

            return mb_substr($providerMessage, 0, 240);
        }

        return 'Marketplace payment request failed (HTTP '.$status.')';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractProviderMessage(array $payload, int $status): ?string
    {
        foreach ([
            data_get($payload, 'message'),
            data_get($payload, 'error'),
            data_get($payload, 'errors.payment.0'),
            data_get($payload, 'errors.0'),
            data_get($payload, 'data.message'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
            if (is_array($candidate)) {
                foreach ($candidate as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        return trim($item);
                    }
                    if (is_array($item)) {
                        foreach ($item as $nested) {
                            if (is_string($nested) && trim($nested) !== '') {
                                return trim($nested);
                            }
                        }
                    }
                }
            }
        }

        $raw = data_get($payload, 'raw');
        if (is_string($raw) && trim($raw) !== '' && strlen(trim($raw)) < 240) {
            return trim($raw);
        }

        return null;
    }

    /**
     * @return array{url: string, host: string, api_key: string, api_secret: string, credential_source: string, key_prefix: string, marketplace_id: ?int, marketplace_code: ?string, error: ?string}
     */
    private function resolveTarget(PodPaymentSession $session): array
    {
        $session->loadMissing(['merchant', 'shipment']);
        $resolved = app(MarketplacePaymentUrlResolver::class)->resolve($session->shipment, $session->merchant);
        $marketplace = $resolved['marketplace'];

        $empty = [
            'url' => '',
            'host' => '',
            'api_key' => '',
            'api_secret' => '',
            'credential_source' => '',
            'key_prefix' => '',
            'marketplace_id' => $marketplace?->id,
            'marketplace_code' => $marketplace?->code,
            'marketplace_name' => $marketplace?->name,
            'error' => null,
        ];

        if (! $marketplace) {
            $store = $session->external_store_id ?: $session->merchant?->external_store_id ?: 'this store';
            $empty['error'] = 'No marketplace is linked to '.$store.', so POD cannot choose an API host. Attach the store on Admin -> Marketplaces.';

            return $empty;
        }

        $label = trim((string) $marketplace->name);
        if ($marketplace->code) {
            $label .= ' ('.$marketplace->code.')';
        }

        $url = $resolved['payment_request_url'];
        $host = $url !== '' ? (string) parse_url($url, PHP_URL_HOST) : '';
        $empty['url'] = $url;
        $empty['host'] = $host;

        if ($url === '' || $host === '') {
            $empty['error'] = 'Marketplace '.$label.' has no API base URL. Set API base URL on Admin -> Marketplaces for this marketplace.';

            return $empty;
        }

        // Same key Express issued to the marketplace (marketplace_api_keys).
        // Best effort: pod-qr does not require these headers, so a missing or
        // undecryptable key never blocks the call.
        $creds = app(MarketplaceApiKeyIssuer::class)->resolveOptionalOutboundHeaders((int) $marketplace->id);

        $empty['key_prefix'] = (string) $creds['key_prefix'];
        $empty['credential_source'] = 'marketplace_api_keys';
        $empty['api_key'] = (string) $creds['api_key'];
        $empty['api_secret'] = (string) $creds['api_secret'];

        if ($empty['api_key'] === '') {
            Log::warning('RequestTukaatuPodPaymentJob: marketplace key cannot be sent; posting without X-Tukaatu-Key', [
                'marketplace_id' => $marketplace->id,
                'key_prefix' => $empty['key_prefix'],
                'code' => $creds['code'],
            ]);
        }

        if (filled($session->last_error)) {
            $session->update(['last_error' => null]);
        }

        return $empty;
    }
}
