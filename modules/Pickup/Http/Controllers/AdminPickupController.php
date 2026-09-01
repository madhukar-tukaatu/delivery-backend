<?php

declare (strict_types = 1);

namespace Modules\Pickup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Services\PickupRequestService;

final class AdminPickupController extends Controller
{
    public function __construct(
        private readonly PickupRequestService $pickupRequestService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/admin/pickups
    |
    */

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $perPage = min(
            max((int) $request->input('per_page', 20), 1),
            100
        );

        $page = max(
            (int) $request->input('page', 1),
            1
        );

        $search = trim(
            (string) $request->input('search', '')
        );

        $status = trim(
            (string) $request->input('status', '')
        );

        $query = PickupRequest::query()
            ->with([
                'merchant:id,name',
                'pickupLocation',
                'assignedRider:id,name,email,phone,branch_id',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Branch scope
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | Do NOT use users.sub_branch_id here.
        |
        | Pickup visibility is determined from the pickup's own branch fields.
        |
        */

        $this->applyBranchScope(
            $query,
            $user
        );

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {

                $q->where(
                    'request_number',
                    'like',
                    '%' . $search . '%'
                );

                /*
                 * Merchant search.
                 */
                $q->orWhereHas(
                    'merchant',
                    function (Builder $merchantQuery) use ($search): void {
                        $merchantQuery
                            ->where('name', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    }
                );

                /*
                 * Pickup location search.
                 */
                $q->orWhereHas(
                    'pickupLocation',
                    function (Builder $locationQuery) use ($search): void {
                        $locationQuery
                            ->where('name', 'like', '%' . $search . '%')
                            ->orWhere('address', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%');
                    }
                );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Status filter
        |--------------------------------------------------------------------------
        */

        if ($status !== '') {
            $query->where('status', $status);
        }

        /*
        |--------------------------------------------------------------------------
        | Ordering
        |--------------------------------------------------------------------------
        */

        $query
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $paginator = $query->paginate(
            $perPage,
            ['*'],
            'page',
            $page
        );

        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return ApiResponse::success(
            $paginator,
            'Pickup requests retrieved successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SHOW
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/admin/pickups/{pickup}
    |
    */

    public function show(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $this->authorizePickup(
            $request,
            $pickup
        );

        $pickup->load([
            'merchant',
            'pickupLocation',
            'assignedRider',
            'assignedBy',
            'shipments',
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
    | GET /api/v1/admin/pickups/{pickup}/assignable-staff
    |
    */

    public function assignableStaff(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $this->authorizePickup(
            $request,
            $pickup
        );

        /*
        |--------------------------------------------------------------------------
        | Determine pickup branch
        |--------------------------------------------------------------------------
        */

        $pickupBranchId = $this->pickupBranchId(
            $pickup
        );

        /*
        |--------------------------------------------------------------------------
        | Find staff / riders
        |--------------------------------------------------------------------------
        |
        | We deliberately do NOT select sub_branch_id from users.
        |
        */

        $query = User::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'branch_id',
            ])
            ->where('is_active', true)
            ->whereHas('roles', function (Builder $roleQuery): void {
                $roleQuery->whereIn(
                    'name',
                    [
                        'rider',
                        'pickup_rider',
                        'staff',
                    ]
                );
            });

        /*
        |--------------------------------------------------------------------------
        | Branch restriction
        |--------------------------------------------------------------------------
        */

        if ($pickupBranchId !== null) {
            $query->where(
                'branch_id',
                $pickupBranchId
            );
        }

        $staff = $query
            ->orderBy('name')
            ->get();

        return ApiResponse::success(
            $staff,
            'Assignable staff retrieved successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ASSIGN
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/admin/pickups/{pickup}/assign
    |
    */

    public function assign(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $this->authorizePickup(
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
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'branch_id',
            ])
            ->whereKey(
                $validated['staff_id']
            )
            ->where('is_active', true)
            ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Verify staff role
        |--------------------------------------------------------------------------
        */

        if (! $staff->hasAnyRole([
            'rider',
            'pickup_rider',
            'staff',
        ])) {
            return ApiResponse::error(
                'Selected staff member cannot be assigned to a pickup.',
                422
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Verify branch
        |--------------------------------------------------------------------------
        */

        $pickupBranchId = $this->pickupBranchId(
            $pickup
        );

        if (
            $pickupBranchId !== null &&
            (int) $staff->branch_id !== (int) $pickupBranchId
        ) {
            return ApiResponse::error(
                'The selected staff member does not belong to the pickup branch.',
                422
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Assign through service
        |--------------------------------------------------------------------------
        */

        $updatedPickup = DB::transaction(
            function () use (
                $pickup,
                $staff,
                $request
            ): PickupRequest {

                return $this->pickupRequestService->assign(
                    $pickup,
                    $staff,
                    $request->user()
                );
            }
        );

        $updatedPickup->load([
            'merchant',
            'pickupLocation',
            'assignedRider',
            'assignedBy',
            'shipments',
        ]);

        return ApiResponse::success(
            $updatedPickup,
            'Pickup assigned successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TRANSFER
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/admin/pickups/{pickup}/transfer
    |
    */

    public function transfer(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $this->authorizePickup(
            $request,
            $pickup
        );

        $validated = $request->validate([
            'staff_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],

            'remarks'  => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $staff = User::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'branch_id',
            ])
            ->whereKey(
                $validated['staff_id']
            )
            ->where('is_active', true)
            ->firstOrFail();

        if (! $staff->hasAnyRole([
            'rider',
            'pickup_rider',
            'staff',
        ])) {
            return ApiResponse::error(
                'Selected staff member cannot receive a pickup transfer.',
                422
            );
        }

        $pickupBranchId = $this->pickupBranchId(
            $pickup
        );

        if (
            $pickupBranchId !== null &&
            (int) $staff->branch_id !== (int) $pickupBranchId
        ) {
            return ApiResponse::error(
                'The selected staff member does not belong to the pickup branch.',
                422
            );
        }

        $updatedPickup = DB::transaction(
            function () use (
                $pickup,
                $staff,
                $request,
                $validated
            ): PickupRequest {

                return $this->pickupRequestService->transfer(
                    $pickup,
                    $staff,
                    $request->user(),
                    $validated['remarks'] ?? null
                );
            }
        );

        $updatedPickup->load([
            'merchant',
            'pickupLocation',
            'assignedRider',
            'assignedBy',
            'shipments',
        ]);

        return ApiResponse::success(
            $updatedPickup,
            'Pickup transferred successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FAIL
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/admin/pickups/{pickup}/fail
    |
    */

    public function fail(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $this->authorizePickup(
            $request,
            $pickup
        );

        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'max:2000',
            ],
        ]);

        $updatedPickup = DB::transaction(
            function () use (
                $pickup,
                $request,
                $validated
            ): PickupRequest {

                return $this->pickupRequestService->fail(
                    $pickup,
                    $request->user(),
                    $validated['reason']
                );
            }
        );

        $updatedPickup->load([
            'merchant',
            'pickupLocation',
            'assignedRider',
            'assignedBy',
            'shipments',
        ]);

        return ApiResponse::success(
            $updatedPickup,
            'Pickup marked as failed successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RECEIVE SHIPMENT
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/admin/pickups/{pickup}/shipments/{shipment}/receive
    |
    */

    public function receiveShipment(
        Request $request,
        PickupRequest $pickup,
        int $shipment
    ): JsonResponse {
        $this->authorizePickup(
            $request,
            $pickup
        );

        $updatedPickup = DB::transaction(
            function () use (
                $pickup,
                $shipment,
                $request
            ): PickupRequest {

                return $this->pickupRequestService->receiveShipment(
                    $pickup,
                    $shipment,
                    $request->user()
                );
            }
        );

        $updatedPickup->load([
            'merchant',
            'pickupLocation',
            'assignedRider',
            'assignedBy',
            'shipments',
        ]);

        return ApiResponse::success(
            $updatedPickup,
            'Shipment received successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | BRANCH SCOPE
    |--------------------------------------------------------------------------
    |
    | Determine whether the authenticated admin can see the pickup.
    |
    */

    private function applyBranchScope(
        Builder $query,
        ?User $user
    ): void {
        if ($user === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Global administrators
        |--------------------------------------------------------------------------
        */

        if ($this->isGlobalAdmin($user)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Branch users
        |--------------------------------------------------------------------------
        */

        $branchId = $this->userBranchId(
            $user
        );

        if ($branchId === null) {
            /*
             * No branch means no pickup visibility.
             */
            $query->whereRaw('1 = 0');

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Pickup branch
        |--------------------------------------------------------------------------
        |
        | Support the actual pickup branch column used by the application.
        |
        */

        $query->where(
            'pickup_branch_id',
            $branchId
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AUTHORIZE PICKUP
    |--------------------------------------------------------------------------
    */

    private function authorizePickup(
        Request $request,
        PickupRequest $pickup
    ): void {
        $user = $request->user();

        if ($user === null) {
            abort(
                401,
                'Unauthenticated.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Global admin
        |--------------------------------------------------------------------------
        */

        if ($this->isGlobalAdmin($user)) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Branch user
        |--------------------------------------------------------------------------
        */

        $userBranchId = $this->userBranchId(
            $user
        );

        $pickupBranchId = $this->pickupBranchId(
            $pickup
        );

        if (
            $userBranchId === null ||
            $pickupBranchId === null ||
            (int) $userBranchId !== (int) $pickupBranchId
        ) {
            abort(
                403,
                'You are not authorized to access this pickup.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GLOBAL ADMIN CHECK
    |--------------------------------------------------------------------------
    */

    private function isGlobalAdmin(
        User $user
    ): bool {
        return $user->hasAnyRole([
            'super_admin',
            'admin',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | USER BRANCH
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    | Only branch_id is queried.
    |
    | NEVER use:
    |
    | users.sub_branch_id
    |
    */

    private function userBranchId(
        User $user
    ): ?int {
        if ($user->branch_id === null) {
            return null;
        }

        return (int) $user->branch_id;
    }

    /*
    |--------------------------------------------------------------------------
    | PICKUP BRANCH
    |--------------------------------------------------------------------------
    */

    private function pickupBranchId(
        PickupRequest $pickup
    ): ?int {
        if (
            ! isset($pickup->pickup_branch_id) ||
            $pickup->pickup_branch_id === null
        ) {
            return null;
        }

        return (int) $pickup->pickup_branch_id;
    }
}
