<?php
namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Merchant\Models\Merchant;

class BranchAssignmentService
{
    /*
    |--------------------------------------------------------------------------
    | Resolve merchant pickup location
    |--------------------------------------------------------------------------
    */

    public function resolveMerchantPickupLocation(
        Merchant $merchant,
        ?int $pickupLocationId = null
    ): ?object {

        $query = DB::table('merchant_pickup_locations')
            ->where('merchant_id', $merchant->id);

        if ($pickupLocationId !== null) {

            $location = (clone $query)
                ->where('id', $pickupLocationId)
                ->first();

            if ($location) {
                return $location;
            }

            return null;
        }

        $location = (clone $query)
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereIn('status', [
                        'active',
                        'approved',
                    ]);
            })
            ->where(function ($q) {
                $q->where('is_default', true)
                    ->orWhereNull('is_default');
            })
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($location) {
            return $location;
        }

        return (clone $query)
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereIn('status', [
                        'active',
                        'approved',
                    ]);
            })
            ->orderBy('id')
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve origin
    |--------------------------------------------------------------------------
    */

    public function resolveOrigin(
        Merchant $merchant,
        ?object $pickupLocation
    ): array {

        /*
        |--------------------------------------------------------------------------
        | Pickup location has explicit branch
        |--------------------------------------------------------------------------
        */

        if (
            $pickupLocation?->branch_id ||
            $pickupLocation?->sub_branch_id
        ) {

            $branchId    = $pickupLocation->branch_id;
            $subBranchId = $pickupLocation->sub_branch_id;

            /*
            | If only sub branch exists, determine parent branch.
            */

            if (! $branchId && $subBranchId) {

                $subBranch = $this->branch($subBranchId);

                $branchId = $subBranch?->parent_id;
            }

            return [
                'branch_id'     => $branchId,
                'sub_branch_id' => $subBranchId,

                'branch'        => $this->branch($branchId),

                'sub_branch'    => $subBranchId
                    ? $this->branch($subBranchId)
                    : null,
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

            $branchId    = $merchant->default_branch_id;
            $subBranchId = $merchant->default_sub_branch_id;

            if (! $branchId && $subBranchId) {

                $subBranch = $this->branch($subBranchId);

                $branchId = $subBranch?->parent_id;
            }

            return [
                'branch_id'     => $branchId,
                'sub_branch_id' => $subBranchId,

                'branch'        => $this->branch($branchId),

                'sub_branch'    => $subBranchId
                    ? $this->branch($subBranchId)
                    : null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Fallback: nearest branch
        |--------------------------------------------------------------------------
        */

        return $this->nearestBranch(
            $pickupLocation?->latitude ?? $merchant->latitude ?? $merchant->pickup_lat ?? null,

            $pickupLocation?->longitude ?? $merchant->longitude ?? $merchant->pickup_lng ?? null,

            $pickupLocation?->city ?? $merchant->city ?? null,

            $pickupLocation?->area ?? $merchant->area ?? null,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve destination
    |--------------------------------------------------------------------------
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

    /*
    |--------------------------------------------------------------------------
    | Build route
    |--------------------------------------------------------------------------
    */

    public function buildRoute(
        array $origin,
        array $destination
    ): array {

        $originNode =
        $origin['sub_branch_id']
            ?: $origin['branch_id'];

        $destinationNode =
        $destination['sub_branch_id']
            ?: $destination['branch_id'];

        $requiresTransfer =
        $originNode !== null &&
        $destinationNode !== null &&
        (int) $originNode !== (int) $destinationNode;

        return [

            'origin_branch_id'          =>
            $origin['branch_id'] ?? null,

            'origin_sub_branch_id'      =>
            $origin['sub_branch_id'] ?? null,

            'destination_branch_id'     =>
            $destination['branch_id'] ?? null,

            'destination_sub_branch_id' =>
            $destination['sub_branch_id'] ?? null,

            'requires_transfer'         =>
            $requiresTransfer,

            'steps'                     => $requiresTransfer

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

    /*
    |--------------------------------------------------------------------------
    | Pickup coordinates
    |--------------------------------------------------------------------------
    */

    public function pickupCoordinates(
        ?object $pickupLocation,
        Merchant $merchant
    ): array {

        return [

            'lat' =>
            $pickupLocation?->latitude ?? $merchant->latitude ?? $merchant->pickup_lat ?? null,

            'lng' =>
            $pickupLocation?->longitude ?? $merchant->longitude ?? $merchant->pickup_lng ?? null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Distance
    |--------------------------------------------------------------------------
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

        $a = min(1, max(0, $a));

        $c = 2 * atan2(
            sqrt($a),
            sqrt(1 - $a)
        );

        return round(
            $earthRadius * $c,
            2
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Nearest branch
    |--------------------------------------------------------------------------
    */

    private function nearestBranch(
        $lat,
        $lng,
        $city = null,
        $area = null
    ): array {

        $query = DB::table('branches')
            ->where(function ($q) {
                $q->where('is_active', true)
                    ->orWhereNull('is_active');
            });

        /*
        |--------------------------------------------------------------------------
        | City
        |--------------------------------------------------------------------------
        */

        if ($city) {

            $branch = (clone $query)
                ->where(
                    'city',
                    'like',
                    '%' . $city . '%'
                )
                ->first();

            if ($branch) {
                return $this->asBranchPayload($branch);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Area
        |--------------------------------------------------------------------------
        */

        if ($area) {

            $branch = (clone $query)
                ->where(
                    'area',
                    'like',
                    '%' . $area . '%'
                )
                ->first();

            if ($branch) {
                return $this->asBranchPayload($branch);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Coordinate search
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

                    if (
                        $branch->latitude === null ||
                        $branch->longitude === null
                    ) {
                        return PHP_FLOAT_MAX;
                    }

                    return $this->distanceKm(
                        (float) $lat,
                        (float) $lng,
                        (float) $branch->latitude,
                        (float) $branch->longitude
                    );
                })
                ->first();

            if ($nearest) {
                return $this->asBranchPayload($nearest);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Nothing found
        |--------------------------------------------------------------------------
        */

        return $this->asBranchPayload(
            $branches->first()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Convert branch into normalized payload
    |--------------------------------------------------------------------------
    */

    private function asBranchPayload(
        $branch
    ): array {

        if (! $branch) {

            return [
                'branch_id'     => null,
                'sub_branch_id' => null,
                'branch'        => null,
                'sub_branch'    => null,
            ];
        }

        $type = strtolower(
            (string) ($branch->type ?? '')
        );

        $isSub =
        $type === 'sub_branch'
        ||
        ! empty($branch->parent_id);

        if ($isSub) {

            return [
                'branch_id'     => $branch->parent_id,
                'sub_branch_id' => $branch->id,

                'branch'        =>
                $this->branch($branch->parent_id),

                'sub_branch'    =>
                $branch,
            ];
        }

        return [
            'branch_id'     => $branch->id,
            'sub_branch_id' => null,

            'branch'        =>
            $branch,

            'sub_branch'    =>
            null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Branch
    |--------------------------------------------------------------------------
    */

    private function branch($id): ?object
    {
        if (! $id) {
            return null;
        }

        return DB::table('branches')
            ->where('id', $id)
            ->first();
    }
}
