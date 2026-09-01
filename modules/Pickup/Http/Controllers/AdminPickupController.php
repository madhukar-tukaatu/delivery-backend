<?php

declare(strict_types=1);

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
use Modules\Shipment\Models\Shipment;

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

    public function index(
        Request $request
    ): JsonResponse {
        $user = $request->user();

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

        $page = max(
            (int) $request->input(
                'page',
                1
            ),
            1
        );

        $search = trim(
            (string) $request->input(
                'search',
                ''
            )
        );

        $status = trim(
            (string) $request->input(
                'status',
                ''
            )
        );

        $query = PickupRequest::query()
            ->with([
                'merchant:id,name',
                'pickupLocation',
                'assignedStaff:id,name,email,phone,branch_id,sub_branch_id',
            ]);

        /*
         * Branch / sub-branch security.
         */
        $this->applyBranchScope(
            query: $query,
            user: $user
        );

        /*
         * Search.
         */
        if ($search !== '') {
            $query->where(
                function (Builder $q) use (
                    $search
                ): void {
                    $q->where(
                        'request_number',
                        'like',
                        '%' . $search . '%'
                    );

                    $q->orWhere(
                        'store_reference',
                        'like',
                        '%' . $search . '%'
                    );

                    $q->orWhere(
                        'pickup_name',
                        'like',
                        '%' . $search . '%'
                    );

                    $q->orWhere(
                        'pickup_phone',
                        'like',
                        '%' . $search . '%'
                    );

                    $q->orWhereHas(
                        'merchant',
                        function (
                            Builder $merchantQuery
                        ) use (
                            $search
                        ): void {
                            $merchantQuery
                                ->where(
                                    'name',
                                    'like',
                                    '%' . $search . '%'
                                )
                                ->orWhere(
                                    'phone',
                                    'like',
                                    '%' . $search . '%'
                                )
                                ->orWhere(
                                    'email',
                                    'like',
                                    '%' . $search . '%'
                                );
                        }
                    );

                    $q->orWhereHas(
                        'pickupLocation',
                        function (
                            Builder $locationQuery
                        ) use (
                            $search
                        ): void {
                            $locationQuery
                                ->where(
                                    'name',
                                    'like',
                                    '%' . $search . '%'
                                )
                                ->orWhere(
                                    'address',
                                    'like',
                                    '%' . $search . '%'
                                )
                                ->orWhere(
                                    'phone',
                                    'like',
                                    '%' . $search . '%'
                                );
                        }
                    );
                }
            );
        }

        /*
         * Status filter.
         */
        if ($status !== '') {
            $query->where(
                'status',
                $status
            );
        }

        $query
            ->orderByDesc(
                'created_at'
            )
            ->orderByDesc(
                'id'
            );

        $paginator = $query->paginate(
            $perPage,
            ['*'],
            'page',
            $page
        );

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
            request: $request,
            pickup: $pickup
        );

        $pickup->load([
            'merchant',
            'pickupLocation',
            'assignedStaff',
            'assignedBy',
            'pickedUpBy',
            'pickupBranch',
            'pickupSubBranch',
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
    | GET /api/v1/admin/pickups/{pickup}/assignable-staff
    |
    */

    public function assignableStaff(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $this->authorizePickup(
            request: $request,
            pickup: $pickup
        );

        $pickupBranchId =
            $this->pickupBranchId(
                $pickup
            );

        $pickupSubBranchId =
            $this->pickupSubBranchId(
                $pickup
            );

        $query = User::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'branch_id',
                'sub_branch_id',
            ])
            ->where(
                'is_active',
                true
            )
            ->whereHas(
                'roles',
                function (
                    Builder $roleQuery
                ): void {
                    $roleQuery->whereIn(
                        'name',
                        [
                            'rider',
                            'pickup_rider',
                            'staff',
                            'delivery_staff',
                        ]
                    );
                }
            );

        /*
         * Branch restriction.
         */
        if (
            $pickupBranchId !== null
        ) {
            $query->where(
                'branch_id',
                $pickupBranchId
            );
        }

        /*
         * Sub-branch restriction when both
         * pickup and staff use sub-branches.
         */
        if (
            $pickupSubBranchId !== null
        ) {
            $query->where(
                function (
                    Builder $q
                ) use (
                    $pickupSubBranchId
                ): void {
                    $q->whereNull(
                        'sub_branch_id'
                    )->orWhere(
                        'sub_branch_id',
                        $pickupSubBranchId
                    );
                }
            );
        }

        $staff = $query
            ->orderBy(
                'name'
            )
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
            request: $request,
            pickup: $pickup
        );

        $validated = $request->validate([
            'staff_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
        ]);

        $staff = $this->resolveAssignableStaff(
            pickup: $pickup,
            staffId: (int) $validated['staff_id']
        );

        $updatedPickup =
            $this->pickupRequestService->assign(
                pickup: $pickup,
                staff: $staff,
                assignedBy: $request->user()
            );

        return $this->pickupResponse(
            pickup: $updatedPickup,
            message: 'Pickup assigned successfully.'
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
            request: $request,
            pickup: $pickup
        );

        $validated = $request->validate([
            'staff_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],

            'remarks' => [
                'required',
                'string',
                'max:2000',
            ],
        ]);

        $staff = $this->resolveAssignableStaff(
            pickup: $pickup,
            staffId: (int) $validated['staff_id']
        );

        $updatedPickup =
            $this->pickupRequestService->transfer(
                pickup: $pickup,
                newStaff: $staff,
                transferredBy: $request->user(),
                reason: $validated['remarks']
            );

        return $this->pickupResponse(
            pickup: $updatedPickup,
            message: 'Pickup transferred successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | START
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/admin/pickups/{pickup}/start
    |
    */

    public function start(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $this->authorizePickup(
            request: $request,
            pickup: $pickup
        );

        $updatedPickup =
            $this->pickupRequestService->start(
                pickup: $pickup,
                user: $request->user()
            );

        return $this->pickupResponse(
            pickup: $updatedPickup,
            message: 'Pickup started successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ARRIVE
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/admin/pickups/{pickup}/arrive
    |
    */

    public function arrive(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $this->authorizePickup(
            request: $request,
            pickup: $pickup
        );

        $updatedPickup =
            $this->pickupRequestService->arrive(
                pickup: $pickup,
                user: $request->user()
            );

        return $this->pickupResponse(
            pickup: $updatedPickup,
            message: 'Rider arrival recorded successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | COLLECT SHIPMENT
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/admin/pickups/{pickup}/shipments/{shipment}/collect
    |
    */

    public function collectShipment(
        Request $request,
        PickupRequest $pickup,
        int $shipment
    ): JsonResponse {
        $this->authorizePickup(
            request: $request,
            pickup: $pickup
        );

        $validated = $request->validate([
            'remarks' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $shipmentModel =
            Shipment::query()
                ->whereKey($shipment)
                ->firstOrFail();

        $item =
            $this->pickupRequestService->collectShipment(
                pickup: $pickup,
                shipment: $shipmentModel,
                user: $request->user(),
                remarks: $validated['remarks'] ?? null
            );

        return ApiResponse::success(
            $item,
            'Shipment collected successfully.'
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
            request: $request,
            pickup: $pickup
        );

        /*
         * IMPORTANT FIX:
         *
         * The service expects Shipment $shipment,
         * not integer $shipment.
         */
        $shipmentModel =
            Shipment::query()
                ->whereKey($shipment)
                ->firstOrFail();

        $item =
            $this->pickupRequestService->receiveShipment(
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
    | COMPLETE
    |--------------------------------------------------------------------------
    |
    | POST /api/v1/admin/pickups/{pickup}/complete
    |
    */

    public function complete(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $this->authorizePickup(
            request: $request,
            pickup: $pickup
        );

        $updatedPickup =
            $this->pickupRequestService->complete(
                pickup: $pickup,
                user: $request->user()
            );

        return $this->pickupResponse(
            pickup: $updatedPickup,
            message: 'Pickup completed successfully.'
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
            request: $request,
            pickup: $pickup
        );

        $validated = $request->validate([
            'reason' => [
                'required',
                'string',
                'max:2000',
            ],
        ]);

        $updatedPickup =
            $this->pickupRequestService->fail(
                pickup: $pickup,
                user: $request->user(),
                reason: $validated['reason']
            );

        return $this->pickupResponse(
            pickup: $updatedPickup,
            message: 'Pickup marked as failed successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE ASSIGNABLE STAFF
    |--------------------------------------------------------------------------
    */

    private function resolveAssignableStaff(
        PickupRequest $pickup,
        int $staffId
    ): User {
        $query = User::query()
            ->whereKey($staffId)
            ->where(
                'is_active',
                true
            )
            ->whereHas(
                'roles',
                function (
                    Builder $roleQuery
                ): void {
                    $roleQuery->whereIn(
                        'name',
                        [
                            'rider',
                            'pickup_rider',
                            'staff',
                            'delivery_staff',
                        ]
                    );
                }
            );

        $pickupBranchId =
            $this->pickupBranchId(
                $pickup
            );

        $pickupSubBranchId =
            $this->pickupSubBranchId(
                $pickup
            );

        if (
            $pickupBranchId !== null
        ) {
            $query->where(
                'branch_id',
                $pickupBranchId
            );
        }

        if (
            $pickupSubBranchId !== null
        ) {
            $query->where(
                function (
                    Builder $q
                ) use (
                    $pickupSubBranchId
                ): void {
                    $q->whereNull(
                        'sub_branch_id'
                    )->orWhere(
                        'sub_branch_id',
                        $pickupSubBranchId
                    );
                }
            );
        }

        $staff = $query->first();

        if (! $staff) {
            abort(
                422,
                'Selected staff member is not eligible for this pickup.'
            );
        }

        return $staff;
    }

    /*
    |--------------------------------------------------------------------------
    | PICKUP RESPONSE
    |--------------------------------------------------------------------------
    */

    private function pickupResponse(
        PickupRequest $pickup,
        string $message
    ): JsonResponse {
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

        return ApiResponse::success(
            $pickup,
            $message
        );
    }

    /*
    |--------------------------------------------------------------------------
    | BRANCH SCOPE
    |--------------------------------------------------------------------------
    */

    private function applyBranchScope(
        Builder $query,
        ?User $user
    ): void {
        if ($user === null) {
            $query->whereRaw(
                '1 = 0'
            );

            return;
        }

        if (
            $this->isGlobalAdmin(
                $user
            )
        ) {
            return;
        }

        /*
         * Branch manager sees their branch.
         */
        if (
            $user->hasRole(
                'branch_manager'
            )
        ) {
            $branchId =
                $this->userBranchId(
                    $user
                );

            if ($branchId === null) {
                $query->whereRaw(
                    '1 = 0'
                );

                return;
            }

            $query->where(
                'pickup_branch_id',
                $branchId
            );

            return;
        }

        /*
         * Sub branch manager sees their sub branch.
         */
        if (
            $user->hasRole(
                'sub_branch_manager'
            )
        ) {
            $subBranchId =
                $this->userSubBranchId(
                    $user
                );

            if ($subBranchId === null) {
                $query->whereRaw(
                    '1 = 0'
                );

                return;
            }

            $query->where(
                'pickup_sub_branch_id',
                $subBranchId
            );

            return;
        }

        /*
         * Normal staff/rider:
         * only own branch.
         */
        $branchId =
            $this->userBranchId(
                $user
            );

        if ($branchId === null) {
            $query->whereRaw(
                '1 = 0'
            );

            return;
        }

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
         * Global administrators.
         */
        if (
            $this->isGlobalAdmin(
                $user
            )
        ) {
            return;
        }

        /*
         * Branch manager.
         */
        if (
            $user->hasRole(
                'branch_manager'
            )
        ) {
            $userBranchId =
                $this->userBranchId(
                    $user
                );

            $pickupBranchId =
                $this->pickupBranchId(
                    $pickup
                );

            if (
                $userBranchId !== null
                &&
                $pickupBranchId !== null
                &&
                $userBranchId ===
                $pickupBranchId
            ) {
                return;
            }
        }

        /*
         * Sub branch manager.
         */
        if (
            $user->hasRole(
                'sub_branch_manager'
            )
        ) {
            $userSubBranchId =
                $this->userSubBranchId(
                    $user
                );

            $pickupSubBranchId =
                $this->pickupSubBranchId(
                    $pickup
                );

            if (
                $userSubBranchId !== null
                &&
                $pickupSubBranchId !== null
                &&
                $userSubBranchId ===
                $pickupSubBranchId
            ) {
                return;
            }
        }

        /*
         * Assigned rider can access own pickup.
         */
        if (
            $pickup->assigned_to !== null
            &&
            (int) $pickup->assigned_to ===
            (int) $user->id
        ) {
            return;
        }

        /*
         * Branch staff.
         */
        $userBranchId =
            $this->userBranchId(
                $user
            );

        $pickupBranchId =
            $this->pickupBranchId(
                $pickup
            );

        if (
            $userBranchId !== null
            &&
            $pickupBranchId !== null
            &&
            $userBranchId ===
            $pickupBranchId
        ) {
            return;
        }

        abort(
            403,
            'You are not authorized to access this pickup.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GLOBAL ADMIN
    |--------------------------------------------------------------------------
    */

    private function isGlobalAdmin(
        User $user
    ): bool {
        if (
            method_exists(
                $user,
                'isSuperAdmin'
            )
            &&
            $user->isSuperAdmin()
        ) {
            return true;
        }

        return $user->hasAnyRole([
            'super_admin',
            'admin',
            'main_admin',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | USER BRANCH
    |--------------------------------------------------------------------------
    */

    private function userBranchId(
        User $user
    ): ?int {
        if (
            $user->branch_id === null
        ) {
            return null;
        }

        return (int) $user->branch_id;
    }

    /*
    |--------------------------------------------------------------------------
    | USER SUB BRANCH
    |--------------------------------------------------------------------------
    */

    private function userSubBranchId(
        User $user
    ): ?int {
        if (
            $user->sub_branch_id === null
        ) {
            return null;
        }

        return (int) $user->sub_branch_id;
    }

    /*
    |--------------------------------------------------------------------------
    | PICKUP BRANCH
    |--------------------------------------------------------------------------
    */

    private function pickupBranchId(
        PickupRequest $pickup
    ): ?int {
        $value =
            $pickup->pickup_branch_id
            ??
            $pickup->branch_id
            ??
            null;

        return $value !== null
            ? (int) $value
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | PICKUP SUB BRANCH
    |--------------------------------------------------------------------------
    */

    private function pickupSubBranchId(
        PickupRequest $pickup
    ): ?int {
        $value =
            $pickup->pickup_sub_branch_id
            ??
            $pickup->sub_branch_id
            ??
            null;

        return $value !== null
            ? (int) $value
            : null;
    }
}