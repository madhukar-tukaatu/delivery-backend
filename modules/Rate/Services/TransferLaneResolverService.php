<?php

namespace Modules\Rate\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferLaneResolverService
{
    /**
     * Resolve an operational route between two responsible branches.
     *
     * Same branch: 0 lanes.
     * Direct movement: 1 lane.
     * Multi-hub movement: one lane for every branch-to-branch movement.
     */
    public function resolve(
        int $originBranchId,
        int $destinationBranchId,
        string $serviceType = 'standard'
    ): array {
        $serviceType = $this->normaliseServiceType($serviceType);

        if ($originBranchId === $destinationBranchId) {
            $branch = $this->branch($originBranchId);

            return [
                'route_available' => true,
                'requested_service_type' => $serviceType,
                'resolved_service_type' => $serviceType,
                'fallback_used' => false,
                'origin_branch_id' => $originBranchId,
                'destination_branch_id' => $destinationBranchId,
                'lane_count' => 0,
                'branch_count' => 1,
                'total_estimated_hours' => 0,
                'total_distance_km' => 0.0,
                'distance_complete' => true,
                'path' => [$branch],
                'lanes' => [],
            ];
        }

        $serviceCandidates = array_values(array_unique([
            $serviceType,
            'standard',
        ]));

        foreach ($serviceCandidates as $candidate) {
            $route = $this->resolveForService(
                $originBranchId,
                $destinationBranchId,
                $candidate
            );

            if ($route !== null) {
                return [
                    ...$route,
                    'requested_service_type' => $serviceType,
                    'resolved_service_type' => $candidate,
                    'fallback_used' => $candidate !== $serviceType,
                ];
            }
        }

        $origin = $this->branch($originBranchId);
        $destination = $this->branch($destinationBranchId);

        throw ValidationException::withMessages([
            'transfer_route' => [
                sprintf(
                    'No active transfer-lane path is configured from %s to %s for %s service.',
                    $origin['name'],
                    $destination['name'],
                    $serviceType
                ),
            ],
        ]);
    }

    private function resolveForService(
        int $originBranchId,
        int $destinationBranchId,
        string $serviceType
    ): ?array {
        $lanes = DB::table('branch_transfer_lanes')
            ->where('service_type', $serviceType)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get([
                'id',
                'from_branch_id',
                'to_branch_id',
                'service_type',
                'transport_mode',
                'distance_km',
                'estimated_hours',
                'priority',
                'is_bidirectional',
            ]);

        if ($lanes->isEmpty()) {
            return null;
        }

        $adjacency = $this->buildAdjacency($lanes);

        if (!isset($adjacency[$originBranchId])) {
            return null;
        }

        $hours = [$originBranchId => 0.0];
        $hops = [$originBranchId => 0];
        $priorityCost = [$originBranchId => 0];
        $previous = [];
        $visited = [];

        while (true) {
            $current = $this->nextNode(
                $hours,
                $hops,
                $priorityCost,
                $visited
            );

            if ($current === null) {
                break;
            }

            if ($current === $destinationBranchId) {
                break;
            }

            $visited[$current] = true;

            foreach ($adjacency[$current] ?? [] as $edge) {
                $next = (int) $edge['to_branch_id'];

                if (isset($visited[$next])) {
                    continue;
                }

                $candidateHours = $hours[$current]
                    + (float) $edge['estimated_hours'];
                $candidateHops = $hops[$current] + 1;
                $candidatePriority = $priorityCost[$current]
                    + (int) $edge['priority'];

                if (
                    !isset($hours[$next]) ||
                    $this->isBetterRoute(
                        candidateHours: $candidateHours,
                        candidateHops: $candidateHops,
                        candidatePriority: $candidatePriority,
                        currentHours: $hours[$next],
                        currentHops: $hops[$next],
                        currentPriority: $priorityCost[$next]
                    )
                ) {
                    $hours[$next] = $candidateHours;
                    $hops[$next] = $candidateHops;
                    $priorityCost[$next] = $candidatePriority;
                    $previous[$next] = [
                        'from_branch_id' => $current,
                        'edge' => $edge,
                    ];
                }
            }
        }

        if (!isset($previous[$destinationBranchId])) {
            return null;
        }

        $routeEdges = [];
        $cursor = $destinationBranchId;

        while ($cursor !== $originBranchId) {
            if (!isset($previous[$cursor])) {
                return null;
            }

            $step = $previous[$cursor];
            array_unshift($routeEdges, $step['edge']);
            $cursor = (int) $step['from_branch_id'];
        }

        return $this->formatRoute(
            $originBranchId,
            $destinationBranchId,
            $serviceType,
            $routeEdges
        );
    }

    private function buildAdjacency(Collection $lanes): array
    {
        $adjacency = [];

        foreach ($lanes as $lane) {
            $this->addEdge(
                $adjacency,
                lane: $lane,
                fromBranchId: (int) $lane->from_branch_id,
                toBranchId: (int) $lane->to_branch_id,
                reverse: false
            );

            if ((bool) $lane->is_bidirectional) {
                $this->addEdge(
                    $adjacency,
                    lane: $lane,
                    fromBranchId: (int) $lane->to_branch_id,
                    toBranchId: (int) $lane->from_branch_id,
                    reverse: true
                );
            }
        }

        return $adjacency;
    }

