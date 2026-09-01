<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Merchant\Models\Merchant;

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
     */
    public function buildRoute(
        array $origin,
        array $destination
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