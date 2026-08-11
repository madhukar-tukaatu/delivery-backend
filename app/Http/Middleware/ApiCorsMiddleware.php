<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Middleware\HandleCors as LaravelHandleCors;
use Symfony\Component\HttpFoundation\Response;

class ApiCorsMiddleware extends LaravelHandleCors
{
    /**
     * The endpoint that is allowed to be called
     * from any browser origin.
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
         * Only override Laravel's normal CORS behavior
         * for the public pricing estimate endpoint.
         */
        if ($this->isPublicPricingEndpoint($request)) {
            return $this->handlePublicPricingCors($request, $next);
        }

        /*
         * EVERYTHING ELSE continues to use Laravel's
         * normal HandleCors implementation.
         *
         * That means config/cors.php continues to control
         * the allowed origins for all other APIs.
         */
        return parent::handle($request, $next);
    }

    /**
     * Determine whether the request is for the public
     * pricing estimate endpoint.
     */
    private function isPublicPricingEndpoint(Request $request): bool
    {
        return $request->is(self::PUBLIC_PRICING_PATH);
    }

    /**
     * Handle CORS for the public pricing endpoint.
     *
     * This endpoint intentionally accepts requests from
     * any browser origin.
     */
    private function handlePublicPricingCors(
        Request $request,
        Closure $next
    ): Response {
        $origin = $request->headers->get('Origin');

        /*
         * Browser preflight request.
         *
         * We handle it here before Laravel tries to find
         * a route for OPTIONS.
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

        $this->addPublicPricingCorsHeaders(
            $response,
            $origin
        );

        return $response;
    }

    /**
     * Add CORS headers for the public pricing endpoint.
     */
    private function addPublicPricingCorsHeaders(
        Response $response,
        ?string $origin
    ): void {
        /*
         * If this is a browser request, echo its origin.
         *
         * This effectively allows ANY origin while remaining
         * compatible with browsers that reject:
         *
         * Access-Control-Allow-Origin: *
         *
         * when credentials are involved.
         */
        if ($origin !== null && $origin !== '') {
            $response->headers->set(
                'Access-Control-Allow-Origin',
                $origin
            );

            $response->headers->set(
                'Vary',
                'Origin'
            );
        }

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
}