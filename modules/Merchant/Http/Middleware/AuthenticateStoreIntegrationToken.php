<?php

namespace Modules\Merchant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateStoreIntegrationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = (string) config('services.store_manager.submission_token');
        $providedToken = trim((string) $request->bearerToken());

        if (
            $expectedToken === '' ||
            $providedToken === '' ||
            !hash_equals($expectedToken, $providedToken)
        ) {
            return response()->json([
                'message' => 'Invalid Store Manager integration token.',
                'code' => 'INVALID_STORE_INTEGRATION_TOKEN',
            ], 401);
        }

        return $next($request);
    }
}
