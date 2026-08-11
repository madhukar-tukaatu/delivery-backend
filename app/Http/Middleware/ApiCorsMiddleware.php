<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiCorsMiddleware
{
    /**
     * Public endpoints that are allowed to be called
     * from arbitrary merchant/storefront domains.
     */
    private const PUBLIC_DYNAMIC_CORS_PATHS = [
        'api/v1/public/pricing/estimate',
    ];

    /**
     * Fixed origins allowed for normal APIs.
     */
    private const ALLOWED_ORIGINS = [
        'https://tukaatuexpress.com',
        'https://www.tukaatuexpress.com',
        'https://tukaatu.com',
        'https://fca.com.np',
        'https://api.tukaatu.com',
        'https://api.fca.com.np',

        'http://localhost:3000',
        'http://localhost:3001',
        'http://localhost:3002',
        'http://localhost:3003',
    ];

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $origin = $request->headers->get('Origin');

        /*
         * No Origin means this is not a browser CORS request.
         */
        if (!$origin) {
            return $next($request);
        }

        /*
         * Normalize the request path.
         *
         * Laravel's $request->path() does not contain
         * the leading slash.
         */
        $path = trim($request->path(), '/');

        $isDynamicPublicCorsEndpoint = in_array(
            $path,
            self::PUBLIC_DYNAMIC_CORS_PATHS,
            true
        );

        /*
         * ---------------------------------------------------------
         * PUBLIC PRICING ENDPOINT
         * ---------------------------------------------------------
         *
         * This endpoint is intentionally usable by arbitrary
         * merchant storefronts.
         *
         * Example:
         *
         * https://lavishme.tukaatu.com
         * https://abcstore.com
         * https://mystore.com
         * https://another-store.com
         *
         * No database/config allowlist is required.
         */
        if ($isDynamicPublicCorsEndpoint) {
            return $this->handleDynamicPublicCors(
                $request,
                $next,
                $origin
            );
        }

        /*
         * ---------------------------------------------------------
         * NORMAL API CORS
         * ---------------------------------------------------------
         *
         * Normal APIs remain restricted to known origins.
         */
        if (!in_array($origin, self::ALLOWED_ORIGINS, true)) {
            /*
             * Do NOT send Access-Control-Allow-Origin for an
             * unapproved normal API origin.
             *
             * Let the request continue normally. The browser
             * will enforce CORS.
             */
            return $next($request);
        }

        $response = $next($request);

        $this->addNormalCorsHeaders(
            $response,
            $origin
        );

        return $response;
    }

    /**
     * Handle the public pricing endpoint.
     */
    private function handleDynamicPublicCors(
        Request $request,
        Closure $next,
        string $origin
    ): Response {
        /*
         * Preflight request.
         *
         * IMPORTANT:
         * Return here instead of calling $next().
         *
         * This guarantees Laravel's normal route handling does
         * not interfere with the OPTIONS request.
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
         * Normal POST request.
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
        /*
         * Reflect the requesting storefront's origin.
         *
         * This allows:
         *
         * Origin: https://lavishme.tukaatu.com
         *
         * to receive:
         *
         * Access-Control-Allow-Origin:
         * https://lavishme.tukaatu.com
         *
         * And:
         *
         * Origin: https://abcstore.com
         *
         * to receive:
         *
         * Access-Control-Allow-Origin:
         * https://abcstore.com
         */
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
         * Do not enable credentialed browser requests for this
         * public endpoint unless the endpoint actually needs
         * cookies/session authentication.
         */
        $response->headers->remove(
            'Access-Control-Allow-Credentials'
        );

        $response->headers->set(
            'Access-Control-Max-Age',
            '86400'
        );

        /*
         * Very important when Access-Control-Allow-Origin is
         * dynamically generated from Origin.
         */
        $response->headers->set(
            'Vary',
            'Origin',
            false
        );
    }

    /**
     * CORS headers for normal restricted APIs.
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
            'Origin',
            false
        );
    }
}