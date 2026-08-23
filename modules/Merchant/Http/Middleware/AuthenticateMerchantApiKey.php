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
        $merchantKey = $this->guard->resolve($request);

        /*
        |--------------------------------------------------------------------------
        | Attach authenticated integration credential
        |--------------------------------------------------------------------------
        */

        $request->attributes->set(
            'merchant_api_key',
            $merchantKey
        );

        /*
        |--------------------------------------------------------------------------
        | Attach merchant ID
        |--------------------------------------------------------------------------
        */

        $request->attributes->set(
            'merchant_id',
            (int) $merchantKey->merchant_id
        );

        /*
        |--------------------------------------------------------------------------
        | Continue
        |--------------------------------------------------------------------------
        */

        return $next($request);
    }
}