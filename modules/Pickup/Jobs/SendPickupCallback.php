<?php

declare(strict_types=1);

namespace Modules\Pickup\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Merchant\Models\Merchant;
use RuntimeException;
use Throwable;

/**
 * Sends a single pickup-lifecycle event to the store partner's
 * integration callback URL.
 *
 * Reuses the SAME destination + signing scheme as the merchant
 * onboarding callback (SendStoreIntegrationApprovalCallback):
 *
 * - URL:    merchant.integration_callback_url
 * - Secret: merchant.integration_callback_secret (encrypted at rest)
 * - Sign:   X-Tukaatu-Signature = HMAC-SHA256(timestamp . '.' . rawBody)
 *
 * The event type and payload are supplied by the caller
 * (PickupCallbackService), so this job is event-agnostic.
 */
class SendPickupCallback implements ShouldQueue
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

    /**
     * @param  array<string, mixed>  $payload  Fully-built event payload
     *                                          (must already contain event,
     *                                          event_id, occurred_at, etc.)
     */
    public function __construct(
        public int $merchantId,
        public array $payload,
    ) {
    }

    public function handle(): void
    {
        $merchant = Merchant::query()
            ->findOrFail($this->merchantId);

        $callbackUrl = trim(
            (string) $merchant->integration_callback_url
        );

        /*
        |--------------------------------------------------------------------------
        | No callback configured -> silently skip.
        |
        | Not every merchant is a store-integration partner, so a missing
        | URL is a normal condition, not an error.
        |--------------------------------------------------------------------------
        */
        if ($callbackUrl === '') {
            return;
        }

        $callbackSecret = (string) $merchant->integration_callback_secret;

        if ($callbackSecret === '') {
            throw new RuntimeException(
                'Integration callback secret is missing for merchant '
                . $this->merchantId . '.'
            );
        }

        $eventId = (string) (
            $this->payload['event_id']
            ?? 'evt_' . Str::lower(Str::random(32))
        );

        $timestamp = (string) now()->timestamp;

        /*
        |--------------------------------------------------------------------------
        | Identity fields required by the store partner
        |
        | The partner endpoint validates every callback the same way it
        | validates the onboarding callback and rejects any body that is
        | missing application_number (HTTP 422). We add the merchant's
        | application_number (and merchant_reference for parity with the
        | onboarding payload) so every pickup event passes validation.
        |
        | These are placed at the front of the body and are covered by the
        | signature because signing happens after this merge.
        |--------------------------------------------------------------------------
        */

        $body = array_merge(
            [
                'application_number' => $merchant->application_number,
                'merchant_reference' => $merchant->code,
            ],
            $this->payload
        );

        dd($body);

        $rawBody = json_encode(
            $body,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
        );

        $signature = hash_hmac(
            'sha256',
            $timestamp . '.' . $rawBody,
            $callbackSecret
        );

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Tukaatu-Event-ID' => $eventId,
            'X-Tukaatu-Timestamp' => $timestamp,
            'X-Tukaatu-Signature' => $signature,
        ])
            ->withBody($rawBody, 'application/json')
            ->timeout(20)
            ->post($callbackUrl);

        if (! $response->successful()) {
            $message = sprintf(
                'Pickup callback failed | event: %s | HTTP %d | URL: %s | body: %s',
                (string) ($this->payload['event'] ?? 'unknown'),
                $response->status(),
                $callbackUrl,
                Str::limit(trim($response->body()), 2000)
            );

            Log::warning($message, [
                'merchant_id' => $this->merchantId,
                'event_id' => $eventId,
            ]);

            throw new RuntimeException($message);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error(
            'Pickup callback permanently failed after retries.',
            [
                'merchant_id' => $this->merchantId,
                'event' => $this->payload['event'] ?? null,
                'event_id' => $this->payload['event_id'] ?? null,
                'error' => $exception->getMessage(),
            ]
        );
    }
}
