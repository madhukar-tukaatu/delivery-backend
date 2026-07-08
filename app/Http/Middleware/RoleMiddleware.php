<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string $roles): Response
    {
        $user = $request->user();
        $allowedRoles = array_filter(explode('|', $roles));

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        $hasRole = in_array($user->role, $allowedRoles, true);

        try {
            $hasRole = $hasRole || $user->hasAnyRole($allowedRoles);
        } catch (\Throwable $e) {
            // Keep legacy role-column fallback.
        }

        if (!$hasRole) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to access this resource.',
            ], 403);
        }

        return $next($request);
    }
}
