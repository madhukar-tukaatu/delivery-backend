<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();
        $required = array_filter(explode('|', $permissions));

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        foreach ($required as $permission) {
            try {
                if ($user->can($permission)) {
                    return $next($request);
                }
            } catch (\Throwable $e) {
                // Continue and deny below.
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Missing required permission: '.implode(' or ', $required),
        ], 403);
    }
}
