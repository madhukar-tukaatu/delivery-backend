<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Middleware\HandleCors as LaravelHandleCors;
use Symfony\Component\HttpFoundation\Response;

class ApiCorsMiddleware extends LaravelHandleCors
{
    /**
     * Public pricing endpoint.
     *
     * This endpoint is intentionally available from ANY browser origin.
     */
    private const PUBLIC_PRICING_PATH = 'api/v1/public/pricing/estimate';

    /**
     * Handle an incoming request.
     */
    public function handle(
        $request,
        Closure $next
    ): Response {
        /*
         * Special CORS handling for the public pricing endpoint.
         */
        if ($this->isPublicPricingEndpoint($request)) {
            return $this->handlePublicPricingCors(
                $request,
                $next
            );
        }

        /*
         * Every other API continues through Laravel's
         * standard HandleCors implementation.
         *
         * Therefore config/cors.php controls CORS for
         * all other API endpoints.
         */
        return parent::handle(
            $request,
            $next
        );
    }

    /**
     * Check whether the request is for the public pricing
     * estimate endpoint.
     */
    private function isPublicPricingEndpoint(
        Request $request
    ): bool {
        return $request->is(
            self::PUBLIC_PRICING_PATH
        );
    }

    /**
     * Handle CORS for the public pricing endpoint.
     *
     * ANY Origin is allowed.
     */
    private function handlePublicPricingCors(
        Request $request,
        Closure $next
    ): Response {
        $origin = $request->headers->get('Origin');

        /*
         * Handle browser preflight.
         *
         * This happens BEFORE Laravel attempts to resolve
         * the POST route.
         */
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);

            $this->addPublicPricingCorsHeaders(
                $response,
                $origin
            );

            return $response;
        }

        /*
         * Normal POST request.
         */
        $response = $next($request);

        /*
         * Add CORS headers to the actual response as well.
         *
         * This is important because fixing OPTIONS alone
         * is not enough.
         */
        $this->addPublicPricingCorsHeaders(
            $response,
            $origin
        );

        return $response;
    }

    /**
     * Add CORS headers for the public pricing endpoint.
     *
     * IMPORTANT:
     *
     * We do NOT use:
     *
     * Access-Control-Allow-Origin: *
     *
     * Instead, when the browser sends an Origin header,
     * we echo that origin back.
     *
     * This effectively allows ANY origin.
     */
    private function addPublicPricingCorsHeaders(
        Response $response,
        ?string $origin
    ): void {
        /*
         * Allow ANY browser origin.
         *
         * Example:
         *
         * Origin: https://lavishme.tukaatu.com
         *
         * becomes:
         *
         * Access-Control-Allow-Origin:
         * https://lavishme.tukaatu.com
         *
         * Another website:
         *
         * Origin: https://example.com
         *
         * becomes:
         *
         * Access-Control-Allow-Origin:
         * https://example.com
         */
        if ($origin !== null && $origin !== '') {
            $response->headers->set(
                'Access-Control-Allow-Origin',
                $origin
            );

            /*
             * Required when Access-Control-Allow-Origin
             * changes depending on Origin.
             */
            $response->headers->set(
                'Vary',
                'Origin'
            );
        }

        /*
         * Public pricing only needs POST + OPTIONS.
         */
        $response->headers->set(
            'Access-Control-Allow-Methods',
            'POST, OPTIONS'
        );

        /*
         * Allow all request headers.
         *
         * This is useful because your frontend may send
         * Content-Type, Accept, Authorization, etc.
         */
        $response->headers->set(
            'Access-Control-Allow-Headers',
            '*'
        );

        /*
         * Cache successful preflight responses.
         */
        $response->headers->set(
            'Access-Control-Max-Age',
            '86400'
        );
    }
}