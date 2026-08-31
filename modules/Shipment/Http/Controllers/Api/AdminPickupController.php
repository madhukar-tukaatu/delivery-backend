<?php

declare(strict_types=1);

namespace Modules\Shipment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Shipment\Models\Pickup;

final class AdminPickupController extends Controller
{
    public function index(
        Request $request
    ): JsonResponse {
        $branchId =
            $request->attributes->get(
                'branch_id'
            );

        $query = Pickup::query()
            ->with([
                'shipments',
                'assignedStaff',
            ]);

        if ($branchId) {
            $query->where(
                'branch_id',
                $branchId
            );
        }

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->string(
                    'status'
                )->toString()
            );
        }

        $pickups = $query
            ->latest('id')
            ->paginate(
                $request->integer(
                    'per_page',
                    20
                )
            );

        return response()->json([
            'success' => true,
            'data' => $pickups,
        ]);
    }

    public function show(
        Request $request,
        Pickup $pickup
    ): JsonResponse {
        $this->assertBranchAccess(
            $request,
            $pickup
        );

        $pickup->load([
            'shipments',
            'assignedStaff',
        ]);

        return response()->json([
            'success' => true,
            'data' => $pickup,
        ]);
    }

    public function assignableStaff(
        Request $request,
        Pickup $pickup
    ): JsonResponse {
        $this->assertBranchAccess(
            $request,
            $pickup
        );

        $branchId =
            $request->attributes->get(
                'branch_id'
            );

        $staff = User::query()
            ->where(
                'branch_id',
                $branchId
            )
            ->where(
                'is_active',
                true
            )
            ->whereHas(
                'roles',
                function ($query) {
                    $query->whereIn(
                        'name',
                        [
                            'pickup_staff',
                            'rider',
                            'delivery_staff',
                        ]
                    );
                }
            )
            ->with('roles')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $staff,
        ]);
    }

    public function assign(
        Request $request,
        Pickup $pickup
    ): JsonResponse {
        $this->assertBranchAccess(
            $request,
            $pickup
        );

        $validated =
            $request->validate([
                'staff_id' => [
                    'required',
                    'integer',
                ],
            ]);

        $branchId =
            $request->attributes->get(
                'branch_id'
            );

        $staff = User::query()
            ->whereKey(
                $validated['staff_id']
            )
            ->where(
                'branch_id',
                $branchId
            )
            ->where(
                'is_active',
                true
            )
            ->whereHas(
                'roles',
                function ($query) {
                    $query->whereIn(
                        'name',
                        [
                            'pickup_staff',
                            'rider',
                            'delivery_staff',
                        ]
                    );
                }
            )
            ->firstOrFail();

        /*
         * Prevent assigning completed
         * pickups.
         */
        if (
            in_array(
                $pickup->status,
                [
                    'completed',
                    'failed',
                    'cancelled',
                ],
                true
            )
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'This pickup cannot be assigned.',
            ], 422);
        }

        /*
         * Actual column names should match
         * your Pickup model.
         */
        $pickup->assigned_staff_id =
            $staff->id;

        $pickup->status =
            'assigned';

        $pickup->save();

        $pickup->load([
            'shipments',
            'assignedStaff',
        ]);

        return response()->json([
            'success' => true,
            'message' =>
                'Pickup assigned successfully.',
            'data' => $pickup,
        ]);
    }

    public function fail(
        Request $request,
        Pickup $pickup
    ): JsonResponse {
        $this->assertBranchAccess(
            $request,
            $pickup
        );

        $validated =
            $request->validate([
                'reason' => [
                    'required',
                    'string',
                    'max:1000',
                ],
            ]);

        $pickup->status =
            'failed';

        $pickup->failure_reason =
            $validated['reason'];

        $pickup->save();

        return response()->json([
            'success' => true,
            'message' =>
                'Pickup marked as failed.',
            'data' => $pickup,
        ]);
    }

    private function assertBranchAccess(
        Request $request,
        Pickup $pickup
    ): void {
        $branchId =
            $request->attributes->get(
                'branch_id'
            );

        if (
            $branchId &&
            (int) $pickup->branch_id !==
                (int) $branchId
        ) {
            abort(404);
        }
    }
}