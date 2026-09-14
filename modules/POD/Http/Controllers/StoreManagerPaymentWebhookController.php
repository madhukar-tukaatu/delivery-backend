<?php

namespace Modules\POD\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\POD\Services\StoreManagerPaymentService;

final class StoreManagerPaymentWebhookController extends Controller
{
    public function handle(Request $request, StoreManagerPaymentService $service): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);

        if (!is_array($payload)) {
            return ApiResponse::error('Invalid payment event payload.', 400);
        }

        $eventId = trim((string) $request->header('X-Tukaatu-Event-ID'));
        if ($eventId === '') {
            return ApiResponse::error('X-Tukaatu-Event-ID header is required.', 401);
        }

        $data = $service->handleWebhook(
            rawBody: $rawBody,
            payload: $payload,
            timestamp: trim((string) $request->header('X-Tukaatu-Timestamp')),
            signature: trim((string) $request->header('X-Tukaatu-Signature')),
            eventId: $eventId,
        );

        return ApiResponse::success($data, 'Payment event processed.');
    }
}
