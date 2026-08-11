<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicPricingCors
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        /*
         * Handle browser preflight request.
         */
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        /*
         * This endpoint is intentionally public for browser
         * clients from any origin.
         */
        if ($origin) {
            $response->headers->set(
                'Access-Control-Allow-Origin',
                '*'
            );

            $response->headers->set(
                'Access-Control-Allow-Methods',
                'POST, OPTIONS'
            );

            $response->headers->set(
                'Access-Control-Allow-Headers',
                'Content-Type, Accept, Authorization, X-Requested-With'
            );

            $response->headers->set(
                'Access-Control-Max-Age',
                '86400'
            );
        }

        return $response;
    }
}