<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Merchant\Models\Merchant;

class BranchAssignmentService
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

        /*
        |--------------------------------------------------------------------------
        | Explicit pickup location
        |--------------------------------------------------------------------------
        */

        if ($pickupLocationId) {
            $row = (clone $query)
                ->where('id', $pickupLocationId)
                ->first();

            if ($row) {
                return $row;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Default pickup location
        |--------------------------------------------------------------------------
        */

        $row = (clone $query)
            ->where('is_default', true)
            ->first();

        if ($row) {
            return $row;
        }

        /*
        |--------------------------------------------------------------------------
        | Fallback
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
     * 1. Pickup location assigned branch
     * 2. Merchant default branch
     * 3. Nearest available branch
     */
    public function resolveOrigin(
        Merchant $merchant,
        ?object $pickupLocation
    ): array {
        /*
        |--------------------------------------------------------------------------
        | 1. Pickup location already has branch assignment
        |--------------------------------------------------------------------------
        */

        if (
            $pickupLocation &&
            (
                !empty($pickupLocation->branch_id) ||
                !empty($pickupLocation->sub_branch_id)
            )
        ) {
            return [
                'branch_id' => $pickupLocation->branch_id,
                'sub_branch_id' => $pickupLocation->sub_branch_id,

                'branch' => $this->branch(
                    $pickupLocation->branch_id
                ),

                'sub_branch' => $this->branch(
                    $pickupLocation->sub_branch_id
                ),
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Merchant default branch
        |--------------------------------------------------------------------------
        */

        if (
            !empty($merchant->default_branch_id) ||
            !empty($merchant->default_sub_branch_id)
        ) {
            return [
                'branch_id' => $merchant->default_branch_id,
                'sub_branch_id' => $merchant->default_sub_branch_id,

                'branch' => $this->branch(
                    $merchant->default_branch_id
                ),

                'sub_branch' => $this->branch(
                    $merchant->default_sub_branch_id
                ),
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Nearest available branch
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
     * The delivery payload can contain:
     *
     * latitude
     * longitude
     * city
     * area
     *
     * At the moment coverage locations may not have
     * an assigned branch, therefore we fall back to
     * the nearest available branch.
     */
    public function resolveDestination(array $delivery): array
    {
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
            $originMain !== null &&
            $destinationMain !== null &&
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

            'steps' => $requiresTransfer
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
     * Find nearest operational branch.
     *
     * IMPORTANT:
     * branches table currently does NOT contain is_active.
     *
     * Therefore we do not filter using is_active.
     */
    private function nearestBranch(
        $lat,
        $lng,
        $city = null,
        $area = null
    ): array {
        /*
        |--------------------------------------------------------------------------
        | Get branches
        |--------------------------------------------------------------------------
        |
        | Do NOT use:
        |
        | where('is_active', true)
        |
        | because branches table does not contain that column.
        |
        */

        $query = DB::table('branches');

        /*
        |--------------------------------------------------------------------------
        | City match
        |--------------------------------------------------------------------------
        */

        if ($city) {
            $byCity = (clone $query)
                ->where(
                    'city',
                    'like',
                    '%' . $city . '%'
                )
                ->first();

            if ($byCity) {
                return $this->asBranchPayload(
                    $byCity
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Area match
        |--------------------------------------------------------------------------
        */

        if ($area) {
            $byArea = (clone $query)
                ->where(
                    'area',
                    'like',
                    '%' . $area . '%'
                )
                ->first();

            if ($byArea) {
                return $this->asBranchPayload(
                    $byArea
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Coordinate based nearest branch
        |--------------------------------------------------------------------------
        */

        $branches = $query->get();

        if (
            $lat !== null &&
            $lng !== null &&
            $branches->isNotEmpty()
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

            return $this->asBranchPayload(
                $nearest
            );
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
     * Convert branch row to standard payload.
     */
    private function asBranchPayload(
        $branch
    ): array {
        if (!$branch) {
            return [
                'branch_id' => null,
                'sub_branch_id' => null,
                'branch' => null,
                'sub_branch' => null,
            ];
        }

        $type = strtolower(
            (string) ($branch->type ?? '')
        );

        $isSub =
            $type === 'sub_branch'
            || !empty($branch->parent_id);

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
        if (!$id) {
            return null;
        }

        return DB::table('branches')
            ->where('id', $id)
            ->first();
    }
}