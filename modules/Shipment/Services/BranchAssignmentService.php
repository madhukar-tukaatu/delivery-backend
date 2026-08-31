<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

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
     * 3. First available pickup location
     */
    public function resolveMerchantPickupLocation(
        Merchant $merchant,
        ?int $pickupLocationId = null
    ): ?object {
        $query = DB::table('merchant_pickup_locations')
            ->where('merchant_id', $merchant->id);

        if ($pickupLocationId) {
            $row = (clone $query)
                ->where('id', $pickupLocationId)
                ->first();

            if ($row) {
                return $row;
            }
        }

        $row = (clone $query)
            ->where('is_default', true)
            ->first();

        if ($row) {
            return $row;
        }

        return (clone $query)
            ->orderBy('id')
            ->first();
    }

    /**
     * Resolve origin branch/sub-branch.
     *
     * Priority:
     *
     * 1. Branch linked to merchant pickup location
     *    - use it if active
     *    - if inactive, find nearest active branch
     *
     * 2. Merchant default branch
     *    - use it if active
     *    - if inactive, find nearest active branch
     *
     * 3. Nearest active branch
     */
    public function resolveOrigin(
        Merchant $merchant,
        ?object $pickupLocation
    ): array {

        /*
        |--------------------------------------------------------------------------
        | 1. PICKUP LOCATION LINKED BRANCH
        |--------------------------------------------------------------------------
        */

        if ($pickupLocation) {

            $linkedBranchId =
                $pickupLocation->branch_id
                ?? null;

            $linkedSubBranchId =
                $pickupLocation->sub_branch_id
                ?? null;

            /*
            |--------------------------------------------------------------------------
            | Linked sub-branch has priority.
            |--------------------------------------------------------------------------
            */

            if ($linkedSubBranchId) {

                $subBranch =
                    $this->findActiveBranch(
                        (int) $linkedSubBranchId
                    );

                if ($subBranch) {

                    return $this->asBranchPayload(
                        $subBranch
                    );
                }

                /*
                |----------------------------------------------------------------------
                | Linked sub-branch is inactive.
                | Fall through to nearest active branch.
                |----------------------------------------------------------------------
                */
            }

            /*
            |--------------------------------------------------------------------------
            | Linked main branch.
            |--------------------------------------------------------------------------
            */

            if ($linkedBranchId) {

                $branch =
                    $this->findActiveBranch(
                        (int) $linkedBranchId
                    );

                if ($branch) {

                    return $this->asBranchPayload(
                        $branch
                    );
                }

                /*
                |----------------------------------------------------------------------
                | Linked branch is inactive.
                | Fall through to nearest active branch.
                |----------------------------------------------------------------------
                */
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 2. MERCHANT DEFAULT BRANCH
        |--------------------------------------------------------------------------
        */

        $defaultSubBranchId =
            $merchant->default_sub_branch_id
            ?? null;

        $defaultBranchId =
            $merchant->default_branch_id
            ?? null;

        /*
        |--------------------------------------------------------------------------
        | Default sub-branch
        |--------------------------------------------------------------------------
        */

        if ($defaultSubBranchId) {

            $defaultSubBranch =
                $this->findActiveBranch(
                    (int) $defaultSubBranchId
                );

            if ($defaultSubBranch) {

                return $this->asBranchPayload(
                    $defaultSubBranch
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Default main branch
        |--------------------------------------------------------------------------
        */

        if ($defaultBranchId) {

            $defaultBranch =
                $this->findActiveBranch(
                    (int) $defaultBranchId
                );

            if ($defaultBranch) {

                return $this->asBranchPayload(
                    $defaultBranch
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 3. NEAREST ACTIVE BRANCH
        |--------------------------------------------------------------------------
        */

        return $this->nearestBranch(
            $pickupLocation->latitude
                ?? $pickupLocation->lat
                ?? $merchant->latitude
                ?? $merchant->pickup_lat
                ?? null,

            $pickupLocation->longitude
                ?? $pickupLocation->lng
                ?? $merchant->longitude
                ?? $merchant->pickup_lng
                ?? null,

            $pickupLocation->city
                ?? $merchant->city
                ?? null,

            $pickupLocation->area
                ?? $merchant->area
                ?? null
        );
    }

    /**
     * Resolve destination branch/sub-branch.
     *
     * Only active branches are considered.
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
                ?? $delivery['customer_city']
                ?? null,

            $delivery['area']
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

        $originMain =
            $origin['sub_branch_id']
            ?? $origin['branch_id']
            ?? null;

        $destinationMain =
            $destination['sub_branch_id']
            ?? $destination['branch_id']
            ?? null;

        $requiresTransfer =
            $originMain !== null
            &&
            $destinationMain !== null
            &&
            (int) $originMain !== (int) $destinationMain;

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
                $pickupLocation->latitude
                ?? $pickupLocation->lat
                ?? $merchant->latitude
                ?? $merchant->pickup_lat
                ?? null,

            'lng' =>
                $pickupLocation->longitude
                ?? $pickupLocation->lng
                ?? $merchant->longitude
                ?? $merchant->pickup_lng
                ?? null,
        ];
    }

    /**
     * Haversine distance.
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
            *
            cos(deg2rad($lat2))
            *
            sin($dLng / 2) ** 2;

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
     * IMPORTANT:
     *
     * This method ONLY considers active branches.
     *
     * We support different possible active/status
     * columns because the current branches schema
     * may vary.
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

        /*
        |--------------------------------------------------------------------------
        | City match
        |--------------------------------------------------------------------------
        |
        | Do not simply take the first city branch.
        | We still need an ACTIVE branch.
        |
        */

        if ($city) {

            $byCityQuery = clone $query;

            $byCity =
                $byCityQuery
                    ->where(
                        'city',
                        'like',
                        '%' . $city . '%'
                    )
                    ->get();

            if ($byCity->isNotEmpty()) {

                /*
                |----------------------------------------------------------------------
                | If coordinates exist, select nearest active branch within city.
                | Otherwise take the first active one.
                |----------------------------------------------------------------------
                */

                if (
                    $lat !== null &&
                    $lng !== null
                ) {

                    $nearest =
                        $byCity
                            ->sortBy(
                                function ($branch) use (
                                    $lat,
                                    $lng
                                ) {
                                    return $this->distanceKm(
                                        (float) $lat,
                                        (float) $lng,

                                        isset(
                                            $branch->latitude
                                        )
                                            ? (float) $branch->latitude
                                            : null,

                                        isset(
                                            $branch->longitude
                                        )
                                            ? (float) $branch->longitude
                                            : null
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

                return $this->asBranchPayload(
                    $byCity->first()
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Area match
        |--------------------------------------------------------------------------
        */

        if ($area) {

            $byAreaQuery = clone $query;

            $byArea =
                $byAreaQuery
                    ->where(
                        'area',
                        'like',
                        '%' . $area . '%'
                    )
                    ->get();

            if ($byArea->isNotEmpty()) {

                if (
                    $lat !== null &&
                    $lng !== null
                ) {

                    $nearest =
                        $byArea
                            ->sortBy(
                                function ($branch) use (
                                    $lat,
                                    $lng
                                ) {
                                    return $this->distanceKm(
                                        (float) $lat,
                                        (float) $lng,

                                        isset(
                                            $branch->latitude
                                        )
                                            ? (float) $branch->latitude
                                            : null,

                                        isset(
                                            $branch->longitude
                                        )
                                            ? (float) $branch->longitude
                                            : null
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

                return $this->asBranchPayload(
                    $byArea->first()
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Coordinate based nearest ACTIVE branch
        |--------------------------------------------------------------------------
        */

        $branches =
            $query->get();

        if (
            $lat !== null &&
            $lng !== null &&
            $branches->isNotEmpty()
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

                                isset(
                                    $branch->latitude
                                )
                                    ? (float) $branch->latitude
                                    : null,

                                isset(
                                    $branch->longitude
                                )
                                    ? (float) $branch->longitude
                                    : null
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
        | Final fallback
        |--------------------------------------------------------------------------
        */

        return $this->asBranchPayload(
            $branches->first()
        );
    }

    /**
     * Check whether a branch is active.
     *
     * Adapted to the actual branches schema.
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
     * Apply active branch filter.
     *
     * Supports common schemas:
     *
     * is_active = 1
     * status = active
     *
     * If neither column exists, we cannot determine
     * active/inactive state from the database.
     */
    private function applyActiveFilter(
        $query
    ): void {

        $schema =
            DB::getSchemaBuilder();

        $hasIsActive =
            $schema->hasColumn(
                'branches',
                'is_active'
            );

        $hasStatus =
            $schema->hasColumn(
                'branches',
                'status'
            );

        if ($hasIsActive) {

            $query->where(
                'is_active',
                true
            );

            return;
        }

        if ($hasStatus) {

            $query->where(
                'status',
                'active'
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        |--------------------------------------------------------------------------
        |
        | Your previous code said branches table does NOT
        | contain is_active.
        |
        | If it also does not contain status, there is currently
        | no database field that tells us whether a branch
        | is active.
        |
        | In that case we leave the query untouched rather than
        | inventing an active condition.
        |
        */
    }

    /**
     * Convert branch row to standard payload.
     */
    private function asBranchPayload(
        $branch
    ): array {

        if (! $branch) {

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

        $type =
            strtolower(
                (string) (
                    $branch->type
                    ?? ''
                )
            );

        $isSub =
            $type === 'sub_branch'
            ||
            ! empty(
                $branch->parent_id
            );

        if ($isSub) {

            return [
                'branch_id' =>
                    $branch->parent_id,

                'sub_branch_id' =>
                    $branch->id,

                'branch' =>
                    $this->branch(
                        $branch->parent_id
                    ),

                'sub_branch' =>
                    $branch,
            ];
        }

        return [
            'branch_id' =>
                $branch->id,

            'sub_branch_id' =>
                null,

            'branch' =>
                $branch,

            'sub_branch' =>
                null,
        ];
    }

    /**
     * Get branch by ID.
     */
    private function branch(
        $id
    ): ?object {

        if (! $id) {
            return null;
        }

        $query =
            DB::table('branches')
                ->where(
                    'id',
                    $id
                );

        $this->applyActiveFilter(
            $query
        );

        return $query->first();
    }
}