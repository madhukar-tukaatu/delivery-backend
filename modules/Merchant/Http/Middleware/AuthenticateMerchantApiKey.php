<?php

namespace Modules\Merchant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Merchant\Services\MerchantApiKeyGuard;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMerchantApiKey
{
    public function __construct(
        private readonly MerchantApiKeyGuard $guard
    ) {
    }

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        /*
        |--------------------------------------------------------------------------
        | API key is mandatory
        |--------------------------------------------------------------------------
        */

        $key = trim((string) $request->header('X-Tukaatu-Key'));

        if ($key === '') {
            return response()->json([
                'message' => 'Tukaatu API key is required.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | API secret is mandatory
        |--------------------------------------------------------------------------
        */

        $secret = trim((string) $request->header('X-Tukaatu-Secret'));

        if ($secret === '') {
            return response()->json([
                'message' => 'Tukaatu API secret is required.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | Resolve and authenticate credential
        |--------------------------------------------------------------------------
        */

        $merchantKey = $this->guard->resolve($request);

        if (!$merchantKey) {
            return response()->json([
                'message' => 'Invalid Tukaatu API credentials.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | Attach authenticated integration context
        |--------------------------------------------------------------------------
        */

        $request->attributes->set(
            'merchant_api_key',
            $merchantKey
        );

        $request->attributes->set(
            'merchant_id',
            (int) $merchantKey->merchant_id
        );

        $request->attributes->set(
            'merchant',
            $merchantKey->merchant ?? null
        );

        return $next($request);
    }
}