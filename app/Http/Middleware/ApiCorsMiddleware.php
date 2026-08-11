<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiCorsMiddleware
{
    /**
     * Public endpoints that can be called by external storefronts.
     */
    private const PUBLIC_DYNAMIC_CORS_PATHS = [
        'api/v1/public/pricing/estimate',
    ];

    /**
     * Fixed origins allowed for normal/admin APIs.
     */
    private const ALLOWED_ORIGINS = [
        'https://tukaatuexpress.com',
        'https://www.tukaatuexpress.com',

        'https://tukaatu.com',
        'https://www.tukaatu.com',

        'https://fca.com.np',
        'https://www.fca.com.np',

        'https://api.tukaatu.com',
        'https://api.fca.com.np',

        'https://tukaatuexpress.com',
        'https://www.tukaatuexpress.com',

        'http://localhost:3000',
        'http://localhost:3001',
        'http://localhost:3002',
        'http://localhost:3003',

        'http://127.0.0.1:3000',
        'http://127.0.0.1:3001',
        'http://127.0.0.1:3002',
        'http://127.0.0.1:3003',
    ];

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $origin = $request->headers->get('Origin');

        /*
        |--------------------------------------------------------------------------
        | Non-browser request
        |--------------------------------------------------------------------------
        */

        if (!$origin) {
            return $next($request);
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize path
        |--------------------------------------------------------------------------
        */

        $path = trim($request->path(), '/');

        /*
        |--------------------------------------------------------------------------
        | PUBLIC EXTERNAL STORE PRICING
        |--------------------------------------------------------------------------
        |
        | Example:
        |
        | https://lavishme.tukaatu.com
        |
        | can call:
        |
        | POST /api/v1/public/pricing/estimate
        |
        */

        if (in_array(
            $path,
            self::PUBLIC_DYNAMIC_CORS_PATHS,
            true
        )) {
            return $this->handlePublicPricingCors(
                $request,
                $next,
                $origin
            );
        }

        /*
        |--------------------------------------------------------------------------
        | NORMAL API CORS
        |--------------------------------------------------------------------------
        */

        if (!in_array($origin, self::ALLOWED_ORIGINS, true)) {
            /*
             * Do not add CORS headers for unknown normal API origins.
             *
             * The request itself is still allowed to continue.
             * The browser will block access to the response.
             */
            return $next($request);
        }

        /*
        |--------------------------------------------------------------------------
        | OPTIONS
        |--------------------------------------------------------------------------
        */

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);

            $this->addNormalCorsHeaders(
                $response,
                $origin
            );

            return $response;
        }

        /*
        |--------------------------------------------------------------------------
        | Normal request
        |--------------------------------------------------------------------------
        */

        $response = $next($request);

        $this->addNormalCorsHeaders(
            $response,
            $origin
        );

        return $response;
    }

    /**
     * Handle external storefront pricing CORS.
     */
    private function handlePublicPricingCors(
        Request $request,
        Closure $next,
        string $origin
    ): Response {
        /*
        |--------------------------------------------------------------------------
        | Preflight
        |--------------------------------------------------------------------------
        */

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);

            $this->addPublicCorsHeaders(
                $response,
                $origin
            );

            return $response;
        }

        /*
        |--------------------------------------------------------------------------
        | Actual POST
        |--------------------------------------------------------------------------
        */

        $response = $next($request);

        $this->addPublicCorsHeaders(
            $response,
            $origin
        );

        return $response;
    }

    /**
     * CORS headers for the public pricing endpoint.
     */
    private function addPublicCorsHeaders(
        Response $response,
        string $origin
    ): void {
        $response->headers->set(
            'Access-Control-Allow-Origin',
            $origin
        );

        $response->headers->set(
            'Access-Control-Allow-Methods',
            'POST, OPTIONS'
        );

        $response->headers->set(
            'Access-Control-Allow-Headers',
            'Content-Type, Accept, Authorization, X-Requested-With'
        );

        /*
        |--------------------------------------------------------------------------
        | Credentials
        |--------------------------------------------------------------------------
        |
        | Public pricing does not require browser cookies.
        |
        */

        $response->headers->remove(
            'Access-Control-Allow-Credentials'
        );

        $response->headers->set(
            'Access-Control-Max-Age',
            '86400'
        );

        /*
        |--------------------------------------------------------------------------
        | Dynamic origin requires Vary
        |--------------------------------------------------------------------------
        */

        $response->headers->set(
            'Vary',
            'Origin'
        );
    }

    /**
     * CORS headers for normal APIs.
     */
    private function addNormalCorsHeaders(
        Response $response,
        string $origin
    ): void {
        $response->headers->set(
            'Access-Control-Allow-Origin',
            $origin
        );

        $response->headers->set(
            'Access-Control-Allow-Methods',
            'GET, POST, PUT, PATCH, DELETE, OPTIONS'
        );

        $response->headers->set(
            'Access-Control-Allow-Headers',
            'Content-Type, Accept, Authorization, X-Requested-With'
        );

        $response->headers->set(
            'Access-Control-Allow-Credentials',
            'true'
        );

        $response->headers->set(
            'Access-Control-Max-Age',
            '86400'
        );

        $response->headers->set(
            'Vary',
            'Origin'
        );
    }
}