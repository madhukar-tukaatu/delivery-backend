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
                'nextHopBranch',
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
                    'data'          => [],
                    'current_page'  => 1,
                    'per_page'      => (int) $request->get('per_page', 20),
                    'total'         => 0,
                    'status_counts' => (object) [],
                    'counts_total'  => 0,
                    'facets'        => ['merchants' => [], 'branches' => []],
                ]);
            }

            $this->applyBranchScope(
                $query,
                $branchId
            );
        }

        /*
         * Branch-scoped base (before user filters) for filter facets.
         */
        $scopedBase = clone $query;

        $this->applyIndexFilters($query, $request, $user, false);

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

        if (! $request->boolean('with_counts')) {
            return ApiResponse::success($shipments);
        }

        /*
        |--------------------------------------------------------------------------
        | STATUS COUNTS (same scope + filters, ignoring the status filter)
        |--------------------------------------------------------------------------
        */

        $countsQuery = (clone $scopedBase)->setEagerLoads([]);
        $countsQuery->getQuery()->orders = null;
        $this->applyIndexFilters($countsQuery, $request, $user, true);

        $statusCounts = $countsQuery
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn($value) => (int) $value)
            ->all();

        /*
        |--------------------------------------------------------------------------
        | FACETS (merchants / branches visible in the user's scope)
        |--------------------------------------------------------------------------
        */

        $facetBase = (clone $scopedBase)->setEagerLoads([]);
        $facetBase->getQuery()->orders = null;

        $merchantIds = (clone $facetBase)
            ->whereNotNull('merchant_id')
            ->distinct()
            ->limit(500)
            ->pluck('merchant_id')
            ->all();

        $merchants = $merchantIds
            ? \Modules\Merchant\Models\Merchant::query()
                ->whereIn('id', $merchantIds)
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn($merchant) => [
                    'id'   => $merchant->id,
                    'name' => $merchant->name,
                    'code' => $merchant->code,
                ])
                ->values()
                ->all()
            : [];

        $branchIds = collect(['origin_branch_id', 'destination_branch_id', 'current_branch_id'])
            ->flatMap(fn($column) => (clone $facetBase)
                    ->whereNotNull($column)
                    ->distinct()
                    ->limit(500)
                    ->pluck($column))
            ->unique()
            ->values()
            ->all();

        $branches = $branchIds
            ? \Modules\Branch\Models\Branch::query()
                ->whereIn('id', $branchIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn($branch) => [
                    'id'   => $branch->id,
                    'name' => $branch->name,
                ])
                ->values()
                ->all()
            : [];

        return ApiResponse::success(array_merge(
            $shipments->toArray(),
            [
                'status_counts' => (object) $statusCounts,
                'counts_total'  => array_sum($statusCounts),
                'facets'        => [
                    'merchants' => $merchants,
                    'branches'  => $branches,
                ],
            ]
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | INDEX FILTERS
    |--------------------------------------------------------------------------
    */

    private function applyIndexFilters(
        $query,
        Request $request,
        $user,
        bool $skipStatus = false
    ): void {
        /*
         * Global admin branch filter (branch users are already scoped).
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

        if (! $skipStatus && $request->filled('status')) {
            $statuses = array_values(array_filter(
                array_map('trim', explode(',', (string) $request->input('status')))
            ));

            if (count($statuses) > 1) {
                $query->whereIn('status', $statuses);
            } elseif ($statuses) {
                $query->where('status', $statuses[0]);
            }
        }

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

        if ($request->filled('origin_branch_id')) {
            $originId = (int) $request->input('origin_branch_id');
            $query->where(function ($q) use ($originId): void {
                $q->where('origin_branch_id', $originId)
                    ->orWhere('origin_sub_branch_id', $originId);
            });
        }

        if ($request->filled('destination_branch_id')) {
            $destinationId = (int) $request->input('destination_branch_id');
            $query->where(function ($q) use ($destinationId): void {
                $q->where('destination_branch_id', $destinationId)
                    ->orWhere('destination_sub_branch_id', $destinationId);
            });
        }

        if ($request->filled('current_branch_id')) {
            $currentId = (int) $request->input('current_branch_id');
            $query->where(function ($q) use ($currentId): void {
                $q->where('current_branch_id', $currentId)
                    ->orWhere('current_sub_branch_id', $currentId);
            });
        }

        /*
         * Search: tracking, order refs, receiver, phone, merchant.
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
                $like = "%{$search}%";

                $q->where('tracking_number', 'like', $like)
                    ->orWhere('merchant_order_id', 'like', $like)
                    ->orWhere('order_reference', 'like', $like)
                    ->orWhere('external_order_id', 'like', $like)
                    ->orWhere('receiver_name', 'like', $like)
                    ->orWhere('receiver_phone', 'like', $like)
                    ->orWhereHas('merchant', function ($mq) use ($like): void {
                        $mq->where('name', 'like', $like)
                            ->orWhere('code', 'like', $like);
                    });
            });
        }

        if ($request->filled('service_type')) {
            $query->where(
                'service_type',
                $request->input('service_type')
            );
        }

        if ($request->filled('payment_type')) {
            $query->where(
                'payment_type',
                $request->input('payment_type')
            );
        }

        /*
         * Created date range (YYYY-MM-DD, inclusive).
         */
        if ($request->filled('date_from')) {
            try {
                $query->where(
                    'created_at',
                    '>=',
                    \Illuminate\Support\Carbon::parse((string) $request->input('date_from'))->startOfDay()
                );
            } catch (\Throwable) {
                // ignore an invalid date
            }
        }

        if ($request->filled('date_to')) {
            try {
                $query->where(
                    'created_at',
                    '<=',
                    \Illuminate\Support\Carbon::parse((string) $request->input('date_to'))->endOfDay()
                );
            } catch (\Throwable) {
                // ignore an invalid date
            }
        }
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
            // Parties
            'merchant',
            'pickupLocation',
            'items',

            // Branches
            'originBranch',
            'originSubBranch',
            'destinationBranch',
            'destinationSubBranch',
            'currentBranch',
            'currentSubBranch',
            'nextHopBranch',

            // Route
            'routeSteps'     => fn($q)     => $q->orderBy('sequence'),
            'routeSteps.fromBranch',
            'routeSteps.toBranch',

            // Activity (oldest â†’ newest for timeline)
            'trackingEvents' => fn($q) => $q->orderBy('created_at'),
        ]);

        // Fallback relation resolution if not automatically loaded
        if (! $shipment->pickupLocation && $shipment->pickup_location_id) {
            $loc = \Modules\Merchant\Models\MerchantPickupLocation::find($shipment->pickup_location_id);
            if ($loc) {
                $shipment->setRelation('pickupLocation', $loc);
            }
        }
        if (! $shipment->pickupLocation && $shipment->merchant) {
            $defaultLoc = $shipment->merchant->pickupLocations()->where('is_default', true)->first()
                ?? $shipment->merchant->pickupLocations()->first();
            if ($defaultLoc) {
                $shipment->setRelation('pickupLocation', $defaultLoc);
            }
        }
        if (! $shipment->originBranch && $shipment->origin_branch_id) {
            $branch = \Modules\Branch\Models\Branch::find($shipment->origin_branch_id);
            if ($branch) {
                $shipment->setRelation('originBranch', $branch);
            }
        }
        if (! $shipment->destinationBranch && $shipment->destination_branch_id) {
            $branch = \Modules\Branch\Models\Branch::find($shipment->destination_branch_id);
            if ($branch) {
                $shipment->setRelation('destinationBranch', $branch);
            }
        }
        if (! $shipment->currentBranch && $shipment->current_branch_id) {
            $branch = \Modules\Branch\Models\Branch::find($shipment->current_branch_id);
            if ($branch) {
                $shipment->setRelation('currentBranch', $branch);
            }
        }

        // is_transfer, hop_meta, etc. already on $appends â€“ no extra append() needed

        $payload = $shipment->toArray();

        $originId = (int) ($shipment->origin_branch_id ?? 0);
        $destId = (int) ($shipment->destination_branch_id ?? 0);
        $isSameBranchShipment = ($originId > 0 && $originId === $destId);

        // Ensure relations and friendly names are explicitly present in payload
        $originName = $shipment->originBranch?->name
            ?? DB::table('branches')->where('id', $shipment->origin_branch_id)->value('name')
            ?? DB::table('coverage_locations')->where('id', $shipment->origin_branch_id)->value('name');

        $destName = $shipment->destinationBranch?->name
            ?? DB::table('branches')->where('id', $shipment->destination_branch_id)->value('name')
            ?? DB::table('coverage_locations')->where('id', $shipment->destination_branch_id)->value('name');

        if ($isSameBranchShipment) {
            $destName = $originName ?? $destName;
        }

        $currentName = $shipment->currentBranch?->name
            ?? DB::table('branches')->where('id', $shipment->current_branch_id)->value('name')
            ?? DB::table('coverage_locations')->where('id', $shipment->current_branch_id)->value('name')
            ?? ($isSameBranchShipment ? $originName : null);

        if ($shipment->relationLoaded('originBranch') && $shipment->originBranch) {
            $payload['origin_branch'] = $shipment->originBranch->toArray();
        }
        if ($shipment->relationLoaded('destinationBranch') && $shipment->destinationBranch) {
            $payload['destination_branch'] = $isSameBranchShipment && $shipment->originBranch
                ? $shipment->originBranch->toArray()
                : $shipment->destinationBranch->toArray();
        }
        if ($shipment->relationLoaded('currentBranch') && $shipment->currentBranch) {
            $payload['current_branch'] = $shipment->currentBranch->toArray();
        }
        if ($shipment->relationLoaded('merchant') && $shipment->merchant) {
            $payload['merchant'] = $shipment->merchant->toArray();
            $payload['merchant_name'] = $shipment->merchant->name ?? $shipment->merchant->business_name;
        }
        if ($shipment->relationLoaded('pickupLocation') && $shipment->pickupLocation) {
            $payload['pickup_location'] = $shipment->pickupLocation->toArray();
            $payload['pickup_location_name'] = $shipment->pickupLocation->name;
        }

        $payload['origin_branch_name'] = $originName;
        $payload['destination_branch_name'] = $destName;
        $payload['current_branch_name'] = $currentName;

        if (is_string($payload['packet_products'] ?? null)) {
            $payload['packet_products'] = json_decode($payload['packet_products'], true) ?? [];
        }
        if (is_string($payload['delivery_charge_breakdown'] ?? null)) {
            $payload['delivery_charge_breakdown'] = json_decode(
                $payload['delivery_charge_breakdown'],
                true
            ) ?? null;
        }

        // Recalculate stale breakdown if origin and destination are the same branch
        // but breakdown was priced as inter-branch (or assigned to an inactive franchise like Banepa)
        $breakdownData = $payload['delivery_charge_breakdown'] ?? null;

        $breakdownSameBranch = $breakdownData['route']['same_branch']
            ?? (
                isset($breakdownData['pickup_branch']['id'], $breakdownData['delivery_branch']['id'])
                && (int) $breakdownData['pickup_branch']['id'] === (int) $breakdownData['delivery_branch']['id']
            );

        if (
            $isSameBranchShipment &&
            $breakdownData !== null &&
            $breakdownSameBranch === false
        ) {
            try {
                $pricingEngine = app(\Modules\Rate\Services\PricingEngineService::class);
                // When origin and destination branch are identical, calculate using pickup branch zone
                $freshBreakdown = $pricingEngine->calculate([
                    'pickup_latitude'   => (float) ($shipment->pickup_lat ?? 0),
                    'pickup_longitude'  => (float) ($shipment->pickup_lng ?? 0),
                    'delivery_latitude' => (float) ($shipment->pickup_lat ?? 0),
                    'delivery_longitude'=> (float) ($shipment->pickup_lng ?? 0),
                    'service_type'      => $shipment->service_type ?? 'standard',
                    'parcel_weight'     => (float) ($shipment->weight ?? 1.5),
                    'weight'            => (float) ($shipment->weight ?? 1.5),
                    'parcel_type'       => $shipment->parcel_type ?? 'non_fragile',
                    'payment_type'      => $shipment->payment_type ?? 'pod',
                    'pod_amount'        => (float) ($shipment->pod_amount ?? 0),
                ], $shipment->merchant_id ? (int) $shipment->merchant_id : null);

                if (! empty($freshBreakdown['final_price'])) {
                    $newDeliveryCharge = round((float) $freshBreakdown['final_price'], 2);
                    $freeBy = \Modules\Settlement\Services\SettlementWorkflowService::normalizeFreeBy(
                        $shipment->delivery_free_by ?? 'none'
                    );
                    $paidBy = strtolower((string) ($shipment->delivery_charge_paid_by ?? 'customer'));
                    $customerPaysDelivery = ($freeBy === 'none' && $paidBy === 'customer');
                    $podAmount = (float) ($shipment->pod_amount ?? 0);
                    $newTotalCollectable = $podAmount + ($customerPaysDelivery ? $newDeliveryCharge : 0.0);

                    $actualDistance = round($this->distanceKm(
                        (float) ($shipment->pickup_lat ?? 0),
                        (float) ($shipment->pickup_lng ?? 0),
                        (float) ($shipment->delivery_lat ?? 0),
                        (float) ($shipment->delivery_lng ?? 0)
                    ), 2);

                    $shipment->forceFill([
                        'delivery_charge_breakdown' => $freshBreakdown,
                        'delivery_charge'           => $newDeliveryCharge,
                        'route_distance_km'         => $actualDistance > 0 ? $actualDistance : $shipment->route_distance_km,
                        'total_collectable_amount'  => round($newTotalCollectable, 2),
                    ])->save();

                    $payload['delivery_charge_breakdown'] = $freshBreakdown;
                    $payload['delivery_charge'] = $newDeliveryCharge;
                    $payload['route_distance_km'] = $actualDistance > 0 ? $actualDistance : $shipment->route_distance_km;
                    $payload['total_collectable_amount'] = round($newTotalCollectable, 2);
                }
            } catch (\Throwable $e) {
                // Keep existing breakdown on calculation error
            }
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

        $originId = (int) ($shipment->origin_branch_id ?? 0);
        $destId = (int) ($shipment->destination_branch_id ?? 0);
        $isSameBranch = ($originId > 0 && $originId === $destId);

        if ($isSameBranch && ($totalDistance > 20 || $totalDistance <= 0)) {
            $directDist = $this->haversineKm(
                (float) ($shipment->pickup_lat ?? $originLat),
                (float) ($shipment->pickup_lng ?? $originLng),
                (float) ($shipment->delivery_lat ?? $destLat),
                (float) ($shipment->delivery_lng ?? $destLng)
            );
            if ($directDist > 0) {
                $totalDistance = round($directDist, 2);
            }
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

        $pickupBranchName = $raw['pickup_branch']['name'] ?? null;
        $deliveryBranchName = $raw['delivery_branch']['name'] ?? null;
        $sameBranch = (bool) (
            $raw['route']['same_branch']
            ?? (
                isset($raw['pickup_branch']['id'], $raw['delivery_branch']['id'])
                && (int) $raw['pickup_branch']['id'] === (int) $raw['delivery_branch']['id']
            )
        );

        $routeBaseRate = (float) (
            $b['route_base_rate']['total']
            ?? $b['route_base_rate']['amount']
            ?? $raw['route']['base_rate']
            ?? 0
        );

        return [
            'pickup_branch_name'    => $pickupBranchName,
            'delivery_branch_name'  => $deliveryBranchName,
            'same_branch'           => $sameBranch,
            'route_base_rate'       => $routeBaseRate,
            'base_pickup_fee'       => (float) ($shipment->pickup_fee ?? 0),
            'base_delivery_fee'     => $routeBaseRate,
            'base_transfer_fee'     => 0,
            'pickup_extra_charge'   => 0,
            'delivery_extra_charge' => (float) ($b['extra_delivery_distance']['total'] ?? 0),
            'extra_distance_km'     => (float) ($b['extra_delivery_distance']['extra_distance_km'] ?? 0),
            'extra_distance_rate'   => (float) ($b['extra_delivery_distance']['rate_per_km'] ?? 0),
            'weight_charge'         => (float) ($b['additional_weight']['total'] ?? 0),
            'fragile_charge'        => (float) ($b['fragile']['total'] ?? 0),
            'same_day_charge'       => (float) ($b['same_day']['total'] ?? 0),
            'express_charge'        => (float) ($b['express']['total'] ?? 0),
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
