<?php

namespace Modules\POD\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\POD\Services\StoreManagerPaymentService;
use Modules\POD\Services\TukaatuPodPaymentService;

/**
 * Inbound payment events from Tukaatu / Store Manager.
 *
 * URL: POST /api/v1/express/callback
 * Legacy URLs still call this same handler.
 *
 * New doorstep POD (preferred):
 *   Header X-Tukaatu-Event: pod_payment.ready | pod_payment.paid | pod_payment.failed
 *   Signature: X-Tukaatu-Signature (HMAC with TUKAATU_CALLBACK_SECRET)
 *
 * Legacy Store Manager sessions:
 *   Header X-Tukaatu-Event-ID + timestamp/signature (STORE_MANAGER_PAYMENT_INTEGRATION_SECRET)
 */
final class StoreManagerPaymentWebhookController extends Controller
{
    public function handle(
        Request $request,
        StoreManagerPaymentService $legacy,
        TukaatuPodPaymentService $tukaatu,
    ): JsonResponse {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return ApiResponse::error('Invalid payment event payload.', 400);
        }

        $event = trim((string) (
            $request->header('X-Tukaatu-Event')
            ?: ($payload['event'] ?? '')
        ));

        $normalized = strtolower(str_replace(['-', ' '], ['_', '_'], $event));

        if ($this->isDeliveryPodEvent($normalized)) {
            return $this->podCallback($request, $tukaatu);
        }

        $eventId = trim((string) $request->header('X-Tukaatu-Event-ID'));
        if ($eventId === '') {
            return ApiResponse::error('X-Tukaatu-Event or X-Tukaatu-Event-ID header is required.', 401);
        }

        $data = $legacy->handleWebhook(
            rawBody: $rawBody,
            payload: $payload,
            timestamp: trim((string) $request->header('X-Tukaatu-Timestamp')),
            signature: trim((string) $request->header('X-Tukaatu-Signature')),
            eventId: $eventId,
        );

        return ApiResponse::success($data, 'Payment event processed.');
    }

    /**
     * Delivery POD online only: pod_payment.ready|paid|failed.
     */
    public function podCallback(Request $request, TukaatuPodPaymentService $tukaatu): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return ApiResponse::error('Invalid payment event payload.', 400);
        }

        $event = trim((string) (
            $request->header('X-Tukaatu-Event')
            ?: ($payload['event'] ?? '')
        ));
        $normalized = strtolower(str_replace(['-', ' '], '_', $event));
        if (! $this->isDeliveryPodEvent($normalized)) {
            return ApiResponse::error('Unsupported delivery POD event.', 422);
        }

        $data = $tukaatu->handleDeliveryPodCallback(
            event: $event,
            payload: $payload,
            rawBody: $rawBody,
            timestamp: trim((string) $request->header('X-Tukaatu-Timestamp')),
            signature: trim((string) $request->header('X-Tukaatu-Signature')),
            eventId: trim((string) (
                $request->header('X-Tukaatu-Event-ID')
                ?: $request->header('X-Tukaatu-Event-Id')
                ?: ''
            )) ?: null,
        );

        return ApiResponse::success($data, 'POD payment event processed.');
    }

    private function isDeliveryPodEvent(string $normalized): bool
    {
        return in_array($normalized, [
            'pod_payment.ready',
            'pod_payment.paid',
            'pod_payment.failed',
            'pod_payment_ready',
            'pod_payment_paid',
            'pod_payment_failed',
        ], true);
    }
}
