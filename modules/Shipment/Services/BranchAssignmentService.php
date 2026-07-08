<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Merchant\Models\Merchant;

class BranchAssignmentService
{
    public function resolveMerchantPickupLocation(Merchant $merchant, ?int $pickupLocationId = null): ?object
    {
        $query = DB::table('merchant_pickup_locations')
            ->where('merchant_id', $merchant->id);

        if ($pickupLocationId) {
            $row = (clone $query)->where('id', $pickupLocationId)->first();
            if ($row) return $row;
        }

        $row = (clone $query)->where('is_default', true)->first();
        if ($row) return $row;

        return (clone $query)->orderBy('id')->first();
    }

    public function resolveOrigin(Merchant $merchant, ?object $pickupLocation): array
    {
        if ($pickupLocation?->branch_id || $pickupLocation?->sub_branch_id) {
            return [
                'branch_id' => $pickupLocation->branch_id,
                'sub_branch_id' => $pickupLocation->sub_branch_id,
                'branch' => $this->branch($pickupLocation->branch_id),
                'sub_branch' => $this->branch($pickupLocation->sub_branch_id),
            ];
        }

        if ($merchant->default_branch_id || $merchant->default_sub_branch_id) {
            return [
                'branch_id' => $merchant->default_branch_id,
                'sub_branch_id' => $merchant->default_sub_branch_id,
                'branch' => $this->branch($merchant->default_branch_id),
                'sub_branch' => $this->branch($merchant->default_sub_branch_id),
            ];
        }

        return $this->nearestBranch(
            $pickupLocation->latitude ?? $merchant->latitude ?? null,
            $pickupLocation->longitude ?? $merchant->longitude ?? null,
            $pickupLocation->city ?? $merchant->city ?? null,
            $pickupLocation->area ?? $merchant->area ?? null
        );
    }

    public function resolveDestination(array $delivery): array
    {
        return $this->nearestBranch(
            $delivery['latitude'] ?? null,
            $delivery['longitude'] ?? null,
            $delivery['city'] ?? null,
            $delivery['area'] ?? null
        );
    }

    public function buildRoute(array $origin, array $destination): array
    {
        $originMain = $origin['sub_branch_id'] ?: $origin['branch_id'];
        $destinationMain = $destination['sub_branch_id'] ?: $destination['branch_id'];
        $requiresTransfer = $originMain && $destinationMain && (int) $originMain !== (int) $destinationMain;

        return [
            'origin_branch_id' => $origin['branch_id'] ?? null,
            'origin_sub_branch_id' => $origin['sub_branch_id'] ?? null,
            'destination_branch_id' => $destination['branch_id'] ?? null,
            'destination_sub_branch_id' => $destination['sub_branch_id'] ?? null,
            'requires_transfer' => $requiresTransfer,
            'steps' => $requiresTransfer
                ? ['Pickup Location', 'Origin Branch/Sub-Branch', 'Transfer', 'Destination Branch/Sub-Branch', 'Customer']
                : ['Pickup Location', 'Branch/Sub-Branch', 'Customer'],
        ];
    }

    public function pickupCoordinates(?object $pickupLocation, Merchant $merchant): array
    {
        return [
            'lat' => $pickupLocation->latitude ?? $merchant->latitude ?? null,
            'lng' => $pickupLocation->longitude ?? $merchant->longitude ?? null,
        ];
    }

    public function distanceKm(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): float
    {
        if (!$lat1 || !$lng1 || !$lat2 || !$lng2) {
            return 5.0;
        }

        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }

    private function nearestBranch($lat, $lng, $city = null, $area = null): array
    {
        $query = DB::table('branches')->where(function ($q) {
            $q->where('is_active', true)->orWhereNull('is_active');
        });

        if ($city) {
            $byCity = (clone $query)->where('city', 'like', '%' . $city . '%')->first();
            if ($byCity) {
                return $this->asBranchPayload($byCity);
            }
        }

        if ($area) {
            $byArea = (clone $query)->where('area', 'like', '%' . $area . '%')->first();
            if ($byArea) {
                return $this->asBranchPayload($byArea);
            }
        }

        $branches = $query->get();

        if ($lat && $lng && $branches->count()) {
            $nearest = $branches->sortBy(function ($branch) use ($lat, $lng) {
                return $this->distanceKm((float) $lat, (float) $lng, (float) ($branch->latitude ?? 0), (float) ($branch->longitude ?? 0));
            })->first();

            return $this->asBranchPayload($nearest);
        }

        return $this->asBranchPayload($branches->first());
    }

    private function asBranchPayload($branch): array
    {
        if (!$branch) {
            return ['branch_id' => null, 'sub_branch_id' => null, 'branch' => null, 'sub_branch' => null];
        }

        $type = strtolower((string) ($branch->type ?? ''));
        $isSub = $type === 'sub_branch' || $branch->parent_id;

        return [
            'branch_id' => $isSub ? $branch->parent_id : $branch->id,
            'sub_branch_id' => $isSub ? $branch->id : null,
            'branch' => $isSub ? $this->branch($branch->parent_id) : $branch,
            'sub_branch' => $isSub ? $branch : null,
        ];
    }

    private function branch($id): ?object
    {
        return $id ? DB::table('branches')->where('id', $id)->first() : null;
    }
}
