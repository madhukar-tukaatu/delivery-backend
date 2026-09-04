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
use Modules\Pickup\Services\PickupCallbackService;
use Modules\Pickup\Services\PickupRequestService;
use Modules\Shipment\Models\Shipment;

final class AdminPickupController extends Controller
{
    public function __construct(
        private readonly PickupRequestService $pickupRequestService,
        private readonly PickupCallbackService $callbackService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | INDEX 
    |--------------------------------------------------------------------------
    */

    public function index(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        $perPage = min(
            max(
                (int) $request->input('per_page', 20),
                1
            ),
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
                'merchant:id,name,phone,email',
                'pickupLocation',
                'assignedStaff:id,name,email,phone,branch_id',
            ]);

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
            $query->where(
                function (Builder $q) use ($search): void {
                    $like = '%' . $search . '%';

                    $q->where(
                        'request_number',
                        'like',
                        $like
                    )
                    ->orWhere(
                        'store_reference',
                        'like',
                        $like
                    )
                    ->orWhere(
                        'pickup_name',
                        'like',
                        $like
                    )
                    ->orWhere(
                        'pickup_phone',
                        'like',
                        $like
                    )
                    ->orWhereHas(
                        'merchant',
                        function (
                            Builder $merchantQuery
                        ) use ($like): void {
                            $merchantQuery
                                ->where(
                                    'name',
                                    'like',
                                    $like
                                )
                                ->orWhere(
                                    'phone',
                                    'like',
                                    $like
                                )
                                ->orWhere(
                                    'email',
                                    'like',
                                    $like
                                );
                        }
                    )
                    ->orWhereHas(
                        'pickupLocation',
                        function (
                            Builder $locationQuery
                        ) use ($like): void {
                            $locationQuery
                                ->where(
                                    'name',
                                    'like',
                                    $like
                                )
                                ->orWhere(
                                    'address',
                                    'like',
                                    $like
                                )
                                ->orWhere(
                                    'phone',
                                    'like',
                                    $like
                                );
                        }
                    );
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        if ($status !== '') {
            $query->where(
                'status',
                $status
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Order
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

        return ApiResponse::success(
            $paginator,
            'Pickup requests retrieved successfully.'
        );
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
        $this->authorizePickup(
            $request,
            $pickup
        );

        $pickup->load([
            'merchant',
            'pickupLocation',
            'assignedStaff',
            'assignedBy',
            'pickedUpBy',
            'pickupBranch',
            'pickupSubBranch',
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
    */

    public function assignableStaff(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $this->authorizePickup(
            $request,
            $pickup
        );

        $pickupBranchId = $this->pickupBranchId(
            $pickup
        );

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT:
        | Do NOT select sub_branch_id.
        |--------------------------------------------------------------------------
        */

        $query = User::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'branch_id',
                'is_active',
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

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT:
        | Do NOT select sub_branch_id.
        |--------------------------------------------------------------------------
        */

        $staff = User::query()
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'branch_id',
                'is_active',
            ])
            ->whereKey(
                $validated['staff_id']
            )
            ->where(
                'is_active',
                true
            )
            ->first();

        if (! $staff) {
            return ApiResponse::error(
                'Selected staff member is not active or does not exist.',
                422
            );
        }

        if (! $staff->hasAnyRole([
            'rider',
            'pickup_rider',
            'staff',
            'delivery_staff',
        ])) {
            return ApiResponse::error(
                'Selected staff member cannot be assigned to a pickup.',
                422
            );
        }

        $pickupBranchId = $this->pickupBranchId(
            $pickup
        );

        if (
            $pickupBranchId !== null
            &&
            $staff->branch_id !== null
            &&
            (int) $staff->branch_id !==
            (int) $pickupBranchId
        ) {
            return ApiResponse::error(
                'The selected staff member does not belong to the pickup branch.',
                422
            );
        }

        $updatedPickup =
            $this->pickupRequestService->assign(
                $pickup,
                $staff,
                $request->user()
            );

        $updatedPickup->load([
            'merchant',
            'pickupLocation',
            'assignedStaff',
            'assignedBy',
            'pickedUpBy',
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

            'remarks' => [
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
                'is_active',
            ])
            ->whereKey(
                $validated['staff_id']
            )
            ->where(
                'is_active',
                true
            )
            ->first();

        if (! $staff) {
            return ApiResponse::error(
                'Selected staff member is not active or does not exist.',
                422
            );
        }

        if (! $staff->hasAnyRole([
            'rider',
            'pickup_rider',
            'staff',
            'delivery_staff',
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
            $pickupBranchId !== null
            &&
            $staff->branch_id !== null
            &&
            (int) $staff->branch_id !==
            (int) $pickupBranchId
        ) {
            return ApiResponse::error(
                'The selected staff member does not belong to the pickup branch.',
                422
            );
        }

        $updatedPickup =
            $this->pickupRequestService->transfer(
                $pickup,
                $staff,
                $request->user(),
                trim(
                    (string) (
                        $validated['remarks']
                        ?? 'Pickup transferred.'
                    )
                )
            );

        $updatedPickup->load([
            'merchant',
            'pickupLocation',
            'assignedStaff',
            'assignedBy',
            'pickedUpBy',
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

        $updatedPickup =
            $this->pickupRequestService->fail(
                $pickup,
                $request->user(),
                $validated['reason']
            );

        $updatedPickup->load([
            'merchant',
            'pickupLocation',
            'assignedStaff',
            'assignedBy',
            'pickedUpBy',
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
    | POST:
    | /api/v1/admin/pickups/{pickup}/shipments/{shipment}/receive
    |--------------------------------------------------------------------------
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

        /*
        |--------------------------------------------------------------------------
        | FIX:
        |
        | Route gives us shipment ID.
        | Service requires Shipment model.
        |--------------------------------------------------------------------------
        */

        $shipmentModel = Shipment::query()
            ->whereKey($shipment)
            ->first();

        if (! $shipmentModel) {
            return ApiResponse::error(
                'Shipment not found.',
                404
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Make sure shipment belongs to pickup.
        |--------------------------------------------------------------------------
        */

        $belongsToPickup =
            $pickup->activeShipments()
                ->where(
                    'shipment_id',
                    $shipmentModel->id
                )
                ->exists();

        if (! $belongsToPickup) {
            return ApiResponse::error(
                'Shipment does not belong to this pickup request.',
                422
            );
        }

        $updatedItem =
            $this->pickupRequestService->receiveShipment(
                $pickup,
                $shipmentModel,
                $request->user()
            );

        $pickup->load([
            'merchant',
            'pickupLocation',
            'assignedStaff',
            'assignedBy',
            'pickedUpBy',
            'shipments',
        ]);

        return ApiResponse::success(
            [
                'pickup' => $pickup,
                'shipment' => $updatedItem->shipment,
                'pickup_shipment' => $updatedItem,
            ],
            'Shipment received successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RESEND CALLBACK
    |--------------------------------------------------------------------------
    |
    | POST:
    | /api/v1/admin/pickups/{pickup}/resend-callback
    |
    | Body:
    |   event       (required) one of PickupCallbackService::RESENDABLE_EVENTS
    |   shipment_id  (required only for shipment.* events)
    |
    | Re-queues a pickup callback to the store partner using the pickup's
    | current state. Useful when a callback previously failed (e.g. the store
    | returned 422) and needs to be replayed after the issue is resolved.
    |--------------------------------------------------------------------------
    */

    public function resendCallback(
        Request $request,
        PickupRequest $pickup
    ): JsonResponse {
        $this->authorizePickup(
            $request,
            $pickup
        );

        $validated = $request->validate([
            'event' => [
                'required',
                'string',
                'in:' . implode(
                    ',',
                    PickupCallbackService::RESENDABLE_EVENTS
                ),
            ],

            'shipment_id' => [
                'nullable',
                'integer',
                'exists:shipments,id',
            ],
        ]);

        $event = $validated['event'];

        $shipmentModel = null;

        /*
        |--------------------------------------------------------------------------
        | Shipment-scoped events need a shipment that belongs to this pickup.
        |--------------------------------------------------------------------------
        */
        if (str_starts_with($event, 'shipment.')) {
            if (empty($validated['shipment_id'])) {
                return ApiResponse::error(
                    'A shipment_id is required to resend a shipment event.',
                    422
                );
            }

            $shipmentModel = Shipment::query()
                ->whereKey($validated['shipment_id'])
                ->first();

            if (! $shipmentModel) {
                return ApiResponse::error(
                    'Shipment not found.',
                    404
                );
            }

            $belongsToPickup = $pickup->shipments()
                ->where(
                    'shipment_id',
                    $shipmentModel->id
                )
                ->exists();

            if (! $belongsToPickup) {
                return ApiResponse::error(
                    'Shipment does not belong to this pickup request.',
                    422
                );
            }
        }

        $pickup->load([
            'merchant',
            'pickupLocation',
            'assignedStaff',
            'shipments',
        ]);

        $this->callbackService->resend(
            pickup: $pickup,
            event: $event,
            shipment: $shipmentModel
        );

        return ApiResponse::success(
            [
                'event' => $event,
                'pickup_id' => $pickup->id,
                'shipment_id' => $shipmentModel?->id,
            ],
            'Callback re-queued successfully.'
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
            $query->whereRaw('1 = 0');

            return;
        }

        if ($this->isGlobalAdmin($user)) {
            return;
        }

        $branchId = $this->userBranchId(
            $user
        );

        if ($branchId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(
            'pickup_branch_id',
            $branchId
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AUTHORIZE
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

        if ($this->isGlobalAdmin($user)) {
            return;
        }

        $userBranchId = $this->userBranchId(
            $user
        );

        $pickupBranchId = $this->pickupBranchId(
            $pickup
        );

        if (
            $userBranchId === null
            ||
            $pickupBranchId === null
            ||
            (int) $userBranchId !==
            (int) $pickupBranchId
        ) {
            abort(
                403,
                'You are not authorized to access this pickup.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GLOBAL ADMIN
    |--------------------------------------------------------------------------
    */

    private function isGlobalAdmin(
        User $user
    ): bool {
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
    |
    | ONLY branch_id.
    |--------------------------------------------------------------------------
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
            $pickup->pickup_branch_id === null
        ) {
            return null;
        }

        return (int) $pickup->pickup_branch_id;
    }
}