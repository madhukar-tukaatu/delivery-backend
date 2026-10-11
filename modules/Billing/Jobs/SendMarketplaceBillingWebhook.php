<?php

declare(strict_types=1);

namespace Modules\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Billing\Models\MarketplaceBillDelivery;
use Modules\Setting\Models\Marketplace;
use Modules\Webhook\Models\WebhookDeliveryLog;
use RuntimeException;
use Throwable;

/**
 * Pushes a billing event (billing.bill.issued, billing.bill.reminder,
 * billing.bill.payment_recorded, billing.bill.paid, billing.bill.payment_reversed,
 * billing.payment_notice.*) to the marketplace callback_url. Same signing as
 * SendShipmentCallback:
 *
 *   X-Tukaatu-Event-ID, X-Tukaatu-Timestamp,
 *   X-Tukaatu-Signature = HMAC-SHA256(timestamp . '.' . rawBody, marketplace.callback_secret)
 *
 * Retries with backoff (60s, 5m, 15m, 30m; 5 tries). When a delivery row id is
 * given (marketplace_bill_deliveries), each attempt updates attempts, HTTP
 * status, last error and the final status (delivered / failed / skipped), so
 * the branch sees it on the bill in admin.
 */
class SendMarketplaceBillingWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 1800];

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public int $marketplaceId,
        public array $payload,
        public ?int $deliveryId = null,
    ) {
    }

    public function handle(): void
    {
        $marketplace = Marketplace::query()->find($this->marketplaceId);
        $url = trim((string) ($marketplace?->callback_url ?? ''));
        if (! $marketplace || $url === '') {
            $this->track(['status' => 'skipped', 'last_error' => 'No callback URL is set on the marketplace']);

            return;
        }

        $secret = $this->secretFor($marketplace);
        if ($secret === '') {
            // Not retryable: an admin must set the callback secret first.
            Log::warning('marketplace_billing_webhook.skipped_no_secret', [
                'marketplace_id' => $this->marketplaceId,
                'event' => $this->payload['event'] ?? null,
            ]);
            $this->track(['status' => 'skipped', 'last_error' => 'No callback secret is set on the marketplace (or it cannot be decrypted)']);

            return;
        }

        $eventId = (string) ($this->payload['event_id'] ?? 'evt_'.Str::lower(Str::random(32)));
        $timestamp = (string) now()->timestamp;
        $body = array_merge(['event_id' => $eventId], $this->payload);
        $rawBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        $log = null;
        try {
            $log = WebhookDeliveryLog::create([
                'merchant_id' => null,
                'shipment_id' => null,
                'event' => (string) ($body['event'] ?? 'billing'),
                'webhook_url' => $url,
                'payload' => $body,
                'signature' => $signature,
                'status' => 'pending',
                'attempt_count' => $this->attempts(),
                'last_attempt_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::info('marketplace_billing_webhook.log_skipped', ['error' => $e->getMessage()]);
        }

        $this->track(['attempts' => $this->attempts(), 'last_attempt_at' => now(), 'url' => mb_substr($url, 0, 500)]);

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Tukaatu-Event-ID' => $eventId,
                'X-Tukaatu-Timestamp' => $timestamp,
                'X-Tukaatu-Signature' => $signature,
            ])->withBody($rawBody, 'application/json')->timeout(20)->post($url);
        } catch (Throwable $e) {
            $log?->forceFill(['status' => 'failed', 'response_body' => Str::limit($e->getMessage(), 4000)])->save();
            $this->track(['http_status' => null, 'last_error' => Str::limit('Connection error: '.$e->getMessage(), 990), 'status' => $this->isLastAttempt() ? 'failed' : 'queued']);

            throw new RuntimeException('Marketplace billing webhook connection failed: '.$e->getMessage(), 0, $e);
        }

        $log?->forceFill([
            'status' => $response->successful() ? 'success' : 'failed',
            'response_status_code' => $response->status(),
            'response_body' => Str::limit(trim($response->body()), 4000),
        ])->save();

        if ($response->successful()) {
            $this->track(['status' => 'delivered', 'http_status' => $response->status(), 'last_error' => null, 'delivered_at' => now()]);

            return;
        }

        $error = 'HTTP '.$response->status().': '.Str::limit(trim($response->body()), 300);
        $this->track(['status' => $this->isLastAttempt() ? 'failed' : 'queued', 'http_status' => $response->status(), 'last_error' => $error]);

        throw new RuntimeException(sprintf(
            'Marketplace billing webhook failed | event: %s | HTTP %d | URL: %s',
            (string) ($body['event'] ?? 'unknown'),
            $response->status(),
            $url
        ));
    }

    public function failed(Throwable $exception): void
    {
        $this->track(['status' => 'failed', 'last_error' => Str::limit($exception->getMessage(), 990)]);
        Log::error('Marketplace billing webhook permanently failed.', [
            'marketplace_id' => $this->marketplaceId,
            'event' => $this->payload['event'] ?? null,
            'delivery_id' => $this->deliveryId,
            'error' => $exception->getMessage(),
        ]);
    }

    protected function secretFor(Marketplace $marketplace): string
    {
        try {
            return (string) ($marketplace->callback_secret ?? '');
        } catch (Throwable) {
            return '';
        }
    }

    private function isLastAttempt(): bool
    {
        return $this->attempts() >= $this->tries;
    }

    /** @param  array<string, mixed>  $patch */
    private function track(array $patch): void
    {
        if (! $this->deliveryId) {
            return;
        }
        try {
            MarketplaceBillDelivery::query()->whereKey($this->deliveryId)->update($patch + ['updated_at' => now()]);
        } catch (Throwable $e) {
            Log::info('marketplace_billing_webhook.track_failed', ['delivery_id' => $this->deliveryId, 'error' => $e->getMessage()]);
        }
    }
}