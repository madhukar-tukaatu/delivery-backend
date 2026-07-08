<?php

namespace Modules\Routing\Services;

use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;

class BranchRoutingService
{
    public function __construct(private GeoDistanceService $geo) {}

    /**
     * Finds nearest service sub-branch/branch from coordinates.
     * Return: ['branch' => Branch, 'sub_branch' => ?Branch, 'distance_km' => float, 'service_area' => ?object]
     */
    public function nearest(float $lat, float $lng, string $type = 'both'): array
    {
        $areas = DB::table('branch_service_areas')
            ->where('status', 'active')
            ->where(function ($q) use ($type) {
                $q->where('service_type', 'both')->orWhere('service_type', $type);
            })
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        $best = null;
        foreach ($areas as $area) {
            $distance = $this->geo->distanceKm($lat, $lng, (float) $area->latitude, (float) $area->longitude);
            $radius = (float) ($area->radius_km ?: 5);
            $score = $distance + (((int) ($area->priority ?? 100)) / 1000);

            if ($distance <= $radius && (!$best || $score < $best['score'])) {
                $best = [
                    'area' => $area,
                    'distance_km' => $distance,
                    'score' => $score,
                ];
            }
        }

        if ($best) {
            $subBranch = $best['area']->sub_branch_id ? Branch::find($best['area']->sub_branch_id) : null;
            $branch = Branch::find($best['area']->branch_id);

            if ($subBranch && $subBranch->parent_id) {
                $branch = $subBranch->parent ?: $branch;
            }

            return [
                'branch' => $branch,
                'sub_branch' => $subBranch,
                'distance_km' => $best['distance_km'],
                'service_area' => $best['area'],
            ];
        }

        $branches = Branch::query()
            ->where('status', 'active')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        $nearest = null;
        foreach ($branches as $branch) {
            $distance = $this->geo->distanceKm($lat, $lng, (float) $branch->latitude, (float) $branch->longitude);
            $radius = (float) ($branch->coverage_radius_km ?: 10);
            $score = $distance;

            if ($distance <= $radius && (!$nearest || $score < $nearest['score'])) {
                $nearest = [
                    'branch_model' => $branch,
                    'distance_km' => $distance,
                    'score' => $score,
                ];
            }
        }

        if (!$nearest) {
            foreach ($branches as $branch) {
                $distance = $this->geo->distanceKm($lat, $lng, (float) $branch->latitude, (float) $branch->longitude);
                if (!$nearest || $distance < $nearest['distance_km']) {
                    $nearest = ['branch_model' => $branch, 'distance_km' => $distance, 'score' => $distance];
                }
            }
        }

        if (!$nearest) {
            throw new \RuntimeException('No active branch with coordinates found. Seed routing branches first.');
        }

        $matched = $nearest['branch_model'];
        $parent = $matched->type === 'sub_branch' && $matched->parent ? $matched->parent : $matched;

        return [
            'branch' => $parent,
            'sub_branch' => $matched->type === 'sub_branch' ? $matched : null,
            'distance_km' => $nearest['distance_km'],
            'service_area' => null,
        ];
    }
}
