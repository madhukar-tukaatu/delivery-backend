<?php

namespace Modules\Branch\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Branch\Models\Branch;

final class BranchManagerPortalController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Branch|null $branch */
        $branch = $request->attributes->get(
            'managed_branch'
        );

        if (!$branch) {
            return response()->json([
                'message' => 'Managed branch not found.',
            ], 404);
        }

        $branch->loadMissing([
            'coverageLocation',
            'parent',
            'manager:id,name,email,phone,username,account_setup_completed_at',
        ]);

        $operationsEnabled =
            $branch->status === Branch::STATUS_ACTIVE;

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'username' => $request->user()->username,
                    'role' => $request->user()->role,
                ],

                'branch' => $branch,

                'access' => [
                    'level' => $operationsEnabled
                        ? 'operational'
                        : 'onboarding',

                    'operations_enabled' => $operationsEnabled,
                    'branch_status' => $branch->status,
                ],

                'next_steps' => $operationsEnabled
                    ? [
                        'Manage branch shipments and operations.',
                        'Configure staff access and assignments.',
                        'Review pickups, dispatches, deliveries, and reports.',
                    ]
                    : [
                        'Complete all missing branch and office information.',
                        'Upload or review required documents and agreements.',
                        'Wait for admin activation before starting live operations.',
                    ],
            ],
        ]);
    }
}
