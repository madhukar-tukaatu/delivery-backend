<?php

namespace Modules\Webhook\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Merchant\Models\MerchantWebhook;
use Modules\Webhook\Models\WebhookDeliveryLog;
use Modules\Webhook\Services\WebhookService;

class WebhookController extends Controller
{
    public function logs(Request $request)
    {
        $query = WebhookDeliveryLog::query()->with(['merchant', 'shipment'])->latest();
        if ($request->filled('_scope_merchant_id')) $query->where('merchant_id', $request->get('_scope_merchant_id'));
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('event')) $query->where('event', $request->event);
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function retry(WebhookDeliveryLog $log, WebhookService $service)
    {
        $service->sendLog($log);
        return ApiResponse::success($log->fresh(), 'Webhook retry attempted.');
    }

    public function test(Request $request, WebhookService $service)
    {
        $data = $request->validate([
            'merchant_webhook_id' => ['required', 'exists:merchant_webhooks,id'],
        ]);
        $webhook = MerchantWebhook::findOrFail($data['merchant_webhook_id']);
        $log = WebhookDeliveryLog::create([
            'merchant_id' => $webhook->merchant_id,
            'event' => 'webhook.test',
            'webhook_url' => $webhook->url,
            'payload' => ['event' => 'webhook.test', 'sent_at' => now()->toIso8601String()],
            'signature' => hash_hmac('sha256', 'webhook.test', $webhook->secret ?: 'secret'),
            'status' => 'pending',
        ]);
        $service->sendLog($log);
        return ApiResponse::success($log->fresh(), 'Webhook test sent.');
    }
}
