<?php

namespace Modules\Merchant\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Merchant\Models\Merchant;
use RuntimeException;
use Throwable;

class SendStoreIntegrationApprovalCallback implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public array $backoff = [
        60,
        300,
        900,
        1800,
    ];

    public function __construct(
        public int $merchantId
    ) {
    }

    public function handle(): void
    {
        $merchant = Merchant::query()
            ->with([
                'apiKeys',
                'pickupLocations',
            ])
            ->findOrFail($this->merchantId);

        if (
            $merchant->application_source !==
            Merchant::SOURCE_STORE_MANAGER
        ) {
            return;
        }

        if (
            $merchant->integration_callback_status ===
            'delivered'
        ) {
            return;
        }

        $apiKey = $merchant->apiKeys
            ->first(
                fn ($key) =>
                    $key->is_active ||
                    $key->status === 'active'
            );

        if (!$apiKey) {
            throw new RuntimeException(
                'Active merchant API key not found.'
            );
        }

        if (!$apiKey->api_secret_encrypted) {
            throw new RuntimeException(
                'Encrypted merchant API secret is missing.'
            );
        }

        $plainSecret = Crypt::decryptString(
            $apiKey->api_secret_encrypted
        );

        $eventId =
            'evt_' .
            Str::lower(
                Str::random(32)
            );

        $timestamp = (string) now()->timestamp;

        $pickupLocation = $merchant->pickupLocations
            ->firstWhere('is_default', true);

        $payload = [
            'event' => 'merchant.integration.approved',

            'event_id' => $eventId,

            'application_number' =>
                $merchant->application_number,

            'merchant_reference' =>
                $merchant->code,

            'status' => 'approved',

            'api_key' =>
                $apiKey->api_key,

            'api_secret' =>
                $plainSecret,

            'environment' =>
                $apiKey->environment ?: 'live',

            'approved_services' =>
                $merchant->approved_services ?? [],

            'pickup_location' =>
                $pickupLocation
                    ? [
                        'id' =>
                            $pickupLocation->id,

                        'name' =>
                            $pickupLocation->name,

                        'address' =>
                            $pickupLocation->address,

                        'city' =>
                            $pickupLocation->city,

                        'area' =>
                            $pickupLocation->area,
                    ]
                    : null,

            'approved_at' =>
                $merchant
                    ->integration_approved_at
                    ?->toIso8601String(),
        ];

        $rawBody = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE |
            JSON_THROW_ON_ERROR
        );

        $callbackSecret =
            (string)
            $merchant->integration_callback_secret;

        if ($callbackSecret === '') {
            throw new RuntimeException(
                'Integration callback secret is missing.'
            );
        }

        $signature = hash_hmac(
            'sha256',
            $timestamp . '.' . $rawBody,
            $callbackSecret
        );

        try {
            $callbackUrl =
                trim(
                    (string)
                    $merchant->integration_callback_url
                );

            if ($callbackUrl === '') {
                throw new RuntimeException(
                    'Integration callback URL is missing.'
                );
            }

            $response = Http::withHeaders([
                'Accept' =>
                    'application/json',

                'Content-Type' =>
                    'application/json',

                'X-Tukaatu-Event-ID' =>
                    $eventId,

                'X-Tukaatu-Timestamp' =>
                    $timestamp,

                'X-Tukaatu-Signature' =>
                    $signature,
            ])
                ->withBody(
                    $rawBody,
                    'application/json'
                )
                ->timeout(20)
                ->post($callbackUrl);

            if (!$response->successful()) {
                throw new RuntimeException(
                    $this->buildCallbackError(
                        $response,
                        $callbackUrl
                    )
                );
            }

            $merchant->forceFill([
                'integration_callback_status' =>
                    'delivered',

                'integration_callback_sent_at' =>
                    now(),

                'integration_callback_error' =>
                    null,
            ])->save();
        } catch (Throwable $exception) {
            $merchant->forceFill([
                'integration_callback_status' =>
                    'failed',

                'integration_callback_error' =>
                    Str::limit(
                        $exception->getMessage(),
                        4000
                    ),
            ])->save();

            throw $exception;
        }
    }

    /**
     * Build a useful callback error containing:
     *
     * - HTTP status
     * - HTTP reason
     * - response message
     * - response error
     * - response errors
     * - response body
     * - callback URL
     */
    private function buildCallbackError(
        $response,
        string $callbackUrl
    ): string {
        $status =
            $response->status();

        $reason =
            $response->reason();

        $body =
            trim($response->body());

        $json =
            $response->json();

        $parts = [
            'Store callback failed',
            'HTTP ' . $status,
        ];

        if ($reason) {
            $parts[] =
                'Reason: ' . $reason;
        }

        if (is_array($json)) {
            if (
                isset($json['message']) &&
                is_scalar($json['message'])
            ) {
                $parts[] =
                    'Message: ' .
                    $json['message'];
            }

            if (
                isset($json['error']) &&
                is_scalar($json['error'])
            ) {
                $parts[] =
                    'Error: ' .
                    $json['error'];
            }

            if (
                isset($json['errors'])
            ) {
                $errors =
                    json_encode(
                        $json['errors'],
                        JSON_UNESCAPED_SLASHES |
                        JSON_UNESCAPED_UNICODE
                    );

                $parts[] =
                    'Errors: ' . $errors;
            }
        }

        if ($body !== '') {
            $parts[] =
                'Response: ' .
                Str::limit(
                    $body,
                    2500
                );
        }

        $parts[] =
            'URL: ' . $callbackUrl;

        return implode(
            ' | ',
            $parts
        );
    }
}