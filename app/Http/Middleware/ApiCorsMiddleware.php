<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiCorsMiddleware
{
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

        // Always treat the public pricing estimate as open to any origin
        if ($this->isPublicPricingPath($request)) {
            return $this->handlePublicPricingCors($request, $next, $origin ?? '*');
        }

        // Non-browser request
        if (!$origin) {
            return $next($request);
        }

        // Normal / admin APIs – only allow listed origins
        if (!in_array($origin, self::ALLOWED_ORIGINS, true)) {
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
     * Very aggressive path detection – catches any variation.
     */
    private function isPublicPricingPath(Request $request): bool
    {
        $path = trim($request->path(), '/');
        $uri  = $request->getRequestUri();

        return $request->is('api/v1/public/pricing/estimate')
            || $path === 'api/v1/public/pricing/estimate'
            || str_contains($path, 'public/pricing/estimate')
            || str_contains($uri, '/api/v1/public/pricing/estimate');
    }

    private function handlePublicPricingCors(
        Request $request,
        Closure $next,
        string $origin
    ): Response {
        // Preflight – answer immediately
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
            $this->addPublicCorsHeaders($response, $origin);
            return $response;
        }

        $response = $next($request);
        $this->addPublicCorsHeaders($response, $origin);
        return $response;
    }

    private function addPublicCorsHeaders(Response $response, string $origin): void
    {
        // Reflect the exact origin the browser sent (or * as last resort)
        $response->headers->set('Access-Control-Allow-Origin', $origin === '*' ? '*' : $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set(
            'Access-Control-Allow-Headers',
            'Content-Type, Accept, Authorization, X-Requested-With'
        );
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->set('Vary', 'Origin');

        // Public endpoint must never advertise credentials
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