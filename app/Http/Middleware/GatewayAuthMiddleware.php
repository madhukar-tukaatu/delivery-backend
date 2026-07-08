<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Merchant\Models\MerchantApiKey;
use Symfony\Component\HttpFoundation\Response;

class GatewayAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key');
        $timestamp = $request->header('X-Timestamp');
        $signature = $request->header('X-Signature');

        if (!$apiKey) {
            return response()->json(['success' => false, 'message' => 'Missing X-API-Key header.'], 401);
        }

        $key = MerchantApiKey::query()
            ->where('api_key', $apiKey)
            ->where('status', 'active')
            ->with('merchant')
            ->first();

        if (!$key || !$key->merchant || $key->merchant->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Invalid or inactive API key.'], 401);
        }

        if (!config('app.debug') || !filter_var(env('GATEWAY_ALLOW_UNSIGNED', false), FILTER_VALIDATE_BOOLEAN)) {
            if (!$timestamp || !$signature) {
                return response()->json(['success' => false, 'message' => 'Missing X-Timestamp or X-Signature header.'], 401);
            }

            $tolerance = (int) env('GATEWAY_SIGNATURE_TOLERANCE_SECONDS', 300);
            if (abs(time() - (int) $timestamp) > $tolerance) {
                return response()->json(['success' => false, 'message' => 'Gateway request timestamp expired.'], 401);
            }

            $plainSecret = $request->header('X-API-Secret-Dev');
            if (!$plainSecret || !Hash::check($plainSecret, $key->api_secret_hash)) {
                return response()->json(['success' => false, 'message' => 'Missing or invalid development API secret header.'], 401);
            }

            $payload = $timestamp.'.'.strtoupper($request->method()).'.'.$request->getPathInfo().'.'.$request->getContent();
            $expected = hash_hmac('sha256', $payload, $plainSecret);

            if (!hash_equals($expected, $signature)) {
                return response()->json(['success' => false, 'message' => 'Invalid gateway signature.'], 401);
            }
        }

        $key->forceFill(['last_used_at' => now()])->save();
        $request->attributes->set('merchant', $key->merchant);
        $request->attributes->set('merchant_api_key', $key);

        return $next($request);
    }
}
