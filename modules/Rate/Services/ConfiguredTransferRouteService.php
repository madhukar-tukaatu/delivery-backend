<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\CoverageLocation;
use Modules\Rate\Models\BranchTransferRoute;

/**
 * Resolves the operational transfer route for an origin -> destination + service.
 *
 * A route is an ORDERED LIST OF LANES (see BranchTransferRouteService). Multiple
 * routes may exist for the same origin/destination/service (alternatives); this
 * service picks the best ACTIVE one: is_default first, then lowest priority, then
 * lowest id.
 *
 * The output shape is consumed by TransferWorkflowService and must remain stable:
 *   route_id, route_code, route_name, origin_branch_id, destination_branch_id,
 *   service_type, transit_branch_ids, transit_branches, transit_count,
 *   transfer_count, total_distance_km, total_estimated_hours, base_rate, currency,
 *   checkpoints, path, path_text, is_active, is_default, priority.
 */
final class ConfiguredTransferRouteService
{
    public function resolve(
        int $originBranchId,
        int $destinationBranchId,
        string $serviceType = 'standard'
    ): array {
        $serviceType = $this->normalizeServiceType($serviceType);

        // Candidate routes for this service, best first. We match origin/destination
        // from each route's ordered lane chain (not a single direct lane).
        $candidates = BranchTransferRoute::query()
            ->where('service_type', $serviceType)
            ->where('is_active', true)
            ->with(['routeLanes.lane.fromBranch', 'routeLanes.lane.toBranch', 'lane.fromBranch', 'lane.toBranch'])
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $route) {
            $path = $route->getPathBranchIds();
            if ($path === []) {
                continue;
            }

            if ((int) $path[0] === $originBranchId
                && (int) end($path) === $destinationBranchId) {
                return $this->formatRoute($route);
            }
        }

