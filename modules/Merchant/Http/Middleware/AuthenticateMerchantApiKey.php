<?php

declare(strict_types=1);

namespace Modules\Merchant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Merchant\Services\MerchantApiKeyGuard;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateMerchantApiKey
{
    public function __construct(
        private readonly MerchantApiKeyGuard $guard
    ) {
    }

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $merchantApiKey = $this->guard->resolve($request);

        /*
        |--------------------------------------------------------------------------
        | Store authenticated integration context
        |--------------------------------------------------------------------------
        */

        $request->attributes->set(
            'merchant_api_key',
            $merchantApiKey
        );

        $request->attributes->set(
            'merchant_id',
            (int) $merchantApiKey->merchant_id
        );

        $request->attributes->set(
            'authenticated_merchant_id',
            (int) $merchantApiKey->merchant_id
        );

        return $next($request);
    }
}