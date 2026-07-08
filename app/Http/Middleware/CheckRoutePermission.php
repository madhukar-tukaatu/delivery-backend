<?php

namespace App\Http\Middleware;

use App\Support\RoutePermissionMapper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRoutePermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return $next($request);
        }

        $routeName = optional($request->route())->getName();
        $permission = RoutePermissionMapper::fromRouteName($routeName);

        if (!$permission) {
            return response()->json([
                'success' => false,
                'message' => 'Route permission is not defined. Add a route name like admin.shipments.index or remove route.permission middleware.',
                'route_name' => $routeName,
            ], 403);
        }

        if ($user->can($permission)) {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'message' => 'Missing required permission: '.$permission,
        ], 403);
    }
}
