<?php

namespace Modules\Branch\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;
use Symfony\Component\HttpFoundation\Response;

final class EnsureBranchManagerPortalAccess
{
    public function handle(
        Request $request,
        Closure $next
    ): Response|JsonResponse {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$this->hasManagerRole($user)) {
            return response()->json([
                'message' =>
                    'Only a branch manager can access this portal.',
            ], 403);
        }

        if (blank($user->account_setup_completed_at)) {
            return response()->json([
                'message' =>
                    'Complete the account setup process before accessing the branch manager portal.',
                'code' => 'ACCOUNT_SETUP_REQUIRED',
            ], 403);
        }

        $branch = Branch::query()
            ->where('manager_user_id', $user->id)
            ->first();

        if (
            !$branch &&
            isset($user->branch_id) &&
            $user->branch_id
        ) {
            $branch = Branch::query()->find(
                $user->branch_id
            );
        }

        if (!$branch) {
            return response()->json([
                'message' =>
                    'No branch is assigned to this manager account.',
                'code' => 'BRANCH_NOT_ASSIGNED',
            ], 403);
        }

        if (
            !in_array(
                $branch->status,
                [
                    Branch::STATUS_APPROVED,
                    Branch::STATUS_ACTIVE,
                ],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'The branch manager portal is unavailable for the current branch status.',
                'code' => 'BRANCH_PORTAL_UNAVAILABLE',
                'branch_status' => $branch->status,
            ], 403);
        }

        $request->attributes->set(
            'managed_branch',
            $branch
        );

        return $next($request);
    }

    private function hasManagerRole(object $user): bool
    {
        $allowed = [
            'branch_manager',
            'sub_branch_manager',
        ];

        if (method_exists($user, 'hasAnyRole')) {
            return $user->hasAnyRole($allowed);
        }

        if (
            isset($user->role) &&
            in_array((string) $user->role, $allowed, true)
        ) {
            return true;
        }

        if (isset($user->roles)) {
            return collect($user->roles)
                ->map(static function ($role): string {
                    if (is_string($role)) {
                        return $role;
                    }

                    return (string) ($role->name ?? '');
                })
                ->intersect($allowed)
                ->isNotEmpty();
        }

        return false;
    }
}
