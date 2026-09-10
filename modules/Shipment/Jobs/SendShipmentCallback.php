<?php

declare(strict_types=1);

namespace Modules\Shipment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Merchant\Models\Merchant;
use Modules\Webhook\Models\WebhookDeliveryLog;
use RuntimeException;
use Throwable;

/**
 * Sends a single shipment-lifecycle event to the store partner's integration
 * callback URL, using the SAME destination + signing scheme as the pickup
 * callbacks (SendPickupCallback):
 *
 * - URL:    merchant.integration_callback_url
 * - Secret: merchant.integration_callback_secret
 * - Sign:   X-Tukaatu-Signature = HMAC-SHA256(timestamp . '.' . rawBody)
 *
 * The event type and payload are supplied by the caller (ShipmentCallbackService).
 */
class SendShipmentCallback implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    /**
     * @param  array<string, mixed>  $payload  Fully-built event payload.
     */
    public function __construct(
        public int $merchantId,
        public array $payload,
    ) {
    }

    public function handle(): void
    {
        $merchant = Merchant::query()->find($this->merchantId);

        if (! $merchant) {
            return;
        }

        $callbackUrl = trim((string) $merchant->integration_callback_url);

        // Not every merchant is a store-integration partner; missing URL is
        // a normal condition, not an error.
        if ($callbackUrl === '') {
            return;
        }

        $callbackSecret = (string) $merchant->integration_callback_secret;

        if ($callbackSecret === '') {
            throw new RuntimeException(
                'Integration callback secret is missing for merchant ' . $this->merchantId . '.'
            );
        }

        $eventId = (string) ($this->payload['event_id'] ?? 'evt_' . Str::lower(Str::random(32)));
        $timestamp = (string) now()->timestamp;

        $body = array_merge(
            [
                'application_number' => $merchant->application_number,
                'merchant_reference' => $merchant->code,
            ],
            $this->payload
        );

        $rawBody = json_encode(
            $body,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $log = WebhookDeliveryLog::create([
            'merchant_id' => $this->merchantId,
            'shipment_id' => $this->payload['shipment']['id'] ?? null,
            'event' => $body['event'] ?? null,
            'webhook_url' => $callbackUrl,
            'payload' => $body,
            'status' => 'pending',
            'attempt_count' => 1,
            'last_attempt_at' => now(),
        ]);

        $signature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $callbackSecret);

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
                'Shipment callback failed | event: %s | HTTP %d | URL: %s',
                (string) ($body['event'] ?? 'unknown'),
                $response->status(),
                $callbackUrl
            );

            $log->forceFill([
                'status' => 'failed',
                'response_status_code' => $response->status(),
                'response_body' => Str::limit(trim($response->body()), 4000),
            ])->save();

            Log::warning($message, [
                'merchant_id' => $this->merchantId,
                'event_id' => $eventId,
            ]);

            throw new RuntimeException($message);
        }

        $log->forceFill([
            'status' => 'success',
            'response_status_code' => $response->status(),
            'response_body' => Str::limit(trim($response->body()), 4000),
        ])->save();

        Log::warning('Shipment callback delivered.', [
            'merchant_id' => $this->merchantId,
            'event' => $body['event'] ?? null,
            'event_id' => $eventId,
            'response_status' => $response->status(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Shipment callback permanently failed after retries.', [
            'merchant_id' => $this->merchantId,
            'event' => $this->payload['event'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