    private function addEdge(
        array &$adjacency,
        object $lane,
        int $fromBranchId,
        int $toBranchId,
        bool $reverse
    ): void {
        $adjacency[$fromBranchId][] = [
            'lane_id' => (int) $lane->id,
            'from_branch_id' => $fromBranchId,
            'to_branch_id' => $toBranchId,
            'service_type' => (string) $lane->service_type,
            'transport_mode' => $lane->transport_mode,
            'distance_km' => $lane->distance_km !== null
                ? (float) $lane->distance_km
                : null,
            'estimated_hours' => max(
                0,
                (int) ($lane->estimated_hours ?? 0)
            ),
            'priority' => max(
                0,
                (int) ($lane->priority ?? 100)
            ),
            'is_reverse_direction' => $reverse,
        ];
    }

    private function nextNode(
        array $hours,
        array $hops,
        array $priorityCost,
        array $visited
    ): ?int {
        $selected = null;

        foreach ($hours as $branchId => $branchHours) {
            $branchId = (int) $branchId;

            if (isset($visited[$branchId])) {
                continue;
            }

            if ($selected === null) {
                $selected = $branchId;
                continue;
            }

            if (
                $this->isBetterRoute(
                    candidateHours: (float) $branchHours,
                    candidateHops: (int) ($hops[$branchId] ?? PHP_INT_MAX),
                    candidatePriority: (int) ($priorityCost[$branchId] ?? PHP_INT_MAX),
                    currentHours: (float) $hours[$selected],
                    currentHops: (int) ($hops[$selected] ?? PHP_INT_MAX),
                    currentPriority: (int) ($priorityCost[$selected] ?? PHP_INT_MAX)
                )
            ) {
                $selected = $branchId;
            }
        }

        return $selected;
    }

    private function isBetterRoute(
        float $candidateHours,
        int $candidateHops,
        int $candidatePriority,
        float $currentHours,
        int $currentHops,
        int $currentPriority
    ): bool {
        $epsilon = 0.000001;

        if ($candidateHours < $currentHours - $epsilon) {
            return true;
        }

        if (abs($candidateHours - $currentHours) > $epsilon) {
            return false;
        }

        if ($candidateHops < $currentHops) {
            return true;
        }

        if ($candidateHops > $currentHops) {
            return false;
        }

        return $candidatePriority < $currentPriority;
    }

    private function formatRoute(
        int $originBranchId,
        int $destinationBranchId,
        string $serviceType,
        array $routeEdges
    ): array {
        $branchIds = [$originBranchId];
        $totalHours = 0;
        $totalDistance = 0.0;
        $distanceComplete = true;
        $formattedLanes = [];

        foreach ($routeEdges as $index => $edge) {
            $branchIds[] = (int) $edge['to_branch_id'];
            $totalHours += (int) $edge['estimated_hours'];

            if ($edge['distance_km'] === null) {
                $distanceComplete = false;
            } else {
                $totalDistance += (float) $edge['distance_km'];
            }

            $formattedLanes[] = [
                'sequence' => $index + 1,
                ...$edge,
            ];
        }

        $branches = DB::table('branches')
            ->whereIn('id', array_values(array_unique($branchIds)))
            ->get(['id', 'name', 'code'])
            ->keyBy('id');

        $path = array_map(
            static function (int $branchId) use ($branches): array {
                $branch = $branches->get($branchId);

                return [
                    'id' => $branchId,
                    'name' => $branch?->name ?? "Branch {$branchId}",
                    'code' => $branch?->code,
                ];
            },
            $branchIds
        );

        return [
            'route_available' => true,
            'origin_branch_id' => $originBranchId,
            'destination_branch_id' => $destinationBranchId,
            'service_type' => $serviceType,
            'lane_count' => count($formattedLanes),
            'branch_count' => count($path),
            'total_estimated_hours' => $totalHours,
            'total_distance_km' => round($totalDistance, 2),
            'distance_complete' => $distanceComplete,
            'path' => $path,
            'lanes' => $formattedLanes,
        ];
    }

    private function branch(int $branchId): array
    {
        $branch = DB::table('branches')
            ->where('id', $branchId)
            ->first(['id', 'name', 'code']);

        if (!$branch) {
            throw ValidationException::withMessages([
                'branch' => ["Branch {$branchId} was not found."],
            ]);
        }

        return [
            'id' => (int) $branch->id,
            'name' => (string) $branch->name,
            'code' => $branch->code,
        ];
    }

    private function normaliseServiceType(string $serviceType): string
    {
        $value = strtolower(trim($serviceType));

        return match ($value) {
            'same-day', 'same day', 'sameday' => 'same_day',
            default => $value !== '' ? $value : 'standard',
        };
    }
}
