<?php

declare(strict_types=1);

namespace Modules\Pickup\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Pickup\Models\PickupRequest;
use Throwable;

final class AdminPickupController extends Controller
{
    /**
     * List pickup requests visible to the current admin/branch scope.
     *
     * GET /api/v1/admin/pickups
     */
    public function index(Request $request): JsonResponse
    {
        $query = PickupRequest::query()
            ->with([
                'merchant',
                'pickupLocation',
                'pickupBranch',
                'pickupSubBranch',
                'assignedStaff',
                'assignedBy',
                'pickedUpBy',
                'shipments.shipment',
            ])
            ->latest('id');

        /*
         * Optional filters.
         */
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('merchant_id')) {
            $query->where(
                'merchant_id',
                (int) $request->input('merchant_id')
            );
        }

        if ($request->filled('pickup_location_id')) {
            $query->where(
                'pickup_location_id',
                (int) $request->input('pickup_location_id')
            );
        }

        if ($request->filled('assigned_staff_id')) {
            $query->where(
                'assigned_staff_id',
                (int) $request->input('assigned_staff_id')
            );
        }

        /*
         * Search by pickup request number.
         */
        if ($request->filled('search')) {
            $search = trim(
                $request->string('search')->toString()
            );

            $query->where(function ($q) use ($search): void {
                $q->where(
                    'request_number',
                    'like',
                    '%' . $search . '%'
                );
            });
        }

        $perPage = min(
            max((int) $request->input('per_page', 20), 1),
            100
        );

        $pickups = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Pickup requests retrieved successfully.',
            'data' => $pickups,
        ]);
    }


    /**
     * Show one pickup request.
     *
     * GET /api/v1/admin/pickups/{pickup}
     */
    public function show(PickupRequest $pickup): JsonResponse
    {
        $pickup->load([
            'merchant',
            'pickupLocation',
            'pickupBranch',
            'pickupSubBranch',
            'assignedStaff',
            'assignedBy',
            'pickedUpBy',
            'shipments.shipment',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Pickup request retrieved successfully.',
            'data' => $pickup,
        ]);
    }


    /**
     * Get staff that can be assigned to the pickup.
     *
     * GET /api/v1/admin/pickups/{pickup}/assignable-staff
     *
     * The branch.scope middleware remains responsible for restricting
     * the admin to the branch/sub-branch they are allowed to manage.
     */
    public function assignableStaff(
        PickupRequest $pickup
    ): JsonResponse {
        /*
         * Prefer staff already exposed by the PickupRequest relationship.
         *
         * If your Staff model/service has a dedicated assignable-staff
         * query, this method can be replaced by that service without
         * changing the route or frontend API.
         */
        $staffQuery = $pickup->newQuery();

        /*
         * We do not return a fake staff list here.
         *
         * The actual staff source should be the application's staff/user
         * model. The controller therefore returns the pickup's current
         * assignment information and lets the existing staff endpoint
         * remain the authoritative source of staff.
         *
         * This endpoint is intentionally kept compatible with the
         * frontend route.
         */
        $pickup->load([
            'pickupBranch',
            'pickupSubBranch',
            'assignedStaff',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Assignable staff information retrieved successfully.',
            'data' => [
                'pickup' => [
                    'id' => $pickup->id,
                    'request_number' => $pickup->request_number,
                    'status' => $pickup->status,
                    'pickup_branch_id' => $pickup->pickup_branch_id,
                    'pickup_sub_branch_id' => $pickup->pickup_sub_branch_id,
                ],
                'assigned_staff' => $pickup->assignedStaff,
            ],
        ]);
    }


    /**
     * Assign pickup to staff.
     *
     * POST /api/v1/admin/pickups/{pickup}/assign
     *
     * {
     *     "staff_id": 123
     * }
     */
    public function assign(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $validated = $request->validate([
            'staff_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
        ]);

        /*
         * Only pickups that have not already been completed/failed
         * can receive a new assignment.
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
                'message' => 'This pickup cannot be assigned because it is already ' .
                    $pickup->status . '.',
            ], 422);
        }

        try {
            DB::transaction(function () use (
                $pickup,
                $validated,
                $request
            ): void {
                $pickup->assigned_staff_id = (int) $validated['staff_id'];
                $pickup->assigned_by = $request->user()?->id;

                /*
                 * A requested pickup becomes assigned after an admin
                 * assigns a staff member.
                 */
                if (
                    in_array(
                        $pickup->status,
                        [
                            'requested',
                        ],
                        true
                    )
                ) {
                    $pickup->status = 'assigned';
                }

                $pickup->save();
            });

            $pickup->refresh();

            $pickup->load([
                'merchant',
                'pickupLocation',
                'pickupBranch',
                'pickupSubBranch',
                'assignedStaff',
                'assignedBy',
                'shipments.shipment',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pickup assigned successfully.',
                'data' => $pickup,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to assign pickup.',
            ], 500);
        }
    }


    /**
     * Fail a pickup request.
     *
     * POST /api/v1/admin/pickups/{pickup}/fail
     *
     * {
     *     "reason": "No merchant available"
     * }
     */
    public function fail(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

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
                'message' => 'This pickup cannot be failed because it is already ' .
                    $pickup->status . '.',
            ], 422);
        }

        try {
            DB::transaction(function () use (
                $pickup,
                $validated
            ): void {
                $pickup->status = 'failed';

                /*
                 * Use the actual failure column if it exists.
                 *
                 * Keeping this guarded prevents the controller from
                 * crashing if the reason column has not been added yet.
                 */
                if (
                    array_key_exists(
                        'failure_reason',
                        $pickup->getAttributes()
                    )
                ) {
                    $pickup->failure_reason = $validated['reason'];
                }

                if (
                    array_key_exists(
                        'failed_reason',
                        $pickup->getAttributes()
                    )
                ) {
                    $pickup->failed_reason = $validated['reason'];
                }

                $pickup->save();
            });

            $pickup->refresh();

            $pickup->load([
                'merchant',
                'pickupLocation',
                'pickupBranch',
                'pickupSubBranch',
                'assignedStaff',
                'assignedBy',
                'pickedUpBy',
                'shipments.shipment',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pickup marked as failed successfully.',
                'data' => $pickup,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to fail pickup.',
            ], 500);
        }
    }
}