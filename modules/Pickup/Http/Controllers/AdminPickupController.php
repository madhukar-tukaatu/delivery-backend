<?php

declare(strict_types=1);

namespace Modules\Pickup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Services\PickupRequestService;

final class AdminPickupController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    */

    public function index(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        $query = PickupRequest::query()
            ->with([
                'merchant',
                'branch',
                'subBranch',
                'pickupBranch',
                'pickupSubBranch',
                'pickupLocation',
                'assignedStaff',
                'assignedBy',
                'pickedUpBy',
                'shipments.shipment',
            ])
            ->latest('id');

        /*
         * Global admin can see everything.
         */
        if (! $this->isGlobalAdmin($user)) {
            $branchId = $this->resolveUserBranchId($user);

            if ($branchId <= 0) {
                return ApiResponse::success([
                    'data' => [],
                    'current_page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                ]);
            }

            $this->applyBranchScope(
                $query,
                $branchId
            );
        }

        /*
         * Global admin branch filter.
         */
        if (
            $this->isGlobalAdmin($user)
            && $request->filled('branch_id')
        ) {
            $this->applyBranchScope(
                $query,
                (int) $request->input('branch_id')
            );
        }

        /*
         * Sub branch.
         */
        if ($request->filled('sub_branch_id')) {
            $subBranchId =
                (int) $request->input('sub_branch_id');

            $query->where(function ($q) use (
                $subBranchId
            ): void {
                $q->where(
                    'pickup_sub_branch_id',
                    $subBranchId
                )->orWhere(
                    'sub_branch_id',
                    $subBranchId
                );
            });
        }

        /*
         * Status.
         */
        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->string('status')->toString()
            );
        }

        /*
         * Merchant.
         */
        if ($request->filled('merchant_id')) {
            $query->where(
                'merchant_id',
                (int) $request->input('merchant_id')
            );
        }

        /*
         * Pickup location.
         */
        if ($request->filled('pickup_location_id')) {
            $query->where(
                'pickup_location_id',
                (int) $request->input('pickup_location_id')
            );
        }

        /*
         * Assigned rider.
         */
        if ($request->filled('assigned_to')) {
            $query->where(
                'assigned_to',
                (int) $request->input('assigned_to')
            );
        }

        /*
         * Search.
         */
        $search = trim(
            (string) $request->input(
                'search',
                $request->input('q', '')
            )
        );

        if ($search !== '') {
            $query->where(function ($q) use (
                $search
            ): void {
                $q->where(
                    'request_number',
                    'like',
                    "%{$search}%"
                )
                    ->orWhere(
                        'store_reference',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'pickup_name',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'pickup_phone',
                        'like',
                        "%{$search}%"
                    );
            });
        }

        $perPage = min(
            max(
                (int) $request->input(
                    'per_page',
                    20
                ),
                1
            ),
            100
        );

        $pickups = $query
            ->paginate($perPage)
            ->appends($request->query());

        return ApiResponse::success($pickups);
    }

    /*
    |--------------------------------------------------------------------------
    | SHOW
    |--------------------------------------------------------------------------
    */

    public function show(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $this->authorizeManagement(
            $request,
            $pickup
        );

        $pickup->load([
            'merchant',
            'branch',
            'subBranch',
            'pickupBranch',
            'pickupSubBranch',
            'pickupLocation',
            'assignedStaff',
            'assignedBy',
            'pickedUpBy',
            'shipments.shipment',
        ]);

        return ApiResponse::success(
            $pickup,
            'Pickup request retrieved successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ASSIGNABLE STAFF
    |--------------------------------------------------------------------------
    */

    public function assignableStaff(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $this->authorizeManagement(
            $request,
            $pickup
        );

        $branchId = (int) (
            $pickup->pickup_branch_id
            ??
            $pickup->branch_id
            ??
            0
        );

        $subBranchId = (int) (
            $pickup->pickup_sub_branch_id
            ??
            $pickup->sub_branch_id
            ??
            0
        );

        $staffQuery = User::query();

        if (
            DB::getSchemaBuilder()
                ->hasColumn('users', 'status')
        ) {
            $staffQuery->where(
                'status',
                'active'
            );
        }

        /*
         * Only eligible pickup staff.
         */
        $staffQuery->whereHas(
            'roles',
            function ($query): void {
                $query->whereIn(
                    'name',
                    [
                        'rider',
                        'staff',
                        'delivery_staff',
                    ]
                );
            }
        );

        /*
         * Branch scope.
         */
        if ($branchId > 0) {
            $staffQuery->where(
                'branch_id',
                $branchId
            );
        }

        /*
         * Sub branch scope if available.
         */
        if (
            $subBranchId > 0
            &&
            DB::getSchemaBuilder()
                ->hasColumn(
                    'users',
                    'sub_branch_id'
                )
        ) {
            $staffQuery->where(
                'sub_branch_id',
                $subBranchId
            );
        }

        $staff = $staffQuery
            ->select([
                'id',
                'name',
                'email',
                'branch_id',
                'sub_branch_id',
            ])
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'pickup' => [
                'id' =>
                    $pickup->id,

                'request_number' =>
                    $pickup->request_number,

                'status' =>
                    $pickup->status,

                'pickup_branch_id' =>
                    $pickup->pickup_branch_id,

                'pickup_sub_branch_id' =>
                    $pickup->pickup_sub_branch_id,
            ],

            'staff' =>
                $staff,

            'assigned_staff' =>
                $pickup->assignedStaff,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ASSIGN
    |--------------------------------------------------------------------------
    */

    public function assign(
        Request $request,
        PickupRequest $pickup,
        PickupRequestService $service
    ): JsonResponse {
        $this->authorizeManagement(
            $request,
            $pickup
        );

        $validated = $request->validate([
            'staff_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
        ]);

        $staff = User::query()
            ->findOrFail(
                (int) $validated['staff_id']
            );

        $pickup = $service->assign(
            pickup: $pickup,
            staff: $staff,
            assignedBy: $request->user()
        );

        return ApiResponse::success(
            $pickup,
            'Pickup rider assigned successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TRANSFER
    |--------------------------------------------------------------------------
    */

    public function transfer(
        Request $request,
        PickupRequest $pickup,
        PickupRequestService $service
    ): JsonResponse {
        $this->authorizeManagement(
            $request,
            $pickup
        );

        $validated = $request->validate([
            'staff_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],

            'reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        $staff = User::query()
            ->findOrFail(
                (int) $validated['staff_id']
            );

        $pickup = $service->transfer(
            pickup: $pickup,
            newStaff: $staff,
            transferredBy: $request->user(),
            reason: $validated['reason']
        );

        return ApiResponse::success(
            $pickup,
            'Pickup transferred successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FAIL / CANCEL
    |--------------------------------------------------------------------------
    */

    public function fail(
        Request $request,
        PickupRequest $pickup,
        PickupRequestService $service
    ): JsonResponse {
        $this->authorizeManagement(
            $request,
            $pickup
        );

        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        $pickup = $service->fail(
            pickup: $pickup,
            user: $request->user(),
            reason: $validated['reason']
        );

        return ApiResponse::success(
            $pickup,
            'Pickup cancelled successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RECEIVE SHIPMENT
    |--------------------------------------------------------------------------
    */

    public function receiveShipment(
        Request $request,
        PickupRequest $pickup,
        $shipment,
        PickupRequestService $service
    ): JsonResponse {
        $this->authorizeManagement(
            $request,
            $pickup
        );

        $shipmentModel =
            \Modules\Shipment\Models\Shipment::query()
                ->findOrFail((int) $shipment);

        $item = $service->receiveShipment(
            pickup: $pickup,
            shipment: $shipmentModel,
            staff: $request->user()
        );

        return ApiResponse::success(
            $item,
            'Shipment received at origin branch successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Branch scope
    |--------------------------------------------------------------------------
    */

    private function applyBranchScope(
        $query,
        int $branchId
    ): void {
        $query->where(function ($q) use (
            $branchId
        ): void {
            $q->where(
                'pickup_branch_id',
                $branchId
            )
                ->orWhere(
                    'pickup_sub_branch_id',
                    $branchId
                )
                ->orWhere(
                    'branch_id',
                    $branchId
                )
                ->orWhere(
                    'sub_branch_id',
                    $branchId
                );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    private function authorizeManagement(
        Request $request,
        PickupRequest $pickup
    ): void {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($this->isGlobalAdmin($user)) {
            return;
        }

        if (
            $user->hasRole('branch_manager')
            ||
            $user->hasRole('sub_branch_manager')
        ) {
            $branchId =
                $this->resolveUserBranchId($user);

            $allowed =
                $branchId > 0
                &&
                in_array(
                    $branchId,
                    [
                        (int) $pickup->pickup_branch_id,
                        (int) $pickup->pickup_sub_branch_id,
                        (int) $pickup->branch_id,
                        (int) $pickup->sub_branch_id,
                    ],
                    true
                );

            abort_unless(
                $allowed,
                403,
                'You are not allowed to manage this pickup.'
            );

            return;
        }

        abort(
            403,
            'You are not allowed to manage pickups.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Global admin
    |--------------------------------------------------------------------------
    */

    private function isGlobalAdmin(
        $user
    ): bool {
        return
            $user->isSuperAdmin()
            ||
            $user->hasRole('main_admin');
    }

    /*
    |--------------------------------------------------------------------------
    | User branch
    |--------------------------------------------------------------------------
    */

    private function resolveUserBranchId(
        $user
    ): int {
        return (int) (
            $user->branch_id
            ??
            $user->sub_branch_id
            ??
            0
        );
    }
}