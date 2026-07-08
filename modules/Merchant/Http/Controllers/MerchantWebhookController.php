<?php

namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Merchant\Models\MerchantWebhook;

class MerchantWebhookController extends Controller
{
    public function index(Request $request)
    {
        $merchantId = $request->user()->role === 'merchant' ? $request->user()->merchant_id : $request->get('merchant_id');
        $query = MerchantWebhook::latest();
        if ($merchantId) $query->where('merchant_id', $merchantId);
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'url' => ['required', 'url'],
            'events' => ['nullable', 'array'],
            'status' => ['nullable', 'in:active,disabled'],
        ]);
        $merchantId = $request->user()->role === 'merchant' ? $request->user()->merchant_id : $data['merchant_id'];
        $webhook = MerchantWebhook::create([
            'merchant_id' => $merchantId,
            'url' => $data['url'],
            'secret' => Str::random(32),
            'events' => $data['events'] ?? [],
            'status' => $data['status'] ?? 'active',
        ]);
        return ApiResponse::success($webhook, 'Webhook saved.', 201);
    }

    public function destroy(Request $request, MerchantWebhook $webhook)
    {
        if ($request->user()->role === 'merchant' && $request->user()->merchant_id !== $webhook->merchant_id) {
            return ApiResponse::error('Forbidden.', 403);
        }
        $webhook->delete();
        return ApiResponse::success(null, 'Webhook deleted.');
    }
}
