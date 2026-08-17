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

        // 1. Public pricing estimate → completely open (any origin)
        if ($this->isPublicPricingPath($request)) {
            if ($request->isMethod('OPTIONS')) {
                return $this->publicPreflightResponse($origin);
            }

            $response = $next($request);
            return $this->addPublicCorsHeaders($response, $origin);
        }

        // 2. No Origin header (server-to-server, Postman, curl, etc.)
        if (!$origin) {
            return $next($request);
        }

        // 3. Origin not in allow-list → just continue (no CORS headers)
        if (!in_array($origin, self::ALLOWED_ORIGINS, true)) {
            return $next($request);
        }

        // 4. Allowed origin
        if ($request->isMethod('OPTIONS')) {
            return $this->normalPreflightResponse($origin);
        }

        $response = $next($request);
        return $this->addNormalCorsHeaders($response, $origin);
    }

    private function isPublicPricingPath(Request $request): bool
    {
        return $request->is('api/v1/public/pricing/estimate')
            || $request->is('api/v1/public/pricing/estimate/*');
    }

    private function publicPreflightResponse(?string $origin): Response
    {
        $response = response('', 204);
        return $this->addPublicCorsHeaders($response, $origin);
    }

    private function normalPreflightResponse(string $origin): Response
    {
        $response = response('', 204);
        return $this->addNormalCorsHeaders($response, $origin);
    }

    private function addPublicCorsHeaders(Response $response, ?string $origin): Response
    {
        // Reflect the exact origin or fall back to *
        $allowOrigin = $origin ?: '*';

        $response->headers->set('Access-Control-Allow-Origin', $allowOrigin);
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set(
            'Access-Control-Allow-Headers',
            'Content-Type, Accept, Authorization, X-Requested-With, X-Api-Key'
        );
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->set('Vary', 'Origin');

        // Never allow credentials on a public open endpoint
        $response->headers->remove('Access-Control-Allow-Credentials');

        return $response;
    }

    private function addNormalCorsHeaders(Response $response, string $origin): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set(
            'Access-Control-Allow-Methods',
            'GET, POST, PUT, PATCH, DELETE, OPTIONS'
        );
        $response->headers->set(
            'Access-Control-Allow-Headers',
            'Content-Type, Accept, Authorization, X-Requested-With, X-Api-Key, X-Tukaatu-Marketplace-Key, X-Tukaatu-Timestamp, X-Tukaatu-Request-Id, X-Tukaatu-Signature'
        );
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Max-Age', '86400');
        $response->headers->set('Vary', 'Origin');

        return $response;
    }
}