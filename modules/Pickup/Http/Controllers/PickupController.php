<?php

declare(strict_types=1);

namespace Modules\Pickup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Pickup\Http\Requests\AddShipmentToPickupRequest;
use Modules\Pickup\Http\Requests\AssignPickupRequest;
use Modules\Pickup\Http\Requests\CollectShipmentRequest;
use Modules\Pickup\Http\Requests\FailPickupRequest;
use Modules\Pickup\Models\PickupRequest as PickupRequestModel;
use Modules\Pickup\Services\PickupRequestService;
use Modules\Shipment\Models\Shipment;

final class PickupController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    |
    | Visibility rules:
    |
    | Super Admin:
    |   - sees all pickups
    |   - may filter by branch_id
    |
    | Main Admin:
    |   - sees all pickups
    |   - may filter by branch_id
    |
    | Branch Manager:
    |   - only sees pickups belonging to their branch/sub-branch scope
    |
    | Sub Branch Manager:
    |   - only sees pickups belonging to their branch/sub-branch scope
    |
    | Merchant:
    |   - only sees their own pickups
    |
    | Rider / Staff:
    |   - only sees pickups assigned to them
    |
    */

    public function index(Request $request)
    {
        $user = $request->user();

        $query = PickupRequestModel::query()
            ->with([
                'merchant',
                'branch',
                'subBranch',
                'pickupBranch',
                'pickupSubBranch',
                'pickupLocation',
                'assignedStaff',
                'shipments.shipment',
            ]);

        /*
        |--------------------------------------------------------------------------
        | ACCESS SCOPE
        |--------------------------------------------------------------------------
        */

        if ($this->isGlobalAdmin($user)) {

            /*
             * Super admin/main admin:
             *
             * No restriction unless branch_id was explicitly
             * selected from the frontend.
             *
             * Example:
             *
             * GET /api/v1/admin/pickups?branch_id=185
             */
            if ($request->filled('branch_id')) {

                $this->applyBranchFilter(
                    $query,
                    (int) $request->input('branch_id')
                );
            }
        }

        elseif ($user->hasRole('merchant')) {

            /*
             * Merchant can only see its own pickups.
             */

            if (! $user->merchant_id) {

                return ApiResponse::success([
                    'data' => [],
                    'current_page' => 1,
                    'per_page' => (int) $request->get('per_page', 20),
                    'total' => 0,
                ]);
            }

            $query->where(
                'merchant_id',
                (int) $user->merchant_id
            );
        }

        elseif (
            $user->hasRole('branch_manager')
            ||
            $user->hasRole('sub_branch_manager')
        ) {

            /*
             * Branch users must NEVER be allowed to choose
             * an arbitrary branch from the frontend.
             *
             * Their branch comes from the authenticated user
             * / branch.scope middleware.
             */

            $branchId = $this->resolveUserBranchId($request, $user);

            if (! $branchId) {

                return ApiResponse::success([
                    'data' => [],
                    'current_page' => 1,
                    'per_page' => (int) $request->get('per_page', 20),
                    'total' => 0,
                ]);
            }

            $this->applyBranchFilter(
                $query,
                $branchId
            );
        }

        else {

            /*
             * Rider / staff:
             *
             * Only pickups assigned to the authenticated user.
             */

            $query->where(
                'assigned_to',
                (int) $user->id
            );
        }

        /*
        |--------------------------------------------------------------------------
        | STATUS FILTER
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
        | SEARCH
        |--------------------------------------------------------------------------
        |
        | Supports:
        |
        | request number
        | pickup name
        | pickup phone
        | merchant order / shipment tracking through relationship
        |
        */

        if ($request->filled('search')) {

            $search = trim(
                $request->string('search')->toString()
            );

            if ($search !== '') {

                $query->where(function (Builder $q) use ($search) {

                    $q->where(
                        'request_number',
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
                    )

                    /*
                     * Search attached shipments.
                     *
                     * This assumes PickupRequest has:
                     *
                     * shipments()
                     *
                     * and the pivot/model points to Shipment.
                     */
                    ->orWhereHas(
                        'shipments.shipment',
                        function (Builder $shipmentQuery) use ($search) {

                            $shipmentQuery
                                ->where(
                                    'tracking_number',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'merchant_order_id',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'receiver_name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'receiver_phone',
                                    'like',
                                    "%{$search}%"
                                );
                        }
                    );
                });
            }
        }

        /*
        |--------------------------------------------------------------------------
        | MERCHANT FILTER
        |--------------------------------------------------------------------------
        |
        | Only global admins can use this filter.
        |
        */

        if (
            $request->filled('merchant_id')
            &&
            $this->isGlobalAdmin($user)
        ) {

            $query->where(
                'merchant_id',
                (int) $request->input('merchant_id')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | ASSIGNED STAFF FILTER
        |--------------------------------------------------------------------------
        |
        | Useful for branch dashboards.
        |
        */

        if (
            $request->filled('assigned_to')
            &&
            $this->isGlobalAdmin($user)
        ) {

            $query->where(
                'assigned_to',
                (int) $request->input('assigned_to')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | PICKUP LOCATION FILTER
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
        | DATE FILTER
        |--------------------------------------------------------------------------
        */

        if ($request->filled('date')) {

            $query->whereDate(
                'created_at',
                $request->input('date')
            );
        }

        if ($request->filled('from_date')) {

            $query->whereDate(
                'created_at',
                '>=',
                $request->input('from_date')
            );
        }

        if ($request->filled('to_date')) {

            $query->whereDate(
                'created_at',
                '<=',
                $request->input('to_date')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | PAGINATION
        |--------------------------------------------------------------------------
        */

        $perPage = min(
            max(
                (int) $request->get('per_page', 20),
                1
            ),
            100
        );

        $pickups = $query
            ->latest('id')
            ->paginate($perPage)
            ->appends(
                $request->query()
            );

        return ApiResponse::success(
            $pickups
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SHOW
    |--------------------------------------------------------------------------
    */

    public function show(
        Request $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {

        $this->authorizePickupView(
            $request,
            $pickup
        );

        return ApiResponse::success(
            $service->get($pickup)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MANUAL SHIPMENT ATTACHMENT
    |--------------------------------------------------------------------------
    */

    public function addShipment(
        AddShipmentToPickupRequest $request,
        PickupRequestService $service
    ) {

        $shipment = Shipment::query()
            ->whereKey(
                $request->validated('shipment_id')
            )
            ->firstOrFail();

        $this->authorizeMerchantShipment(
            $request,
            $shipment
        );

        $item = $service->attachShipment(
            shipment: $shipment,
            userId: $request->user()->id,
            remarks: $request->validated('remarks')
        );

        return ApiResponse::success(
            $item->load([
                'pickupRequest',
                'shipment',
            ]),
            'Shipment added to pickup request successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ASSIGN
    |--------------------------------------------------------------------------
    */

    public function assign(
        AssignPickupRequest $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {

        $this->authorizeManagement(
            $request,
            $pickup
        );

        $staff = User::query()
            ->findOrFail(
                $request->validated('staff_id')
            );

        /*
         * Staff must belong to the same operational branch
         * as the pickup.
         *
         * Global admin can still assign across branches only
         * if the staff branch matches the pickup branch.
         */

        $this->authorizeStaffAssignment(
            $pickup,
            $staff
        );

        $pickup = $service->assign(
            pickup: $pickup,
            staff: $staff,
            assignedBy: $request->user()
        );

        return ApiResponse::success(
            $pickup->load([
                'merchant',
                'branch',
                'subBranch',
                'pickupBranch',
                'pickupSubBranch',
                'assignedStaff',
            ]),
            'Pickup rider assigned successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | START
    |--------------------------------------------------------------------------
    */

    public function start(
        Request $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {

        $this->authorizePickupAction(
            $request,
            $pickup
        );

        $pickup = $service->start(
            pickup: $pickup,
            user: $request->user()
        );

        return ApiResponse::success(
            $pickup,
            'Rider has started the pickup.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ARRIVE
    |--------------------------------------------------------------------------
    */

    public function arrive(
        Request $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {

        $this->authorizePickupAction(
            $request,
            $pickup
        );

        $pickup = $service->arrive(
            pickup: $pickup,
            user: $request->user()
        );

        return ApiResponse::success(
            $pickup,
            'Rider arrival recorded.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | COLLECT SHIPMENT
    |--------------------------------------------------------------------------
    */

    public function collectShipment(
        CollectShipmentRequest $request,
        PickupRequestModel $pickup,
        Shipment $shipment,
        PickupRequestService $service
    ) {

        $this->authorizePickupAction(
            $request,
            $pickup
        );

        $item = $service->collectShipment(
            pickup: $pickup,
            shipment: $shipment,
            user: $request->user(),
            remarks: $request->validated('remarks')
        );

        return ApiResponse::success(
            $item,
            'Shipment collected successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | COMPLETE
    |--------------------------------------------------------------------------
    */

    public function complete(
        Request $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {

        $this->authorizePickupAction(
            $request,
            $pickup
        );

        $pickup = $service->complete(
            pickup: $pickup,
            user: $request->user()
        );

        return ApiResponse::success(
            $pickup,
            'Pickup completed successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RECEIVE SHIPMENT AT ORIGIN
    |--------------------------------------------------------------------------
    */

    public function receiveShipment(
        Request $request,
        PickupRequestModel $pickup,
        Shipment $shipment,
        PickupRequestService $service
    ) {

        $this->authorizePickupAction(
            $request,
            $pickup
        );

        $item = $service->receiveShipment(
            pickup: $pickup,
            shipment: $shipment,
            staff: $request->user()
        );

        return ApiResponse::success(
            $item,
            'Shipment received at origin branch successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FAIL
    |--------------------------------------------------------------------------
    */

    public function fail(
        FailPickupRequest $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {

        $this->authorizePickupAction(
            $request,
            $pickup
        );

        $pickup = $service->fail(
            pickup: $pickup,
            user: $request->user(),
            reason: $request->validated('reason')
        );

        return ApiResponse::success(
            $pickup,
            'Pickup marked as failed.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | BRANCH FILTER
    |--------------------------------------------------------------------------
    |
    | A pickup is visible to a branch when that branch appears anywhere
    | in its operational ownership/lifecycle.
    |
    | branch_id
    | sub_branch_id
    | pickup_branch_id
    | pickup_sub_branch_id
    |
    */

    private function applyBranchFilter(
        Builder $query,
        int $branchId
    ): void {

        $query->where(function (Builder $q) use ($branchId) {

            $q->where(
                'branch_id',
                $branchId
            )

            ->orWhere(
                'sub_branch_id',
                $branchId
            )

            ->orWhere(
                'pickup_branch_id',
                $branchId
            )

            ->orWhere(
                'pickup_sub_branch_id',
                $branchId
            );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE USER BRANCH
    |--------------------------------------------------------------------------
    */

    private function resolveUserBranchId(
        Request $request,
        $user
    ): ?int {

        /*
         * Prefer the authenticated user's branch.
         */

        if ($user?->branch_id) {

            return (int) $user->branch_id;
        }

        /*
         * branch.scope middleware may provide the trusted scope.
         */

        $scopedBranchId =
            $request->attributes->get(
                '_scope_branch_id'
            );

        if ($scopedBranchId) {

            return (int) $scopedBranchId;
        }

        /*
         * Backward compatibility if middleware currently
         * injects it as a request parameter.
         */

        if ($request->filled('_scope_branch_id')) {

            return (int) $request->input(
                '_scope_branch_id'
            );
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | GLOBAL ADMIN
    |--------------------------------------------------------------------------
    */

    private function isGlobalAdmin($user): bool
    {
        return (bool) (
            $user?->isSuperAdmin()
            ||
            $user?->is_super_admin
            ||
            $user?->hasRole('main_admin')
            ||
            $user?->role === 'main_admin'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VIEW AUTHORIZATION
    |--------------------------------------------------------------------------
    */

    private function authorizePickupView(
        Request $request,
        PickupRequestModel $pickup
    ): void {

        $user = $request->user();

        /*
         * Super admin / main admin
         */

        if ($this->isGlobalAdmin($user)) {
            return;
        }

        /*
         * Merchant
         */

        if ($user->hasRole('merchant')) {

            abort_unless(
                (int) $pickup->merchant_id ===
                (int) $user->merchant_id,
                403
            );

            return;
        }

        /*
         * Branch manager / sub branch manager
         */

        if (
            $user->hasRole('branch_manager')
            ||
            $user->hasRole('sub_branch_manager')
        ) {

            $branchId =
                $this->resolveUserBranchId(
                    $request,
                    $user
                );

            abort_unless(
                $branchId
                &&
                $this->pickupBelongsToBranch(
                    $pickup,
                    $branchId
                ),
                403
            );

            return;
        }

        /*
         * Rider / staff
         */

        abort_unless(
            (int) $pickup->assigned_to ===
            (int) $user->id,
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MANAGEMENT AUTHORIZATION
    |--------------------------------------------------------------------------
    */

    private function authorizeManagement(
        Request $request,
        PickupRequestModel $pickup
    ): void {

        $user = $request->user();

        /*
         * Global admin
         */

        if ($this->isGlobalAdmin($user)) {
            return;
        }

        /*
         * Branch manager
         */

        if (
            $user->hasRole('branch_manager')
            ||
            $user->hasRole('sub_branch_manager')
        ) {

            $branchId =
                $this->resolveUserBranchId(
                    $request,
                    $user
                );

            abort_unless(
                $branchId
                &&
                $this->pickupBelongsToBranch(
                    $pickup,
                    $branchId
                ),
                403
            );

            return;
        }

        abort(403);
    }

    /*
    |--------------------------------------------------------------------------
    | PICKUP ACTION AUTHORIZATION
    |--------------------------------------------------------------------------
    */

    private function authorizePickupAction(
        Request $request,
        PickupRequestModel $pickup
    ): void {

        $user = $request->user();

        /*
         * Global admin
         */

        if ($this->isGlobalAdmin($user)) {
            return;
        }

        /*
         * Branch managers can operate on their branch pickups.
         */

        if (
            $user->hasRole('branch_manager')
            ||
            $user->hasRole('sub_branch_manager')
        ) {

            $branchId =
                $this->resolveUserBranchId(
                    $request,
                    $user
                );

            abort_unless(
                $branchId
                &&
                $this->pickupBelongsToBranch(
                    $pickup,
                    $branchId
                ),
                403
            );

            return;
        }

        /*
         * Rider can only operate on assigned pickup.
         */

        abort_unless(
            (int) $pickup->assigned_to ===
            (int) $user->id,
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PICKUP BELONGS TO BRANCH
    |--------------------------------------------------------------------------
    */

    private function pickupBelongsToBranch(
        PickupRequestModel $pickup,
        int $branchId
    ): bool {

        return
            (int) $pickup->branch_id === $branchId
            ||
            (int) $pickup->sub_branch_id === $branchId
            ||
            (int) $pickup->pickup_branch_id === $branchId
            ||
            (int) $pickup->pickup_sub_branch_id === $branchId;
    }

    /*
    |--------------------------------------------------------------------------
    | STAFF ASSIGNMENT AUTHORIZATION
    |--------------------------------------------------------------------------
    */

    private function authorizeStaffAssignment(
        PickupRequestModel $pickup,
        User $staff
    ): void {

        /*
         * If staff has no branch configured, don't allow
         * cross-branch assignment.
         */

        $pickupBranchIds = array_filter([
            (int) $pickup->branch_id,
            (int) $pickup->sub_branch_id,
            (int) $pickup->pickup_branch_id,
            (int) $pickup->pickup_sub_branch_id,
        ]);

        if (
            $staff->branch_id
            &&
            ! in_array(
                (int) $staff->branch_id,
                $pickupBranchIds,
                true
            )
        ) {

            abort(
                422,
                'The selected staff does not belong to the pickup branch.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | MERCHANT SHIPMENT AUTHORIZATION
    |--------------------------------------------------------------------------
    */

    private function authorizeMerchantShipment(
        Request $request,
        Shipment $shipment
    ): void {

        $user = $request->user();

        /*
         * Global admin
         */

        if ($this->isGlobalAdmin($user)) {
            return;
        }

        /*
         * Only merchant can manually attach shipments.
         */

        abort_unless(
            $user->hasRole('merchant'),
            403
        );

        abort_unless(
            (int) $shipment->merchant_id ===
            (int) $user->merchant_id,
            403
        );
    }
}