<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiCorsMiddleware
{
    /**
     * Public endpoints that any external storefront may call.
     * These endpoints reflect the requesting Origin.
     */
    private const PUBLIC_DYNAMIC_CORS_PATHS = [
        'api/v1/public/pricing/estimate',
    ];

    /**
     * Fixed origins allowed for normal / admin APIs.
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
        'http://localhost:3000',
        'http://localhost:3001',
        'http://localhost:3002',
        'http://localhost:3003',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:3001',
        'http://127.0.0.1:3002',
        'http://127.0.0.1:3003',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        // Non-browser request – just continue
        if (!$origin) {
            return $next($request);
        }

        // -------------------------------------------------
        // PUBLIC EXTERNAL STOREFRONT ENDPOINTS
        // -------------------------------------------------
        // Any origin is allowed (lavishme.tukaatu.com, acstore.com, …)
        if ($this->isPublicPricingPath($request)) {
            return $this->handlePublicPricingCors($request, $next, $origin);
        }

        // -------------------------------------------------
        // NORMAL / ADMIN API CORS
        // -------------------------------------------------
        if (!in_array($origin, self::ALLOWED_ORIGINS, true)) {
            // Unknown origin → no CORS headers (browser will block)
            return $next($request);
        }

        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
            $this->addNormalCorsHeaders($response, $origin);
            return $response;
        }

        $response = $next($request);
        $this->addNormalCorsHeaders($response, $origin);
        return $response;
    }

    /**
     * Robust path check – works with or without leading slash / api prefix issues.
     */
    private function isPublicPricingPath(Request $request): bool
    {
        // Most reliable Laravel helper
        if ($request->is('api/v1/public/pricing/estimate')) {
            return true;
        }

        // Fallback for edge cases
        $path = trim($request->path(), '/');
        return $path === 'api/v1/public/pricing/estimate'
            || str_ends_with($path, '/api/v1/public/pricing/estimate');
    }

    private function handlePublicPricingCors(
        Request $request,
        Closure $next,
        string $origin
    ): Response {
        // Preflight
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
            $this->addPublicCorsHeaders($response, $origin);
            return $response;
        }

        // Actual request
        $response = $next($request);
        $this->addPublicCorsHeaders($response, $origin);
        return $response;
    }

    /**
     * CORS headers for public pricing – any origin is reflected.
     */
    private function addPublicCorsHeaders(Response $response, string $origin): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set(
            'Access-Control-Allow-Headers',
            'Content-Type, Accept, Authorization, X-Requested-With, X-Requested-With'
        );
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->set('Vary', 'Origin');

        // Public endpoint must never send credentials
        $response->headers->remove('Access-Control-Allow-Credentials');
    }

    private function addNormalCorsHeaders(Response $response, string $origin): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set(
            'Access-Control-Allow-Methods',
            'GET, POST, PUT, PATCH, DELETE, OPTIONS'
        );
        $response->headers->set(
            'Access-Control-Allow-Headers',
            'Content-Type, Accept, Authorization, X-Requested-With'
        );
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->set('Vary', 'Origin');
    }
}