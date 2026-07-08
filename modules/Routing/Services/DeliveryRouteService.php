<?php

namespace Modules\Routing\Services;

use Modules\Branch\Models\Branch;
use Modules\Routing\Models\DeliveryRouteSegment;

class DeliveryRouteService
{
    public function __construct(private GeoDistanceService $geo) {}

    /**
     * Returns ordered route steps. Each step has from_branch_id, to_branch_id, distance_km, fee, estimated_hours.
     */
    public function buildRoute(?Branch $originSubBranch, Branch $originBranch, Branch $destinationBranch, ?Branch $destinationSubBranch, float $weight = 1): array
    {
        $steps = [];

        if ($originSubBranch && $originSubBranch->id !== $originBranch->id) {
            $steps[] = $this->localStep($originSubBranch, $originBranch, $weight, 40, 2);
        }

        if ($originBranch->id !== $destinationBranch->id) {
            $hubSteps = $this->shortestBranchPath($originBranch, $destinationBranch, $weight);
            $steps = array_merge($steps, $hubSteps);
        }

        if ($destinationSubBranch && $destinationSubBranch->id !== $destinationBranch->id) {
            $steps[] = $this->localStep($destinationBranch, $destinationSubBranch, $weight, 50, 2);
        }

        $sequence = 1;
        return array_map(function (array $step) use (&$sequence) {
            $step['sequence'] = $sequence++;
            return $step;
        }, $steps);
    }

    private function shortestBranchPath(Branch $from, Branch $to, float $weight): array
    {
        $segments = DeliveryRouteSegment::query()->where('status', 'active')->get();

        if ($segments->isEmpty()) {
            return [$this->localStep($from, $to, $weight, 100, 24)];
        }

        $nodes = [];
        $graph = [];
        foreach ($segments as $segment) {
            $nodes[$segment->from_branch_id] = true;
            $nodes[$segment->to_branch_id] = true;
            $graph[$segment->from_branch_id][] = $segment;
        }

        $dist = [];
        $prev = [];
        $visited = [];
        foreach (array_keys($nodes) as $node) {
            $dist[$node] = INF;
        }
        $dist[$from->id] = 0;

        while (true) {
            $current = null;
            $currentDistance = INF;
            foreach ($dist as $node => $distance) {
                if (!isset($visited[$node]) && $distance < $currentDistance) {
                    $current = (int) $node;
                    $currentDistance = $distance;
                }
            }

            if ($current === null || $current === $to->id) {
                break;
            }

            $visited[$current] = true;
            foreach ($graph[$current] ?? [] as $segment) {
                $next = $segment->to_branch_id;
                $newDistance = $dist[$current] + (float) $segment->distance_km;
                if ($newDistance < ($dist[$next] ?? INF)) {
                    $dist[$next] = $newDistance;
                    $prev[$next] = $segment;
                }
            }
        }

        if (!isset($prev[$to->id])) {
            return [$this->localStep($from, $to, $weight, 100, 24)];
        }

        $path = [];
        $cursor = $to->id;
        while ($cursor !== $from->id && isset($prev[$cursor])) {
            $segment = $prev[$cursor];
            array_unshift($path, $this->segmentStep($segment, $weight));
            $cursor = $segment->from_branch_id;
        }

        return $path ?: [$this->localStep($from, $to, $weight, 100, 24)];
    }

    private function segmentStep(DeliveryRouteSegment $segment, float $weight): array
    {
        $fee = (float) $segment->base_fee + max(0, $weight - 1) * (float) $segment->per_kg_fee;

        return [
            'from_branch_id' => $segment->from_branch_id,
            'to_branch_id' => $segment->to_branch_id,
            'distance_km' => (float) $segment->distance_km,
            'fee' => round($fee, 2),
            'estimated_hours' => (int) $segment->estimated_hours,
        ];
    }

    private function localStep(Branch $from, Branch $to, float $weight, float $minimumFee, int $hours): array
    {
        $distance = 0;
        if ($from->latitude && $from->longitude && $to->latitude && $to->longitude) {
            $distance = $this->geo->distanceKm((float) $from->latitude, (float) $from->longitude, (float) $to->latitude, (float) $to->longitude);
        }

        $fee = max($minimumFee, ($distance * 8) + max(0, $weight - 1) * 15);

        return [
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
            'distance_km' => round($distance, 2),
            'fee' => round($fee, 2),
            'estimated_hours' => $hours,
        ];
    }
}
