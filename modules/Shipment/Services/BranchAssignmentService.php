<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Merchant\Models\Merchant;

final class BranchAssignmentService
{
    /**
     * Resolve merchant pickup location.
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

        if ($pickupLocationId !== null) {
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
        | First available pickup location
        |--------------------------------------------------------------------------
        */

        return (clone $query)
            ->orderBy('id')
            ->first();
    }

    /**
     * Resolve merchant pickup location.
     */
    public function resolveOrigin(
        Merchant $merchant,
        ?object $pickupLocation
    ): array {
        /*
        |--------------------------------------------------------------------------
        | Pickup location explicitly assigned to branch
        |--------------------------------------------------------------------------
        */

        if (
            $pickupLocation &&
            (
                $pickupLocation->branch_id ||
                $pickupLocation->sub_branch_id
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
        | Merchant default branch
        |--------------------------------------------------------------------------
        */

        if (
            $merchant->default_branch_id ||
            $merchant->default_sub_branch_id
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
        | Fallback to nearest branch
        |--------------------------------------------------------------------------
        */

        return $this->nearestBranch(
            $pickupLocation?->latitude
                ?? $merchant->latitude
                ?? null,

            $pickupLocation?->longitude
                ?? $merchant->longitude
                ?? null,

            $pickupLocation?->city
                ?? $merchant->city
                ?? null,

            $pickupLocation?->area
                ?? $merchant->area
                ?? null,
        );
    }

    /**
     * Resolve destination branch.
     */
    public function resolveDestination(array $delivery): array
    {
        return $this->nearestBranch(
            $delivery['latitude'] ?? null,
            $delivery['longitude'] ?? null,
            $delivery['city'] ?? null,
            $delivery['area'] ?? null,
        );
    }

    /**
     * Build logical route.
     */
    public function buildRoute(
        array $origin,
        array $destination
    ): array {
        $originMain =
            $origin['sub_branch_id']
            ?: $origin['branch_id'];

        $destinationMain =
            $destination['sub_branch_id']
            ?: $destination['branch_id'];

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
     * Pickup coordinates.
     */
    public function pickupCoordinates(
        ?object $pickupLocation,
        Merchant $merchant
    ): array {
        return [
            'lat' =>
                $pickupLocation?->latitude
                ?? $merchant->latitude
                ?? null,

            'lng' =>
                $pickupLocation?->longitude
                ?? $merchant->longitude
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
     * Find nearest available branch.
     *
     * IMPORTANT:
     * The branches table does NOT contain is_active,
     * so we do not filter by is_active here.
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
        | Coordinate-based nearest branch
        |--------------------------------------------------------------------------
        */

        $branches = $query->get();

        if (
            $lat !== null &&
            $lng !== null &&
            $branches->isNotEmpty()
        ) {
            $nearest = $branches
                ->sortBy(
                    function ($branch) use ($lat, $lng) {
                        return $this->distanceKm(
                            (float) $lat,
                            (float) $lng,
                            $branch->latitude !== null
                                ? (float) $branch->latitude
                                : null,
                            $branch->longitude !== null
                                ? (float) $branch->longitude
                                : null,
                        );
                    }
                )
                ->first();

            return $this->asBranchPayload(
                $nearest
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Last fallback
        |--------------------------------------------------------------------------
        */

        return $this->asBranchPayload(
            $branches->first()
        );
    }

    /**
     * Convert branch row into standard payload.
     */
    private function asBranchPayload(
        ?object $branch
    ): array {
        if (! $branch) {
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
            ||
            ! empty($branch->parent_id);

        return [
            'branch_id' =>
                $isSub
                    ? $branch->parent_id
                    : $branch->id,

            'sub_branch_id' =>
                $isSub
                    ? $branch->id
                    : null,

            'branch' =>
                $isSub
                    ? $this->branch(
                        $branch->parent_id
                    )
                    : $branch,

            'sub_branch' =>
                $isSub
                    ? $branch
                    : null,
        ];
    }

    /**
     * Get branch.
     */
    private function branch(
        $id
    ): ?object {
        if (! $id) {
            return null;
        }

        return DB::table('branches')
            ->where('id', $id)
            ->first();
    }
}