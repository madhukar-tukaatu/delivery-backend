<?php

namespace Modules\Webhook\Services;

use Illuminate\Support\Facades\Http;
use Modules\Merchant\Models\MerchantWebhook;
use Modules\Shipment\Models\Shipment;
use Modules\Webhook\Models\WebhookDeliveryLog;

class WebhookService
{
    public function queueShipmentEvent(Shipment $shipment, string $event): void
    {
        if (!$shipment->merchant_id) {
            return;
        }

        $webhooks = MerchantWebhook::where('merchant_id', $shipment->merchant_id)
            ->where('status', 'active')
            ->get();

        foreach ($webhooks as $webhook) {
            $events = $webhook->events ?: [];
            if ($events && !in_array($event, $events, true)) {
                continue;
            }

            $payload = [
                'event' => $event,
                'tracking_number' => $shipment->tracking_number,
                'merchant_order_id' => $shipment->merchant_order_id,
                'status' => $shipment->status,
                'merchant_status' => $shipment->merchant_status,
                'cod_amount' => (float) $shipment->cod_amount,
                'delivery_charge' => (float) $shipment->delivery_charge,
                'updated_at' => now()->toIso8601String(),
            ];

            $signature = $webhook->secret ? hash_hmac('sha256', json_encode($payload), $webhook->secret) : null;

            WebhookDeliveryLog::create([
                'merchant_id' => $shipment->merchant_id,
                'shipment_id' => $shipment->id,
                'event' => $event,
                'webhook_url' => $webhook->url,
                'payload' => $payload,
                'signature' => $signature,
                'status' => 'pending',
                'next_retry_at' => now(),
            ]);
        }
    }

    public function sendLog(WebhookDeliveryLog $log): WebhookDeliveryLog
    {
        $log->attempt_count = $log->attempt_count + 1;
        $log->last_attempt_at = now();

        try {
            $response = Http::timeout(10)
                ->withHeaders(array_filter([
                    'X-Webhook-Event' => $log->event,
                    'X-Webhook-Signature' => $log->signature,
                    'Content-Type' => 'application/json',
                ]))
                ->post($log->webhook_url, $log->payload ?? []);

            $log->response_status_code = $response->status();
            $log->response_body = mb_substr($response->body(), 0, 5000);
            $log->status = $response->successful() ? 'success' : 'failed';
            if (!$response->successful()) {
                $log->next_retry_at = now()->addMinutes($this->retryMinutes($log->attempt_count));
            }
        } catch (\Throwable $e) {
            $log->status = 'failed';
            $log->response_body = mb_substr($e->getMessage(), 0, 5000);
            $log->next_retry_at = now()->addMinutes($this->retryMinutes($log->attempt_count));
        }

        $log->save();
        return $log;
    }

    private function retryMinutes(int $attempts): int
    {
        return match (true) {
            $attempts <= 1 => 5,
            $attempts === 2 => 30,
            $attempts === 3 => 120,
            default => 1440,
        };
    }
}
