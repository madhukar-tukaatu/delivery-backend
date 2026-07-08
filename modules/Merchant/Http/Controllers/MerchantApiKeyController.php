<?php

namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantApiKey;

class MerchantApiKeyController extends Controller
{
    public function index(Request $request)
    {
        $merchantId = $request->user()->role === 'merchant' ? $request->user()->merchant_id : $request->get('merchant_id');
        $query = MerchantApiKey::with('merchant')->latest();
        if ($merchantId) $query->where('merchant_id', $merchantId);
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'name' => ['required', 'string'],
            'environment' => ['nullable', 'in:sandbox,live'],
        ]);
        $merchantId = $request->user()->role === 'merchant' ? $request->user()->merchant_id : $data['merchant_id'];
        $merchant = Merchant::findOrFail($merchantId);
        $secret = 'sk_'.Str::random(48);
        $key = MerchantApiKey::create([
            'merchant_id' => $merchant->id,
            'name' => $data['name'],
            'api_key' => 'pk_'.Str::random(40),
            'api_secret_hash' => Hash::make($secret),
            'environment' => $data['environment'] ?? 'sandbox',
            'permissions' => ['shipments:create', 'shipments:read', 'rates:calculate'],
            'status' => 'active',
        ]);
        return ApiResponse::success([
            'api_key' => $key->api_key,
            'api_secret' => $secret,
            'record' => $key,
            'note' => 'Copy the API secret now. It is stored hashed and cannot be viewed again.',
        ], 'API key created.', 201);
    }

    public function destroy(Request $request, MerchantApiKey $apiKey)
    {
        if ($request->user()->role === 'merchant' && $request->user()->merchant_id !== $apiKey->merchant_id) {
            return ApiResponse::error('Forbidden.', 403);
        }
        $apiKey->update(['status' => 'disabled']);
        return ApiResponse::success(null, 'API key disabled.');
    }
}
