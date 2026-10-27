<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Merchant\Models\Merchant;
use Modules\Rate\Models\BranchTransferRoute;

final class BranchAssignmentService
{
    /**
     * Resolve merchant pickup location.
     *
     * Priority:
     *
     * 1. Explicit pickup location
     * 2. Default pickup location
     * 3. First pickup location
     */
    public function resolveMerchantPickupLocation(
        Merchant $merchant,
        ?int $pickupLocationId = null
    ): ?object {
        $query = DB::table('merchant_pickup_locations')
            ->where(
                'merchant_id',
                $merchant->id
            );

        if ($pickupLocationId !== null) {
            $pickupLocation =
                (clone $query)
                    ->where(
                        'id',
                        $pickupLocationId
                    )
                    ->first();

            if ($pickupLocation) {
                return $pickupLocation;
            }
        }

        $pickupLocation =
            (clone $query)
                ->where(
                    'is_default',
                    true
                )
                ->first();

        if ($pickupLocation) {
            return $pickupLocation;
        }

        return (clone $query)
            ->orderBy('id')
            ->first();
    }

    /**
     * Resolve origin branch.
     *
     * Priority:
     *
     * 1. Linked main branch
     * 2. Linked sub-branch
     * 3. Nearest operational branch
     */
    public function resolveOrigin(
        Merchant $merchant,
        ?object $pickupLocation
    ): array {

        $lat =
            $pickupLocation?->latitude
            ??
            $pickupLocation?->lat
            ??
            $merchant->latitude
            ??
            $merchant->pickup_lat
            ??
            null;

        $lng =
            $pickupLocation?->longitude
            ??
            $pickupLocation?->lng
            ??
            $merchant->longitude
            ??
            $merchant->pickup_lng
            ??
            null;

        $city =
            $pickupLocation?->city
            ??
            $merchant->city
            ??
            null;

        $area =
            $pickupLocation?->area
            ??
            $merchant->area
            ??
            null;

        /*
        |--------------------------------------------------------------------------
        | LINKED MAIN BRANCH
        |--------------------------------------------------------------------------
        */

        if ($pickupLocation) {

            $linkedBranchId =
                ! empty(
                    $pickupLocation->branch_id
                )
                    ? (int) $pickupLocation->branch_id
                    : null;

            if ($linkedBranchId !== null) {

                $linkedBranch =
                    $this->findActiveBranch(
                        $linkedBranchId
                    );

                if ($linkedBranch) {
                    return $this->asBranchPayload(
                        $linkedBranch
                    );
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | LINKED SUB-BRANCH
        |--------------------------------------------------------------------------
        */

        if (
            $pickupLocation
            &&
            empty($pickupLocation->branch_id)
            &&
            ! empty($pickupLocation->sub_branch_id)
        ) {
            $linkedSubBranchId =
                (int) $pickupLocation->sub_branch_id;

            $linkedSubBranch =
                $this->findActiveBranch(
                    $linkedSubBranchId
                );

            if ($linkedSubBranch) {
                return $this->asBranchPayload(
                    $linkedSubBranch
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | NEAREST OPERATIONAL BRANCH
        |--------------------------------------------------------------------------
        */

        return $this->nearestBranch(
            lat:
                $lat,

            lng:
                $lng,

            city:
                $city,

            area:
                $area,
        );
    }

    /**
     * Resolve destination branch/sub-branch.
     */
    public function resolveDestination(
        array $delivery
    ): array {
        return $this->nearestBranch(
            lat:
                $delivery['latitude']
                ??
                $delivery['delivery_lat']
                ??
                null,

            lng:
                $delivery['longitude']
                ??
                $delivery['delivery_lng']
                ??
                null,

            city:
                $delivery['city']
                ??
                $delivery['delivery_city']
                ??
                $delivery['customer_city']
                ??
                null,

            area:
                $delivery['area']
                ??
                $delivery['delivery_area']
                ??
                $delivery['customer_area']
                ??
                null,
        );
    }

    /**
     * Build logical shipment route.
     *
     * Calculates distance based on:
     * - Branch-to-branch transfer route (road distance for standard, aerial distance for flight)
     * - Last-mile distance from destination branch to customer delivery address
     */
    public function buildRoute(
        array $origin,
        array $destination,
        ?float $deliveryLat = null,
        ?float $deliveryLng = null,
        ?string $serviceType = 'standard'
    ): array {

        $originNode =
            $origin['sub_branch_id']
            ??
            $origin['branch_id']
            ??
            null;

        $destinationNode =
            $destination['sub_branch_id']
            ??
            $destination['branch_id']
            ??
            null;

        $requiresTransfer =
            $originNode !== null
            &&
            $destinationNode !== null
            &&
            (int) $originNode
            !==
            (int) $destinationNode;

        // Origin Branch coordinates
        $originBranchObj = $origin['sub_branch'] ?? $origin['branch'] ?? null;
        if (! $originBranchObj && ! empty($originNode)) {
            $originBranchObj = $this->branchWithoutActiveFilter((int) $originNode);
        }
        $originLat = $originBranchObj ? $this->branchLatitude($originBranchObj) : null;
        $originLng = $originBranchObj ? $this->branchLongitude($originBranchObj) : null;

        $originToCustomerDistance = 0.0;
        if ($originLat !== null && $originLng !== null && $deliveryLat !== null && $deliveryLng !== null) {
            $originToCustomerDistance = $this->distanceKm($originLat, $originLng, $deliveryLat, $deliveryLng);
        }

        // Destination Branch coordinates
        $destBranchObj = $destination['sub_branch'] ?? $destination['branch'] ?? null;
        if (! $destBranchObj && ! empty($destinationNode)) {
            $destBranchObj = $this->branchWithoutActiveFilter((int) $destinationNode);
        }
        $destLat = $destBranchObj ? $this->branchLatitude($destBranchObj) : null;
        $destLng = $destBranchObj ? $this->branchLongitude($destBranchObj) : null;

        $destToCustomerDistance = 0.0;
        if ($destLat !== null && $destLng !== null && $deliveryLat !== null && $deliveryLng !== null) {
            $destToCustomerDistance = $this->distanceKm($destLat, $destLng, $deliveryLat, $deliveryLng);
        }

        $baseDistanceKm = 5.0;
        try {
            // Admin-managed column first (base_distance_km), legacy column second.
            $activeSetting = DB::table('pricing_settings')->where('is_active', true)->orderByDesc('id')->first();
            $baseDistanceKm = max(0.0, (float) ($activeSetting->base_distance_km ?? $activeSetting->included_delivery_distance_km ?? 5.0));
        } catch (\Throwable $e) {}

        $extraMileKm = max(0.0, round($destToCustomerDistance - $baseDistanceKm, 2));

        $transferRouteId = null;
        $routeCode = null;
        $routeName = null;
        $pathText = null;
        $branchToBranchDistance = 0.0;
        $transportMode = strtolower((string) ($serviceType ?? 'standard')) === 'flight' ? 'flight' : 'road';

        if ($requiresTransfer && $originNode && $destinationNode) {
            try {
                $transferRoute = $this->findTransferRoute(
                    (int) $originNode,
                    (int) $destinationNode,
                    (string) ($serviceType ?? 'standard')
                );

                if ($transferRoute) {
                    $transferRouteId = (int) $transferRoute->id;
                    $routeCode = (string) $transferRoute->route_code;
                    $routeName = (string) $transferRoute->name;
                    $pathText = $transferRoute->path_text ?? (method_exists($transferRoute, 'getPathText') ? $transferRoute->getPathText() : null);
                    $branchToBranchDistance = (float) $transferRoute->getTotalDistanceKm();

                    // Check transport mode on lanes (road vs flight)
                    $lanes = $transferRoute->orderedLanes();
                    $isFlightLane = $lanes->contains(function ($l) {
                        return strtolower((string) ($l->transport_mode ?? '')) === 'flight'
                            || strtolower((string) ($l->service_type ?? '')) === 'flight';
                    });
                    $transportMode = ($isFlightLane || strtolower((string) ($serviceType ?? '')) === 'flight')
                        ? 'flight'
                        : 'road';
                } else {
                    // Fallback to Haversine coordinate distance between origin branch and destination branch
                    if ($originLat !== null && $originLng !== null && $destLat !== null && $destLng !== null) {
                        $branchToBranchDistance = $this->distanceKm($originLat, $originLng, $destLat, $destLng);
                    }
                }
            } catch (\Throwable $e) {
                if ($originLat !== null && $originLng !== null && $destLat !== null && $destLng !== null) {
                    $branchToBranchDistance = $this->distanceKm($originLat, $originLng, $destLat, $destLng);
                }
            }
        }

        // Total distance covered = Branch-to-Branch (Road or Flight) + Destination to Customer (Last Mile)
        $totalDistanceKm = $requiresTransfer
            ? round($branchToBranchDistance + $destToCustomerDistance, 2)
            : ($destToCustomerDistance > 0 ? $destToCustomerDistance : $originToCustomerDistance);

        return [
            'origin_branch_id' =>
                $origin['branch_id'] ?? null,

            'origin_sub_branch_id' =>
                $origin['sub_branch_id'] ?? null,

            'destination_branch_id' =>
                $destination['branch_id'] ?? null,

            'destination_sub_branch_id' =>
                $destination['sub_branch_id'] ?? null,

            'requires_transfer' =>
                $requiresTransfer,

            'route_id' =>
                null,

            'transfer_route_id' =>
                $transferRouteId,

            'route_code' =>
                $routeCode,

            'route_name' =>
                $routeName,

            'path_text' =>
                $pathText,

            'transport_mode' =>
                $transportMode,

            'branch_to_branch_distance_km' =>
                $branchToBranchDistance,

            'destination_to_customer_km' =>
                $destToCustomerDistance,

            'base_distance_km' =>
                $baseDistanceKm,

            'extra_distance_km' =>
                $extraMileKm,

            'distance_km' =>
                $totalDistanceKm,

            'total_distance_km' =>
                $totalDistanceKm,

            'origin_to_customer_km' =>
                $originToCustomerDistance,

            'steps' =>
                $requiresTransfer
                    ? [
                        'Pickup Location',
                        'Origin Branch/Sub-Branch',
                        'Transfer',
                        'Destination Branch/Sub-Branch',
                        'Customer',
                    ]
                    : [
                        'Pickup Location',
                        'Branch/Sub-Branch',
                        'Customer',
                    ],
        ];
    }

    /**
     * Find active transfer route between two branches/coverage locations.
     */
    public function findTransferRoute(
        int $originBranchId,
        int $destinationBranchId,
        string $serviceType = 'standard'
    ): ?BranchTransferRoute {
        $serviceType = strtolower(trim($serviceType)) ?: 'standard';

        $originBranch = DB::table('branches')->where('id', $originBranchId)->first();
        $destBranch = DB::table('branches')->where('id', $destinationBranchId)->first();

        $fromCoverage = $originBranch?->coverage_location_id ?? $originBranchId;
        $toCoverage = $destBranch?->coverage_location_id ?? $destinationBranchId;

        $candidates = BranchTransferRoute::query()
            ->where('is_active', true)
            ->where(function ($q) use ($serviceType) {
                $q->where('service_type', $serviceType);
                if ($serviceType !== 'standard') {
                    $q->orWhere('service_type', 'standard');
                }
            })
            ->with(['routeLanes.lane.fromBranch', 'routeLanes.lane.toBranch', 'lane.fromBranch', 'lane.toBranch'])
            ->orderByRaw("CASE WHEN service_type = ? THEN 0 ELSE 1 END", [$serviceType])
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $exact = null;
        $partial = null;

        foreach ($candidates as $route) {
            $path = array_map('intval', $route->getPathBranchIds());
            if (empty($path)) {
                continue;
            }

            $pathStart = (int) $path[0];
            $pathEnd = (int) end($path);

            if (($pathStart === (int) $fromCoverage || $pathStart === $originBranchId)
                && ($pathEnd === (int) $toCoverage || $pathEnd === $destinationBranchId)) {
                $exact = $route;
                break;
            }

            $idxFrom = array_search((int) $fromCoverage, $path, true);
            if ($idxFrom === false) {
                $idxFrom = array_search($originBranchId, $path, true);
            }

            $idxTo = array_search((int) $toCoverage, $path, true);
            if ($idxTo === false) {
                $idxTo = array_search($destinationBranchId, $path, true);
            }

            if ($idxFrom !== false && $idxTo !== false && $idxFrom < $idxTo && $partial === null) {
                $partial = $route;
            }
        }

        return $exact ?? $partial;
    }

    /**
     * Get pickup coordinates.
     */
    public function pickupCoordinates(
        ?object $pickupLocation,
        Merchant $merchant
    ): array {
        return [
            'lat' =>
                $pickupLocation?->latitude
                ??
                $pickupLocation?->lat
                ??
                $merchant->latitude
                ??
                $merchant->pickup_lat
                ??
                null,

            'lng' =>
                $pickupLocation?->longitude
                ??
                $pickupLocation?->lng
                ??
                $merchant->longitude
                ??
                $merchant->pickup_lng
                ??
                null,
        ];
    }

    /**
     * Calculate Haversine distance.
     */
    public function distanceKm(
        ?float $lat1,
        ?float $lng1,
        ?float $lat2,
        ?float $lng2
    ): float {
        if (
            $lat1 === null
            ||
            $lng1 === null
            ||
            $lat2 === null
            ||
            $lng2 === null
        ) {
            return PHP_FLOAT_MAX;
        }

        $earthRadius = 6371;

        $dLat =
            deg2rad(
                $lat2 - $lat1
            );

        $dLng =
            deg2rad(
                $lng2 - $lng1
            );

        $a =
            sin($dLat / 2) ** 2
            +
            cos(deg2rad($lat1))
            *
            cos(deg2rad($lat2))
            *
            sin($dLng / 2) ** 2;

        $c =
            2
            *
            atan2(
                sqrt($a),
                sqrt(1 - $a)
            );

        return round(
            $earthRadius * $c,
            2
        );
    }

    /**
     * Find nearest operational branch.
     */
    private function nearestBranch(
        mixed $lat,
        mixed $lng,
        mixed $city = null,
        mixed $area = null
    ): array {

        $query =
            DB::table('branches');

        $this->applyActiveFilter(
            $query
        );

        $branches =
            $query->get();

        if ($branches->isEmpty()) {
            return $this->emptyBranchPayload();
        }

        /*
        |--------------------------------------------------------------------------
        | COORDINATE SEARCH
        |--------------------------------------------------------------------------
        */

        if (
            $lat !== null
            &&
            $lng !== null
        ) {
            $nearest =
                $branches
                    ->sortBy(
                        function ($branch) use (
                            $lat,
                            $lng
                        ) {
                            return $this->distanceKm(
                                (float) $lat,
                                (float) $lng,
                                $this->branchLatitude(
                                    $branch
                                ),
                                $this->branchLongitude(
                                    $branch
                                )
                            );
                        }
                    )
                    ->first();

            if ($nearest) {
                return $this->asBranchPayload(
                    $nearest
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | CITY
        |--------------------------------------------------------------------------
        */

        if ($city !== null) {

            $cityBranch =
                $branches->first(
                    function ($branch) use (
                        $city
                    ) {
                        return isset(
                            $branch->city
                        )
                        &&
                        stripos(
                            (string)
                                $branch->city,
                            (string)
                                $city
                        ) !== false;
                    }
                );

            if ($cityBranch) {
                return $this->asBranchPayload(
                    $cityBranch
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | AREA
        |--------------------------------------------------------------------------
        */

        if ($area !== null) {

            $areaBranch =
                $branches->first(
                    function ($branch) use (
                        $area
                    ) {
                        return isset(
                            $branch->area
                        )
                        &&
                        stripos(
                            (string)
                                $branch->area,
                            (string)
                                $area
                        ) !== false;
                    }
                );

            if ($areaBranch) {
                return $this->asBranchPayload(
                    $areaBranch
                );
            }
        }

        return $this->asBranchPayload(
            $branches->first()
        );
    }

    /**
     * Find operational branch by ID.
     */
    private function findActiveBranch(
        int $branchId
    ): ?object {
        if ($branchId <= 0) {
            return null;
        }

        $query =
            DB::table('branches')
                ->where(
                    'branches.id',
                    $branchId
                );

        $this->applyActiveFilter(
            $query
        );

        return $query->first();
    }

    /**
     * Apply operational branch filter.
     */
    private function applyActiveFilter(
        Builder $query
    ): void {
        $query->where(
            function (Builder $q) {

                $q->where(
                    'branches.status',
                    'active'
                );

                $q->orWhere(
                    function (Builder $franchise) {

                        $franchise
                            ->where(
                                'branches.status',
                                'approved'
                            )
                            ->where(
                                'branches.account_invitation_status',
                                'account_configured'
                            );
                    }
                );
            }
        );
    }

    /**
     * Convert branch row to payload.
     */
    private function asBranchPayload(
        ?object $branch
    ): array {

        if (! $branch) {
            return $this->emptyBranchPayload();
        }

        $type =
            strtolower(
                (string) (
                    $branch->type
                    ??
                    ''
                )
            );

        $isSubBranch =
            $type === 'sub_branch'
            ||
            ! empty(
                $branch->parent_id
            );

        if ($isSubBranch) {

            $parentId =
                ! empty(
                    $branch->parent_id
                )
                    ? (int) $branch->parent_id
                    : null;

            return [
                'branch_id' =>
                    $parentId,

                'sub_branch_id' =>
                    (int) $branch->id,

                'branch' =>
                    $this->branchWithoutActiveFilter(
                        $parentId
                    ),

                'sub_branch' =>
                    $branch,
            ];
        }

        return [
            'branch_id' =>
                (int) $branch->id,

            'sub_branch_id' =>
                null,

            'branch' =>
                $branch,

            'sub_branch' =>
                null,
        ];
    }

    private function branchWithoutActiveFilter(
        ?int $id
    ): ?object {
        if (! $id) {
            return null;
        }

        return DB::table('branches')
            ->where(
                'id',
                $id
            )
            ->first();
    }

    private function branchLatitude(
        object $branch
    ): ?float {
        if (
            isset($branch->latitude)
            &&
            $branch->latitude !== null
        ) {
            return (float)
                $branch->latitude;
        }

        if (
            isset($branch->office_latitude)
            &&
            $branch->office_latitude !== null
        ) {
            return (float)
                $branch->office_latitude;
        }

        if (
            isset($branch->lat)
            &&
            $branch->lat !== null
        ) {
            return (float)
                $branch->lat;
        }

        return null;
    }

    private function branchLongitude(
        object $branch
    ): ?float {
        if (
            isset($branch->longitude)
            &&
            $branch->longitude !== null
        ) {
            return (float)
                $branch->longitude;
        }

        if (
            isset($branch->office_longitude)
            &&
            $branch->office_longitude !== null
        ) {
            return (float)
                $branch->office_longitude;
        }

        if (
            isset($branch->lng)
            &&
            $branch->lng !== null
        ) {
            return (float)
                $branch->lng;
        }

        return null;
    }

    private function emptyBranchPayload(): array
    {
        return [
            'branch_id' =>
                null,

            'sub_branch_id' =>
                null,

            'branch' =>
                null,

            'sub_branch' =>
                null,
        ];
    }
}