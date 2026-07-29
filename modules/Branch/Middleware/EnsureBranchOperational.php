<?php

namespace Modules\Branch\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;
use Symfony\Component\HttpFoundation\Response;

final class EnsureBranchOperational
{
    public function handle(
        Request $request,
        Closure $next
    ): Response|JsonResponse {
        $branch = $request->attributes->get(
            'managed_branch'
        );

        if (!$branch) {
            $user = $request->user();

            $branch = $user
                ? Branch::query()
                    ->where('manager_user_id', $user->id)
                    ->first()
                : null;
        }

        if (!$branch) {
            return response()->json([
                'message' => 'Managed branch not found.',
            ], 403);
        }

        if ($branch->status !== Branch::STATUS_ACTIVE) {
            return response()->json([
                'message' =>
                    'The branch must be active before operational features can be used.',
                'code' => 'BRANCH_NOT_ACTIVE',
                'branch_status' => $branch->status,
            ], 403);
        }

        return $next($request);
    }
}
