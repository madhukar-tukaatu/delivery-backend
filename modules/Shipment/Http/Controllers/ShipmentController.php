<?php

declare (strict_types = 1);

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
                'merchant:id,name,code,external_store_id,marketplace_id',
                'merchant.marketplace:id,name,code',
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
                    'data'         => [],
                    'current_page' => 1,
                    'per_page'     => (int) $request->get('per_page', 20),
                    'total'        => 0,
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

        if ($request->filled('marketplace_id')) {
            $marketplaceId = (int) $request->input('marketplace_id');
            $query->where(function ($q) use ($marketplaceId): void {
                $q->where('marketplace_id', $marketplaceId)
                    ->orWhereHas('merchant', fn($mq) => $mq->where('marketplace_id', $marketplaceId));
            });
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

    // public function show(
    //     Request $request,
    //     Shipment $shipment
    // ) {
    //     $this->authorizeShipment(
    //         $request,
    //         $shipment
    //     );

    //     return ApiResponse::success(
    //         $shipment->load([
    //             'merchant',
    //             'items',
    //             'trackingEvents',
    //             'originBranch',
    //             'originSubBranch',
    //             'destinationBranch',
    //             'destinationSubBranch',
    //             'currentBranch',
    //             'currentSubBranch',
    //             'routeSteps.fromBranch',
    //             'routeSteps.toBranch',
    //         ])
    //     );
    // }
    public function show(Request $request, Shipment $shipment)
    {
        $this->authorizeShipment($request, $shipment);

        $shipment->load([
            // Parties – expand merchant columns if your table has them
            'merchant:id,name,owner_name,contact_person,phone,email,address,city,area,pickup_address,pickup_city,pickup_area',
            'pickupLocation',
            'items',

            // Branches
            'originBranch:id,name,area,code,latitude,longitude,office_latitude,office_longitude',
            'originSubBranch:id,name,area,code,latitude,longitude,office_latitude,office_longitude',
            'destinationBranch:id,name,area,code,latitude,longitude,office_latitude,office_longitude',
            'destinationSubBranch:id,name,area,code,latitude,longitude,office_latitude,office_longitude',
            'currentBranch:id,name,area,code,latitude,longitude,office_latitude,office_longitude',
            'currentSubBranch:id,name,area,code,latitude,longitude,office_latitude,office_longitude',
            'nextHopBranch:id,name,area,code,latitude,longitude,office_latitude,office_longitude',

            // Route
            'routeSteps'     => fn($q)     => $q->orderBy('sequence'),
            'routeSteps.fromBranch:id,name,area,latitude,longitude,office_latitude,office_longitude',
            'routeSteps.toBranch:id,name,area,latitude,longitude,office_latitude,office_longitude',

            // Activity (oldest → newest for timeline)
            'trackingEvents' => fn($q) => $q->orderBy('created_at'),
        ]);

        // is_transfer, hop_meta, etc. already on $appends – no extra append() needed

        $payload = $shipment->toArray();

        if (is_string($payload['packet_products'] ?? null)) {
            $payload['packet_products'] = json_decode($payload['packet_products'], true) ?? [];
        }
        if (is_string($payload['delivery_charge_breakdown'] ?? null)) {
            $payload['delivery_charge_breakdown'] = json_decode(
                $payload['delivery_charge_breakdown'],
                true
            ) ?? null;
        }

        $payload['price_breakdown'] = $this->buildPriceBreakdown(
            $payload['delivery_charge_breakdown'] ?? null,
            $shipment
        );

        $payload['distance_data'] = $this->calculateShipmentDistances($shipment);

        return ApiResponse::success($payload);
    }

    private function calculateShipmentDistances(Shipment $shipment): array
    {
        $totalDistance = (float) ($shipment->route_distance_km ?? 0);

        $originBranch = $shipment->originSubBranch ?? $shipment->originBranch;
        $destBranch = $shipment->destinationSubBranch ?? $shipment->destinationBranch;
        $currentBranch = $shipment->currentSubBranch ?? $shipment->currentBranch ?? $originBranch;

        $originLat = $this->resolveBranchLat($originBranch) ?? (float) ($shipment->pickup_lat ?? 0);
        $originLng = $this->resolveBranchLng($originBranch) ?? (float) ($shipment->pickup_lng ?? 0);

        $destLat = $this->resolveBranchLat($destBranch) ?? (float) ($shipment->delivery_lat ?? 0);
        $destLng = $this->resolveBranchLng($destBranch) ?? (float) ($shipment->delivery_lng ?? 0);

        $currentLat = $this->resolveBranchLat($currentBranch) ?? $originLat;
        $currentLng = $this->resolveBranchLng($currentBranch) ?? $originLng;

        $routeSteps = $shipment->routeSteps ?? collect();
        $routeStepsTotal = 0.0;
        $routeStepsCovered = 0.0;
        $hasRouteSteps = $routeSteps->isNotEmpty();

        if ($hasRouteSteps) {
            foreach ($routeSteps as $step) {
                $stepDist = (float) ($step->distance_km ?? 0);
                $routeStepsTotal += $stepDist;
                if (in_array(strtolower((string) $step->status), ['completed', 'received']) || !empty($step->completed_at)) {
                    $routeStepsCovered += $stepDist;
                }
            }
        }

        if ($totalDistance <= 0 && $routeStepsTotal > 0) {
            $totalDistance = $routeStepsTotal;
        }

        if ($totalDistance <= 0 && $originLat && $destLat) {
            $totalDistance = $this->haversineKm($originLat, $originLng, $destLat, $destLng);
        }

        $status = strtolower((string) $shipment->status);
        $isDelivered = ($status === 'delivered');
        $isAtDestinationOrBeyond = in_array($status, [
            'received_at_destination',
            'received_at_destination_branch',
            'received_at_destination_sub_branch',
            'assigned_to_rider',
            'out_for_delivery',
            'delivered',
        ]) || (
            !empty($shipment->current_branch_id) &&
            !empty($shipment->destination_branch_id) &&
            (int) $shipment->current_branch_id === (int) $shipment->destination_branch_id
        );

        if ($isDelivered) {
            $coveredDistance = $totalDistance;
            $distanceFromDest = 0.0;
            $progressPercent = 100;
        } elseif ($isAtDestinationOrBeyond) {
            $distanceFromDest = 0.0;
            $coveredDistance = $hasRouteSteps && $routeStepsCovered > 0
                ? max($routeStepsCovered, $totalDistance * 0.9)
                : $totalDistance * 0.9;
            $progressPercent = $totalDistance > 0 ? (int) round(($coveredDistance / $totalDistance) * 100) : 90;
        } elseif ($status === 'in_transit' || $status === 'received_at_transit_hub') {
            if ($currentLat && $destLat) {
                $distanceFromDest = $this->haversineKm($currentLat, $currentLng, $destLat, $destLng);
            } else {
                $distanceFromDest = max(0.0, $totalDistance - $routeStepsCovered);
            }
            $coveredDistance = $routeStepsCovered > 0
                ? $routeStepsCovered
                : max(0.0, $totalDistance - $distanceFromDest);
            $progressPercent = $totalDistance > 0 ? (int) min(95, max(15, round(($coveredDistance / $totalDistance) * 100))) : 50;
        } elseif (in_array($status, ['picked_up', 'received_at_origin', 'received_at_origin_branch', 'sorted_for_transfer'])) {
            if ($originLat && $destLat) {
                $distanceFromDest = $this->haversineKm($originLat, $originLng, $destLat, $destLng);
            } else {
                $distanceFromDest = $totalDistance;
            }
            $coveredDistance = min($totalDistance * 0.1, 5.0);
            $progressPercent = 10;
        } else {
            if ($originLat && $destLat) {
                $distanceFromDest = $this->haversineKm($originLat, $originLng, $destLat, $destLng);
            } else {
                $distanceFromDest = $totalDistance;
            }
            $coveredDistance = 0.0;
            $progressPercent = 0;
        }

        return [
            'total_distance_km'                   => round($totalDistance, 2),
            'covered_distance_km'                 => round($coveredDistance, 2),
            'distance_from_destination_branch_km' => round($distanceFromDest, 2),
            'progress_percent'                    => (int) $progressPercent,
            'is_at_destination'                   => $isAtDestinationOrBeyond,
        ];
    }

    private function resolveBranchLat(?object $branch): ?float
    {
        if (!$branch) return null;
        $lat = $branch->latitude ?? $branch->office_latitude ?? null;
        return $lat !== null ? (float) $lat : null;
    }

    private function resolveBranchLng(?object $branch): ?float
    {
        if (!$branch) return null;
        $lng = $branch->longitude ?? $branch->office_longitude ?? null;
        return $lng !== null ? (float) $lng : null;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return round($earthRadius * $c, 2);
    }

    private function buildPriceBreakdown(?array $raw, Shipment $shipment): ?array
    {
        if (empty($raw)) {
            return null;
        }

        $b = $raw['breakdown'] ?? $raw;

        return [
            'base_pickup_fee'       => (float) ($shipment->pickup_fee ?? 0),
            'base_delivery_fee'     => (float) (
                $b['route_base_rate']['total'] ?? $b['route_base_rate']['amount'] ?? 0
            ),
            'base_transfer_fee'     => 0,
            'pickup_extra_charge'   => 0,
            'delivery_extra_charge' => (float) ($b['extra_delivery_distance']['total'] ?? 0),
            'weight_charge'         => (float) ($b['additional_weight']['total'] ?? 0),
            'pod_fee'               => (float) ($shipment->pod_charge ?? 0),
            'final_price'           => (float) (
                $raw['final_price'] ?? $shipment->delivery_charge ?? 0
            ),
            'raw'                   => $raw,
        ];
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
        Shipment $shipment,
        ShipmentService $service
    ) {
        $this->authorizeShipment(
            $request,
            $shipment
        );

        $data = $this->validatedShipment($request);

        $hasNewDeliveryCoords = (
            isset($data['delivery_lat']) && isset($data['delivery_lng'])
            && (
                (float) $data['delivery_lat'] !== (float) $shipment->delivery_lat
                || (float) $data['delivery_lng'] !== (float) $shipment->delivery_lng
            )
        );

        if ($hasNewDeliveryCoords && empty($data['manual_branch_override'])) {
            $shipment = $service->updateDeliveryLocation($shipment, $data, $request->user()?->id);
            $shipment->update($data);
        } else {
            $shipment->update($data);
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
    | PREVIEW DELIVERY LOCATION RECALCULATION
    |--------------------------------------------------------------------------
    */

    public function previewDeliveryLocation(
        Request $request,
        Shipment $shipment,
        ShipmentService $service
    ) {
        $this->authorizeShipment($request, $shipment);

        $data = $request->validate([
            'delivery_lat'      => ['required', 'numeric', 'between:-90,90'],
            'delivery_lng'      => ['required', 'numeric', 'between:-180,180'],
            'delivery_address'  => ['nullable', 'string', 'max:500'],
            'delivery_city'     => ['nullable', 'string', 'max:100'],
            'delivery_area'     => ['nullable', 'string', 'max:100'],
            'receiver_address'  => ['nullable', 'string', 'max:500'],
            'receiver_city'     => ['nullable', 'string', 'max:100'],
            'receiver_area'     => ['nullable', 'string', 'max:100'],
        ]);

        $preview = $service->previewDeliveryLocationRecalculation($shipment, $data);

        return ApiResponse::success($preview, 'Delivery location recalculation previewed.');
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE CUSTOMER DELIVERY LOCATION & RECALCULATE CHARGE
    |--------------------------------------------------------------------------
    */

    public function updateDeliveryLocation(
        Request $request,
        Shipment $shipment,
        ShipmentService $service
    ) {
        $this->authorizeShipment($request, $shipment);

        $data = $request->validate([
            'delivery_lat'      => ['required', 'numeric', 'between:-90,90'],
            'delivery_lng'      => ['required', 'numeric', 'between:-180,180'],
            'delivery_address'  => ['nullable', 'string', 'max:500'],
            'delivery_city'     => ['nullable', 'string', 'max:100'],
            'delivery_area'     => ['nullable', 'string', 'max:100'],
            'receiver_address'  => ['nullable', 'string', 'max:500'],
            'receiver_city'     => ['nullable', 'string', 'max:100'],
            'receiver_area'     => ['nullable', 'string', 'max:100'],
            'receiver_name'     => ['nullable', 'string', 'max:150'],
            'receiver_phone'    => ['nullable', 'string', 'max:50'],
            'receiver_email'    => ['nullable', 'email', 'max:150'],
        ]);

        $updated = $service->updateDeliveryLocation($shipment, $data, $request->user()?->id);

        return ApiResponse::success(
            $updated->fresh([
                'merchant',
                'items',
                'trackingEvents',
                'originBranch',
                'originSubBranch',
                'destinationBranch',
                'destinationSubBranch',
                'currentBranch',
                'currentSubBranch',
            ]),
            'Customer delivery location updated and delivery charge recalculated.'
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
            'status'  => [
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
            'merchant_id'               => [
                'nullable',
                'exists:merchants,id',
            ],

            'merchant_order_id'         => [
                'nullable',
                'string',
            ],

            'manual_branch_override'    => [
                'nullable',
                'boolean',
            ],

            'origin_branch_id'          => [
                'nullable',
                'exists:branches,id',
            ],

            'origin_sub_branch_id'      => [
                'nullable',
                'exists:branches,id',
            ],

            'destination_branch_id'     => [
                'nullable',
                'exists:branches,id',
            ],

            'destination_sub_branch_id' => [
                'nullable',
                'exists:branches,id',
            ],

            'pickup_lat'                => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'pickup_lng'                => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],

            'delivery_lat'              => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'delivery_lng'              => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],

            'sender_name'               => [
                'nullable',
                'string',
            ],

            'sender_phone'              => [
                'nullable',
                'string',
            ],

            'sender_address'            => [
                'nullable',
                'string',
            ],

            'sender_city'               => [
                'nullable',
                'string',
            ],

            'sender_area'               => [
                'nullable',
                'string',
            ],

            'receiver_name'             => [
                'required',
                'string',
            ],

            'receiver_phone'            => [
                'required',
                'string',
            ],

            'receiver_email'            => [
                'nullable',
                'email',
            ],

            'receiver_address'          => [
                'required',
                'string',
            ],

            'receiver_city'             => [
                'nullable',
                'string',
            ],

            'receiver_area'             => [
                'nullable',
                'string',
            ],

            'delivery_address'          => [
                'nullable',
                'string',
            ],

            'delivery_city'             => [
                'nullable',
                'string',
            ],

            'delivery_area'             => [
                'nullable',
                'string',
            ],

            'parcel_type'               => [
                'nullable',
                'string',
            ],

            'description'               => [
                'nullable',
                'string',
            ],

            'quantity'                  => [
                'nullable',
                'integer',
                'min:1',
            ],

            'weight'                    => [
                'nullable',
                'numeric',
                'min:0.1',
            ],

            'declared_value'            => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'fragile'                   => [
                'nullable',
                'boolean',
            ],

            'payment_type'              => [
                'nullable',
                'in:prepaid,pod,to_pay',
            ],

            'pod_amount'                => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'delivery_charge'           => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'pod_charge'                => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'delivery_charge_paid_by'   => [
                'nullable',
                'in:merchant,customer',
            ],

            'delivery_free_by'          => ['nullable', 'in:none,store,marketplace'],

            'remarks'                   => [
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
