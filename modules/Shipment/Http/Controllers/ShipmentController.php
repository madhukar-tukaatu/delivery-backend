<?php

declare(strict_types=1);

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\CourierStatus;
use Illuminate\Http\Request;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\ShipmentService;

final class ShipmentController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        $user = $request->user();

        $query = Shipment::query()
            ->with([
                'merchant',
                'originBranch',
                'originSubBranch',
                'destinationBranch',
                'destinationSubBranch',
                'currentBranch',
                'currentSubBranch',
            ])
            ->latest();

        /*
        |--------------------------------------------------------------------------
        | ACCESS SCOPE
        |--------------------------------------------------------------------------
        */

        if (! $this->isGlobalAdmin($user)) {
            $branchId = $this->resolveUserBranchId($user);

            /*
             * User without a branch:
             * no shipment visibility.
             */
            if (! $branchId) {
                return ApiResponse::success([
                    'data' => [],
                    'current_page' => 1,
                    'per_page' => (int) $request->get('per_page', 20),
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
        | STATUS
        |--------------------------------------------------------------------------
        */

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->input('status')
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
                $request->input('merchant_id')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | SEARCH
        |--------------------------------------------------------------------------
        */

        $search = trim(
            (string) (
                $request->input(
                    'search',
                    $request->input('q', '')
                )
            )
        );

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where(
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
            });
        }

        /*
        |--------------------------------------------------------------------------
        | SERVICE TYPE
        |--------------------------------------------------------------------------
        */

        if ($request->filled('service_type')) {
            $query->where(
                'service_type',
                $request->input('service_type')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | PAYMENT TYPE
        |--------------------------------------------------------------------------
        */

        if ($request->filled('payment_type')) {
            $query->where(
                'payment_type',
                $request->input('payment_type')
            );
        }

        /*
        |--------------------------------------------------------------------------
        | PAGINATION
        |--------------------------------------------------------------------------
        */

        $perPage = max(
            1,
            min(
                (int) $request->get('per_page', 20),
                100
            )
        );

        $shipments = $query
            ->paginate($perPage)
            ->appends($request->query());

        return ApiResponse::success($shipments);
    }

    /*
    |--------------------------------------------------------------------------
    | SHOW
    |--------------------------------------------------------------------------
    */

    public function show(
        Request $request,
        Shipment $shipment
    ) {
        $this->authorizeShipment(
            $request,
            $shipment
        );

        return ApiResponse::success(
            $shipment->load([
                'merchant',
                'items',
                'trackingEvents',
                'originBranch',
                'originSubBranch',
                'destinationBranch',
                'destinationSubBranch',
                'currentBranch',
                'currentSubBranch',
                'routeSteps.fromBranch',
                'routeSteps.toBranch',
            ])
        );
    }

    /*
    |--------------------------------------------------------------------------
    | STORE
    |--------------------------------------------------------------------------
    */

    public function store(
        Request $request,
        ShipmentService $service
    ) {
        $data = $this->validatedShipment($request);

        $shipment = $service->create(
            $data,
            $request->user()->id,
            $data['merchant_id'] ?? null,
            'manual'
        );

        return ApiResponse::success(
            $shipment,
            'Shipment created.',
            201
        );
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        Shipment $shipment
    ) {
        $this->authorizeShipment(
            $request,
            $shipment
        );

        $data = $this->validatedShipment($request);

        $shipment->update($data);

        /*
        |--------------------------------------------------------------------------
        | Recalculate route when coordinates change
        |--------------------------------------------------------------------------
        */

        if ($this->shouldReroute($data)) {
            app(
                \Modules\Routing\Services\ShipmentRoutingService::class
            )->applyToShipment(
                $shipment,
                [
                    'pickup_lat' =>
                        $data['pickup_lat'],

                    'pickup_lng' =>
                        $data['pickup_lng'],

                    'delivery_lat' =>
                        $data['delivery_lat'],

                    'delivery_lng' =>
                        $data['delivery_lng'],

                    'weight' =>
                        $data['weight']
                        ?? $shipment->weight
                        ?? 1,

                    'pod_amount' =>
                        $data['pod_amount']
                        ?? $shipment->pod_amount
                        ?? 0,
                ]
            );
        }

        return ApiResponse::success(
            $shipment->fresh([
                'merchant',
                'items',
                'trackingEvents',
                'originBranch',
                'originSubBranch',
                'destinationBranch',
                'destinationSubBranch',
                'currentBranch',
                'currentSubBranch',
                'routeSteps.fromBranch',
                'routeSteps.toBranch',
            ]),
            'Shipment updated.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | STATUS
    |--------------------------------------------------------------------------
    */

    public function status(
        Request $request,
        Shipment $shipment,
        ShipmentService $service
    ) {
        $this->authorizeShipment(
            $request,
            $shipment
        );

        $data = $request->validate([
            'status' => [
                'required',
                'string',
            ],

            'remarks' => [
                'nullable',
                'string',
            ],
        ]);

        $shipment = $service->updateStatus(
            $shipment,
            $data['status'],
            $request->user()->id,
            $data['remarks'] ?? null
        );

        return ApiResponse::success(
            $shipment,
            'Shipment status updated.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CANCEL
    |--------------------------------------------------------------------------
    */

    public function cancel(
        Request $request,
        Shipment $shipment,
        ShipmentService $service
    ) {
        $this->authorizeShipment(
            $request,
            $shipment
        );

        $shipment = $service->updateStatus(
            $shipment,
            CourierStatus::CANCELLED,
            $request->user()->id,
            $request->get(
                'remarks',
                'Shipment cancelled.'
            )
        );

        return ApiResponse::success(
            $shipment,
            'Shipment cancelled.'
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
                'origin_branch_id',
                $branchId
            )
                ->orWhere(
                    'origin_sub_branch_id',
                    $branchId
                )
                ->orWhere(
                    'destination_branch_id',
                    $branchId
                )
                ->orWhere(
                    'destination_sub_branch_id',
                    $branchId
                )
                ->orWhere(
                    'current_branch_id',
                    $branchId
                )
                ->orWhere(
                    'current_sub_branch_id',
                    $branchId
                );
        });
    }

    /*
    |--------------------------------------------------------------------------
    | AUTHORIZE SINGLE SHIPMENT
    |--------------------------------------------------------------------------
    */

    private function authorizeShipment(
        Request $request,
        Shipment $shipment
    ): void {
        $user = $request->user();

        /*
         * Global administrators can access all shipments.
         */
        if ($this->isGlobalAdmin($user)) {
            return;
        }

        $branchId = $this->resolveUserBranchId($user);

        abort_unless(
            $branchId,
            403,
            'User is not assigned to a branch.'
        );

        $belongsToBranch = in_array(
            $branchId,
            [
                (int) $shipment->origin_branch_id,
                (int) $shipment->origin_sub_branch_id,
                (int) $shipment->destination_branch_id,
                (int) $shipment->destination_sub_branch_id,
                (int) $shipment->current_branch_id,
                (int) $shipment->current_sub_branch_id,
            ],
            true
        );

        abort_unless(
            $belongsToBranch,
            403,
            'You do not have access to this shipment.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | USER BRANCH
    |--------------------------------------------------------------------------
    */

    private function resolveUserBranchId(
        $user
    ): ?int {
        if ($user?->branch_id) {
            return (int) $user->branch_id;
        }

        if ($user?->branch?->id) {
            return (int) $user->branch->id;
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | GLOBAL ADMIN
    |--------------------------------------------------------------------------
    */

    private function isGlobalAdmin(
        $user
    ): bool {
        return (bool) (
            $user?->is_super_admin
            || $user?->role === 'super_admin'
            || (
                method_exists($user, 'isSuperAdmin')
                && $user->isSuperAdmin()
            )
            || (
                method_exists($user, 'hasRole')
                && $user->hasRole('main_admin')
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    private function validatedShipment(
        Request $request
    ): array {
        return $request->validate([
            'merchant_id' => [
                'nullable',
                'exists:merchants,id',
            ],

            'merchant_order_id' => [
                'nullable',
                'string',
            ],

            'manual_branch_override' => [
                'nullable',
                'boolean',
            ],

            'origin_branch_id' => [
                'nullable',
                'exists:branches,id',
            ],

            'origin_sub_branch_id' => [
                'nullable',
                'exists:branches,id',
            ],

            'destination_branch_id' => [
                'nullable',
                'exists:branches,id',
            ],

            'destination_sub_branch_id' => [
                'nullable',
                'exists:branches,id',
            ],

            'pickup_lat' => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'pickup_lng' => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],

            'delivery_lat' => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'delivery_lng' => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],

            'sender_name' => [
                'nullable',
                'string',
            ],

            'sender_phone' => [
                'nullable',
                'string',
            ],

            'sender_address' => [
                'nullable',
                'string',
            ],

            'sender_city' => [
                'nullable',
                'string',
            ],

            'sender_area' => [
                'nullable',
                'string',
            ],

            'receiver_name' => [
                'required',
                'string',
            ],

            'receiver_phone' => [
                'required',
                'string',
            ],

            'receiver_email' => [
                'nullable',
                'email',
            ],

            'receiver_address' => [
                'required',
                'string',
            ],

            'receiver_city' => [
                'nullable',
                'string',
            ],

            'receiver_area' => [
                'nullable',
                'string',
            ],

            'parcel_type' => [
                'nullable',
                'string',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'quantity' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'weight' => [
                'nullable',
                'numeric',
                'min:0.1',
            ],

            'declared_value' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'fragile' => [
                'nullable',
                'boolean',
            ],

            'payment_type' => [
                'nullable',
                'in:prepaid,pod,to_pay',
            ],

            'pod_amount' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'delivery_charge' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'pod_charge' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'delivery_charge_paid_by' => [
                'nullable',
                'in:merchant,customer',
            ],

            'remarks' => [
                'nullable',
                'string',
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | REROUTE
    |--------------------------------------------------------------------------
    */

    private function shouldReroute(
        array $data
    ): bool {
        if (! empty($data['manual_branch_override'])) {
            return false;
        }

        return ! empty($data['pickup_lat'])
            && ! empty($data['pickup_lng'])
            && ! empty($data['delivery_lat'])
            && ! empty($data['delivery_lng']);
    }
}