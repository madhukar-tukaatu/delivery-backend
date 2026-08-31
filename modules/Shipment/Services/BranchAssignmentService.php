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
     * 1. Explicit pickup location
     * 2. Default pickup location
     * 3. First pickup location
     */
    public function resolveMerchantPickupLocation(
        Merchant $merchant,
        ?int $pickupLocationId = null
    ): ?object {
        $query = DB::table('merchant_pickup_locations')
            ->where('merchant_id', $merchant->id);

        /*
        |--------------------------------------------------------------------------
        | 1. Explicit pickup location
        |--------------------------------------------------------------------------
        */

        if ($pickupLocationId !== null) {
            $pickupLocation = (clone $query)
                ->where('id', $pickupLocationId)
                ->first();

            if ($pickupLocation) {
                return $pickupLocation;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Default pickup location
        |--------------------------------------------------------------------------
        */

        $pickupLocation = (clone $query)
            ->where('is_default', true)
            ->first();

        if ($pickupLocation) {
            return $pickupLocation;
        }

        /*
        |--------------------------------------------------------------------------
        | 3. First pickup location
        |--------------------------------------------------------------------------
        */

        return (clone $query)
            ->orderBy('id')
            ->first();
    }

    /**
     * Resolve origin branch/sub-branch.
     *
     * Priority:
     *
     * 1. Pickup location linked sub-branch
     * 2. Pickup location linked main branch
     * 3. Merchant default sub-branch
     * 4. Merchant default main branch
     * 5. Nearest active branch
     *
     * IMPORTANT:
     *
     * A linked branch has priority.
     *
     * We only fall back to another branch when the
     * linked branch does not exist or is inactive.
     */
    public function resolveOrigin(
        Merchant $merchant,
        ?object $pickupLocation
    ): array {
        /*
        |--------------------------------------------------------------------------
        | Coordinates used for fallback
        |--------------------------------------------------------------------------
        */

        $lat =
            $pickupLocation?->latitude
            ?? $pickupLocation?->lat
            ?? $merchant->latitude
            ?? $merchant->pickup_lat
            ?? null;

        $lng =
            $pickupLocation?->longitude
            ?? $pickupLocation?->lng
            ?? $merchant->longitude
            ?? $merchant->pickup_lng
            ?? null;

        $city =
            $pickupLocation?->city
            ?? $merchant->city
            ?? null;

        $area =
            $pickupLocation?->area
            ?? $merchant->area
            ?? null;

        /*
        |--------------------------------------------------------------------------
        | 1. PICKUP LOCATION LINKED SUB-BRANCH
        |--------------------------------------------------------------------------
        */

        if ($pickupLocation) {
            $linkedSubBranchId =
                !empty($pickupLocation->sub_branch_id)
                    ? (int) $pickupLocation->sub_branch_id
                    : null;

            if ($linkedSubBranchId !== null) {
                $subBranch = $this->findActiveBranch(
                    $linkedSubBranchId
                );

                if ($subBranch) {
                    return $this->asBranchPayload(
                        $subBranch
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Linked sub-branch is inactive.
                |
                | DO NOT use it.
                |
                | Continue to linked main branch / defaults / nearest.
                |--------------------------------------------------------------------------
                */
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 2. PICKUP LOCATION LINKED MAIN BRANCH
        |--------------------------------------------------------------------------
        */

        if ($pickupLocation) {
            $linkedBranchId =
                !empty($pickupLocation->branch_id)
                    ? (int) $pickupLocation->branch_id
                    : null;

            if ($linkedBranchId !== null) {
                $branch = $this->findActiveBranch(
                    $linkedBranchId
                );

                if ($branch) {
                    return $this->asBranchPayload(
                        $branch
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Linked branch is inactive.
                |
                | Continue to merchant default / nearest.
                |--------------------------------------------------------------------------
                */
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 3. MERCHANT DEFAULT SUB-BRANCH
        |--------------------------------------------------------------------------
        */

        $defaultSubBranchId =
            !empty($merchant->default_sub_branch_id)
                ? (int) $merchant->default_sub_branch_id
                : null;

        if ($defaultSubBranchId !== null) {
            $subBranch = $this->findActiveBranch(
                $defaultSubBranchId
            );

            if ($subBranch) {
                return $this->asBranchPayload(
                    $subBranch
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 4. MERCHANT DEFAULT MAIN BRANCH
        |--------------------------------------------------------------------------
        */

        $defaultBranchId =
            !empty($merchant->default_branch_id)
                ? (int) $merchant->default_branch_id
                : null;

        if ($defaultBranchId !== null) {
            $branch = $this->findActiveBranch(
                $defaultBranchId
            );

            if ($branch) {
                return $this->asBranchPayload(
                    $branch
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 5. NEAREST ACTIVE BRANCH
        |--------------------------------------------------------------------------
        */

        return $this->nearestBranch(
            $lat,
            $lng,
            $city,
            $area
        );
    }

    /**
     * Resolve destination branch/sub-branch.
     *
     * Destination is always selected from active branches.
     */
    public function resolveDestination(
        array $delivery
    ): array {
        return $this->nearestBranch(
            $delivery['latitude']
                ?? $delivery['delivery_lat']
                ?? null,

            $delivery['longitude']
                ?? $delivery['delivery_lng']
                ?? null,

            $delivery['city']
                ?? $delivery['delivery_city']
                ?? $delivery['customer_city']
                ?? null,

            $delivery['area']
                ?? $delivery['delivery_area']
                ?? $delivery['customer_area']
                ?? null
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
            ?? $origin['branch_id']
            ?? null;

        $destinationNode =
            $destination['sub_branch_id']
            ?? $destination['branch_id']
            ?? null;

        $requiresTransfer =
            $originNode !== null
            && $destinationNode !== null
            && (int) $originNode !== (int) $destinationNode;

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
                ?? $pickupLocation?->lat
                ?? $merchant->latitude
                ?? $merchant->pickup_lat
                ?? null,

            'lng' =>
                $pickupLocation?->longitude
                ?? $pickupLocation?->lng
                ?? $merchant->longitude
                ?? $merchant->pickup_lng
                ?? null,
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
            $lat1 === null ||
            $lng1 === null ||
            $lat2 === null ||
            $lng2 === null
        ) {
            return PHP_FLOAT_MAX;
        }

        $earthRadius = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a =
            sin($dLat / 2) ** 2
            +
            cos(deg2rad($lat1))
            * cos(deg2rad($lat2))
            * sin($dLng / 2) ** 2;

        $c = 2 * atan2(
            sqrt($a),
            sqrt(1 - $a)
        );

        return round(
            $earthRadius * $c,
            2
        );
    }

    /**
     * Find nearest active branch.
     *
     * Selection order:
     *
     * 1. Coordinate distance
     * 2. City
     * 3. Area
     * 4. First active branch
     */
    private function nearestBranch(
        $lat,
        $lng,
        $city = null,
        $area = null
    ): array {
        $query = DB::table('branches');

        /*
        |--------------------------------------------------------------------------
        | ACTIVE BRANCHES ONLY
        |--------------------------------------------------------------------------
        */

        $this->applyActiveFilter($query);

        $branches = $query->get();

        if ($branches->isEmpty()) {
            return $this->emptyBranchPayload();
        }

        /*
        |--------------------------------------------------------------------------
        | 1. TRUE NEAREST BRANCH BY COORDINATES
        |--------------------------------------------------------------------------
        */

        if (
            $lat !== null &&
            $lng !== null
        ) {
            $nearest = $branches
                ->sortBy(function ($branch) use ($lat, $lng) {
                    return $this->distanceKm(
                        (float) $lat,
                        (float) $lng,

                        isset($branch->latitude)
                            ? (float) $branch->latitude
                            : null,

                        isset($branch->longitude)
                            ? (float) $branch->longitude
                            : null
                    );
                })
                ->first();

            if ($nearest) {
                return $this->asBranchPayload(
                    $nearest
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 2. CITY FALLBACK
        |--------------------------------------------------------------------------
        */

        if ($city) {
            $cityBranch = $branches
                ->first(function ($branch) use ($city) {
                    return isset($branch->city)
                        && stripos(
                            (string) $branch->city,
                            (string) $city
                        ) !== false;
                });

            if ($cityBranch) {
                return $this->asBranchPayload(
                    $cityBranch
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 3. AREA FALLBACK
        |--------------------------------------------------------------------------
        */

        if ($area) {
            $areaBranch = $branches
                ->first(function ($branch) use ($area) {
                    return isset($branch->area)
                        && stripos(
                            (string) $branch->area,
                            (string) $area
                        ) !== false;
                });

            if ($areaBranch) {
                return $this->asBranchPayload(
                    $areaBranch
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 4. FINAL ACTIVE BRANCH
        |--------------------------------------------------------------------------
        */

        return $this->asBranchPayload(
            $branches->first()
        );
    }

    /**
     * Find a branch only when it is active.
     */
    private function findActiveBranch(
        int $branchId
    ): ?object {
        $query = DB::table('branches')
            ->where('id', $branchId);

        $this->applyActiveFilter($query);

        return $query->first();
    }

    /**
     * Apply active filter.
     *
     * Supports:
     *
     * branches.is_active
     * OR
     * branches.status
     */
    private function applyActiveFilter(
        Builder $query
    ): void {
        $schema = DB::getSchemaBuilder();

        $hasIsActive = $schema->hasColumn(
            'branches',
            'is_active'
        );

        $hasStatus = $schema->hasColumn(
            'branches',
            'status'
        );

        /*
        |--------------------------------------------------------------------------
        | Preferred: is_active
        |--------------------------------------------------------------------------
        */

        if ($hasIsActive) {
            $query->where(
                'branches.is_active',
                true
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Fallback: status
        |--------------------------------------------------------------------------
        */

        if ($hasStatus) {
            $query->where(
                'branches.status',
                'active'
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        |--------------------------------------------------------------------------
        |
        | If neither column exists, the application cannot distinguish
        | active from inactive branches.
        |
        | In that case we DO NOT pretend that every branch is active.
        |
        |--------------------------------------------------------------------------
        */

        throw new \RuntimeException(
            'Unable to determine branch activity. ' .
            'The branches table must contain either is_active or status.'
        );
    }

    /**
     * Convert branch row to standard payload.
     */
    private function asBranchPayload(
        ?object $branch
    ): array {
        if (!$branch) {
            return $this->emptyBranchPayload();
        }

        $type = strtolower(
            (string) (
                $branch->type
                ?? ''
            )
        );

        $isSubBranch =
            $type === 'sub_branch'
            || !empty($branch->parent_id);

        /*
        |--------------------------------------------------------------------------
        | SUB-BRANCH
        |--------------------------------------------------------------------------
        */

        if ($isSubBranch) {
            $parentId =
                !empty($branch->parent_id)
                    ? (int) $branch->parent_id
                    : null;

            return [
                'branch_id' =>
                    $parentId,

                'sub_branch_id' =>
                    (int) $branch->id,

                /*
                | IMPORTANT:
                | Get parent WITHOUT requiring parent to be active
                | here. The selected sub-branch has already passed
                | the active check.
                */

                'branch' =>
                    $this->branchWithoutActiveFilter(
                        $parentId
                    ),

                'sub_branch' =>
                    $branch,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | MAIN BRANCH
        |--------------------------------------------------------------------------
        */

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

    /**
     * Get parent branch without applying the active filter.
     *
     * This is only used to build the relationship payload.
     */
    private function branchWithoutActiveFilter(
        ?int $id
    ): ?object {
        if (!$id) {
            return null;
        }

        return DB::table('branches')
            ->where('id', $id)
            ->first();
    }

    /**
     * Empty branch payload.
     */
    private function emptyBranchPayload(): array
    {
        return [
            'branch_id' => null,
            'sub_branch_id' => null,
            'branch' => null,
            'sub_branch' => null,
        ];
    }
}