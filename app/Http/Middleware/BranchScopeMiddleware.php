<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BranchScopeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) {
            return $next($request);
        }

        if ($user->merchant_id) {
            $request->merge(['_scope_merchant_id' => $user->merchant_id]);
            return $next($request);
        }

        if ($user->branch_id) {
            $request->merge(['_scope_branch_id' => $user->branch_id]);
        }

        return $next($request);
    }
}
