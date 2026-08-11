<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiCorsMiddleware
{
    /**
     * Routes that should allow dynamic storefront origins.
     */
    private const PUBLIC_PRICING_PATH = 'api/v1/public/pricing/estimate';

    /**
     * Handle the request.
     */
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $origin = $request->headers->get('Origin');

        /*
         * Only apply unrestricted/dynamic CORS to the public
         * pricing estimate endpoint.
         *
         * Do NOT globally reflect every Origin for the entire API.
         */
        if (!$this->isPublicPricingEndpoint($request)) {
            return $next($request);
        }

        /*
         * Browser CORS preflight.
         *
         * Return immediately so the request does not continue
         * into Laravel's route/controller/throttle handling.
         */
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);

            return $this->addCorsHeaders(
                $response,
                $origin,
                $request
            );
        }

        /*
         * Normal POST request.
         */
        $response = $next($request);

        return $this->addCorsHeaders(
            $response,
            $origin,
            $request
        );
    }

    /**
     * Determine whether the request is the public pricing endpoint.
     */
    private function isPublicPricingEndpoint(
        Request $request
    ): bool {
        return $request->is(self::PUBLIC_PRICING_PATH);
    }

    /**
     * Add CORS response headers.
     */
    private function addCorsHeaders(
        Response $response,
        ?string $origin,
        Request $request
    ): Response {
        /*
         * No Origin means this is not a browser CORS request.
         */
        if (!$origin) {
            return $response;
        }

        /*
         * IMPORTANT:
         *
         * We intentionally reflect the requesting Origin.
         *
         * This allows:
         *
         * https://abcstore.com
         * https://xyzstore.com
         * https://lavishme.tukaatu.com
         * https://anything.example.com
         *
         * without maintaining a database/config list of domains.
         */
        $response->headers->set(
            'Access-Control-Allow-Origin',
            $origin
        );

        /*
         * The response varies depending on Origin.
         */
        $response->headers->set(
            'Vary',
            'Origin'
        );

        /*
         * Public pricing only needs POST and OPTIONS.
         */
        $response->headers->set(
            'Access-Control-Allow-Methods',
            'POST, OPTIONS'
        );

        /*
         * Allow the headers browsers commonly send.
         *
         * "*" is also acceptable for non-credentialed CORS,
         * but explicitly listing them is safer/predictable.
         */
        $response->headers->set(
            'Access-Control-Allow-Headers',
            'Content-Type, Accept, Authorization, X-Requested-With'
        );

        /*
         * Browser can cache the preflight result for 24 hours.
         */
        $response->headers->set(
            'Access-Control-Max-Age',
            '86400'
        );

        /*
         * IMPORTANT:
         *
         * This endpoint is public and should not use browser
         * credentials/cookies.
         *
         * Therefore do NOT send:
         *
         * Access-Control-Allow-Credentials: true
         */
        $response->headers->remove(
            'Access-Control-Allow-Credentials'
        );

        return $response;
    }
}