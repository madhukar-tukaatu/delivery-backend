<?php

declare(strict_types=1);

namespace Modules\Pickup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Services\PickupRequestService;
use Throwable;

final class AdminPickupController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    */

    public function index(Request $request): JsonResponse
    {
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
        |--------------------------------------------------------------------------
        | BRANCH SCOPE
        |--------------------------------------------------------------------------
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
        |--------------------------------------------------------------------------
        | GLOBAL ADMIN BRANCH FILTER
        |--------------------------------------------------------------------------
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
        |--------------------------------------------------------------------------
        | SUB BRANCH FILTER
        |--------------------------------------------------------------------------
        */

        if ($request->filled('sub_branch_id')) {
            $subBranchId = (int) $request->input('sub_branch_id');

            $query->where(function ($q) use ($subBranchId): void {
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
        |--------------------------------------------------------------------------
        | STATUS
        |--------------------------------------------------------------------------
        */

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->string('status')->toString()
            );
        }

        /*
        |--------------------------------------------------------------------------
        | MERCHANT
        |--------------------------------------------------------------------------
        */

        if ($request->filled('merchant_id')) {
            $query->where(
                'merchant_id',
                (int) $request->input('merchant_id')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | PICKUP LOCATION
        |--------------------------------------------------------------------------
        */

        if ($request->filled('pickup_location_id')) {
            $query->where(
                'pickup_location_id',
                (int) $request->input('pickup_location_id')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | RIDER
        |--------------------------------------------------------------------------
        */

        if ($request->filled('assigned_to')) {
            $query->where(
                'assigned_to',
                (int) $request->input('assigned_to')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | SEARCH
        |--------------------------------------------------------------------------
        */

        $search = trim(
            (string) $request->input(
                'search',
                $request->input('q', '')
            )
        );

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
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

        /*
        |--------------------------------------------------------------------------
        | PAGINATION
        |--------------------------------------------------------------------------
        */

        $perPage = min(
            max(
                (int) $request->input('per_page', 20),
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
    |
    | This is now a real rider query.
    |
    */

    public function assignableStaff(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $this->authorizeManagement(
            $request,
            $pickup
        );

        /*
        |--------------------------------------------------------------------------
        | Determine pickup branch
        |--------------------------------------------------------------------------
        */

        $branchId = (int) (
            $pickup->pickup_branch_id
            ??
            $pickup->branch_id
        );

        $subBranchId = (int) (
            $pickup->pickup_sub_branch_id
            ??
            $pickup->sub_branch_id
        );

        /*
        |--------------------------------------------------------------------------
        | Get active users
        |--------------------------------------------------------------------------
        |
        | We deliberately query users instead of returning only the currently
        | assigned rider.
        |
        */

        $staffQuery = User::query()
            ->where('id', '!=', 0);

        /*
        |--------------------------------------------------------------------------
        | Active
        |--------------------------------------------------------------------------
        */

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
        |--------------------------------------------------------------------------
        | Role
        |--------------------------------------------------------------------------
        |
        | Adjust these role names only if your application uses different
        | Spatie roles.
        |
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
        |--------------------------------------------------------------------------
        | Branch
        |--------------------------------------------------------------------------
        */

        if ($branchId > 0) {
            $staffQuery->where(
                'branch_id',
                $branchId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Sub branch
        |--------------------------------------------------------------------------
        |
        | If pickup has a sub branch, prefer riders belonging to that
        | sub-branch.
        |
        */

        if (
            $subBranchId > 0
            &&
            DB::getSchemaBuilder()
                ->hasColumn('users', 'sub_branch_id')
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
                'id' => $pickup->id,
                'request_number' => $pickup->request_number,
                'status' => $pickup->status,
                'pickup_branch_id' => $pickup->pickup_branch_id,
                'pickup_sub_branch_id' => $pickup->pickup_sub_branch_id,
            ],
            'staff' => $staff,
            'assigned_staff' => $pickup->assignedStaff,
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
    | FAIL
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
            'Pickup marked as failed successfully.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | BRANCH SCOPE
    |--------------------------------------------------------------------------
    */

    private function applyBranchScope(
        $query,
        int $branchId
    ): void {
        $query->where(function ($q) use ($branchId): void {
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
    | MANAGEMENT AUTHORIZATION
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

        /*
        |--------------------------------------------------------------------------
        | Super admin / main admin
        |--------------------------------------------------------------------------
        */

        if ($this->isGlobalAdmin($user)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Branch manager
        |--------------------------------------------------------------------------
        */

        if (
            $user->hasRole('branch_manager')
            ||
            $user->hasRole('sub_branch_manager')
        ) {
            $branchId = $this->resolveUserBranchId($user);

            $allowed =
                $branchId > 0
                &&
                (
                    $branchId === (int) $pickup->pickup_branch_id
                    ||
                    $branchId === (int) $pickup->pickup_sub_branch_id
                    ||
                    $branchId === (int) $pickup->branch_id
                    ||
                    $branchId === (int) $pickup->sub_branch_id
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
    | GLOBAL ADMIN
    |--------------------------------------------------------------------------
    */

    private function isGlobalAdmin($user): bool
    {
        return
            $user->isSuperAdmin()
            ||
            $user->hasRole('main_admin');
    }


    /*
    |--------------------------------------------------------------------------
    | USER BRANCH
    |--------------------------------------------------------------------------
    */

    private function resolveUserBranchId($user): int
    {
        return (int) (
            $user->branch_id
            ??
            $user->sub_branch_id
            ??
            0
        );
    }
}