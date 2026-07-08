<?php

namespace Modules\Routing\Services;

use Modules\Branch\Models\Branch;

class BranchLocatorService
{
    public function locate(float $lat, float $lng): array
    {
        $branches = Branch::query()
            ->where('status', 'active')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        $subBranches = $branches->where('type', 'sub_branch')->values();
        $districtBranches = $branches->where('type', 'branch')->values();

        $nearestSubBranch = $this->nearest($subBranches, $lat, $lng);
        $nearestBranch = null;

        if ($nearestSubBranch) {
            $nearestBranch = Branch::find($nearestSubBranch->parent_id);
        }

        if (!$nearestBranch) {
            $nearestBranch = $this->nearest($districtBranches, $lat, $lng);
        }

        return [
            'branch' => $nearestBranch,
            'sub_branch' => $nearestSubBranch,
            'distance_km' => $nearestSubBranch?->distance_km ?? $nearestBranch?->distance_km,
            'within_coverage' => $this->withinCoverage($nearestSubBranch ?: $nearestBranch),
        ];
    }

    private function nearest($branches, float $lat, float $lng): ?Branch
    {
        return $branches
            ->map(function (Branch $branch) use ($lat, $lng) {
                $branch->distance_km = $this->distanceKm(
                    $lat,
                    $lng,
                    (float) $branch->latitude,
                    (float) $branch->longitude
                );

                return $branch;
            })
            ->sortBy('distance_km')
            ->first();
    }

    private function withinCoverage(?Branch $branch): bool
    {
        if (!$branch) {
            return false;
        }

        $radius = (float) ($branch->coverage_radius_km ?? 0);
        if ($radius <= 0) {
            return true;
        }

        return (float) $branch->distance_km <= $radius;
    }

    public function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }
}