        throw ValidationException::withMessages([
            'transfer_route' => [
                sprintf(
                    'No active transfer route is configured from %s to %s for %s service. '
                    . 'Create a route (direct or via transit branches) for this pair.',
                    $this->branchName($originBranchId),
                    $this->branchName($destinationBranchId),
                    strtoupper($serviceType)
                ),
            ],
        ]);
    }

    public function formatRoute(BranchTransferRoute $route): array
    {
        $route->loadMissing(
            'routeLanes.lane.fromBranch',
            'routeLanes.lane.toBranch',
            'lane.fromBranch',
            'lane.toBranch'
        );

        $lanes = $route->orderedLanes();

        if ($lanes->isEmpty()) {
            return $this->getEmptyFormat($route);
        }

        // Ordered lane mappings so the edit form can rebuild the lane chain.
        $laneMappings = [];
        $seq = 1;
        foreach ($lanes as $lane) {
            $laneMappings[] = [
                'sequence_number'         => $seq++,
                'branch_transfer_lane_id' => (int) $lane->id,
                'lane'                    => [
                    'id'             => (int) $lane->id,
                    'from_branch_id' => (int) $lane->from_branch_id,
                    'to_branch_id'   => (int) $lane->to_branch_id,
                    'service_type'   => $lane->service_type,
                    'variant_name'   => $lane->variant_name,
                    'checkpoints'    => $lane->getCheckpoints(),
                ],
            ];
        }

        // Build the ordered branch path: origin -> transit branches -> destination.
        $path = [];
        $sequence = 0;
        $lastLane = null;

        $first = $lanes->first();
        $path[] = $this->branchNode($first->fromBranch, (int) $first->from_branch_id, $sequence++, null);

        foreach ($lanes as $lane) {
            $path[] = $this->branchNode(
                $lane->toBranch,
                (int) $lane->to_branch_id,
                $sequence++,
                (string) ($lane->transport_mode ?? null)
            );
            $lastLane = $lane;
        }

        $originBranchId      = (int) $first->from_branch_id;
        $destinationBranchId = (int) ($lastLane?->to_branch_id ?? $first->to_branch_id);

        // Transit branches (real hubs) derived from lane boundaries.
        $transitBranches = [];
        $pathCount = count($path);
        for ($i = 1; $i < $pathCount - 1; $i++) {
            $transitBranches[] = [
                'id'        => $path[$i]['id'],
                'name'      => $path[$i]['name'],
                'code'      => $path[$i]['code'],
                'latitude'  => $path[$i]['latitude'],
                'longitude' => $path[$i]['longitude'],
                'sequence'  => $path[$i]['sequence'],
            ];
        }

        // Combine checkpoints from all lanes
        $checkpoints = [];
        foreach ($lanes as $lane) {
            $checkpoints = array_merge($checkpoints, $lane->getCheckpoints());
        }

        $pathText = implode(' → ', array_filter(array_column($path, 'name')));

        return [
            'id'                    => (int) $route->id,
            'route_id'              => (int) $route->id,
            'route_code'            => (string) $route->route_code,
            'route_name'            => (string) $route->name,
            'name'                  => (string) $route->name,
            'origin_branch_id'      => $originBranchId,
            'destination_branch_id' => $destinationBranchId,
            'service_type'          => (string) $route->service_type,
            'transit_branch_ids'    => array_map(static fn ($b) => (int) $b['id'], $transitBranches),
            'transit_branches'      => $transitBranches,
            'transit_count'         => count($transitBranches),
            'transfer_count'        => max(1, $lanes->count()),
            'total_distance_km'     => $route->getTotalDistanceKm(),
            'total_estimated_hours' => (int) round($route->getTotalEstimatedHours()),
            'base_rate'             => (float) ($route->base_rate ?? 0),
            'currency'              => (string) ($route->currency ?? 'NPR'),
            'checkpoints'           => $checkpoints,
            'lanes'                 => $laneMappings,
            'path'                  => $path,
            'path_text'             => $pathText,
            'is_active'             => (bool) $route->is_active,
            'is_default'            => (bool) $route->is_default,
            'priority'              => (int) $route->priority,
        ];
    }

    private function branchNode(?CoverageLocation $branch, int $id, int $sequence, ?string $transportMode): array
    {
        return [
            'id'             => $id,
            'name'           => $branch?->name,
            'code'           => $branch?->code,
            'latitude'       => $branch?->latitude,
            'longitude'      => $branch?->longitude,
            'sequence'       => $sequence,
            'transport_mode' => $transportMode,
        ];
    }

    private function getEmptyFormat(BranchTransferRoute $route): array
    {
        return [
            'id'                    => (int) $route->id,
            'route_id'              => (int) $route->id,
            'route_code'            => (string) $route->route_code,
            'route_name'            => (string) $route->name,
            'name'                  => (string) $route->name,
            'origin_branch_id'      => null,
            'destination_branch_id' => null,
            'service_type'          => (string) $route->service_type,
            'transit_branch_ids'    => [],
            'transit_branches'      => [],
            'transit_count'         => 0,
            'transfer_count'        => 0,
            'total_distance_km'     => (float) ($route->distance_km ?? 0),
            'total_estimated_hours' => (int) ($route->estimated_hours ?? 0),
            'base_rate'             => (float) ($route->base_rate ?? 0),
            'currency'              => (string) ($route->currency ?? 'NPR'),
            'checkpoints'           => [],
            'lanes'                 => [],
            'path'                  => [],
            'path_text'             => '',
            'is_active'             => (bool) $route->is_active,
            'is_default'            => (bool) $route->is_default,
            'priority'              => (int) $route->priority,
        ];
    }

    private function branchName(int $id): string
    {
        $name = CoverageLocation::query()->whereKey($id)->value('name');

        return $name ?: "Branch #{$id}";
    }

    private function normalizeServiceType(string $serviceType): string
    {
        $serviceType = strtolower(trim($serviceType));

        if (!in_array($serviceType, ['standard', 'express', 'same_day', 'flight'], true)) {
            throw ValidationException::withMessages([
                'service_type' => ['Invalid transfer service type. Must be: standard, express, same_day, or flight.'],
            ]);
        }

        return $serviceType;
    }
}
