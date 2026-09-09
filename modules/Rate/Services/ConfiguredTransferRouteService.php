<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Illuminate\Validation\ValidationException;
use Modules\Rate\Models\BranchTransferRoute;

final class ConfiguredTransferRouteService
{
    public function resolve(
        int $originBranchId,
        int $destinationBranchId,
        string $serviceType = 'standard'
    ): array {
        $serviceType = $this->normalizeServiceType($serviceType);

        // Find the lane first
        $lane = \Modules\Rate\Models\BranchTransferLane::query()
            ->where('from_branch_id', $originBranchId)
            ->where('to_branch_id', $destinationBranchId)
            ->where('service_type', $serviceType)
            ->where('is_active', true)
            ->first();

        if (!$lane) {
            throw ValidationException::withMessages([
                'transfer_route' => [
                    sprintf(
                        'No active transfer lane is configured from branch %d to branch %d for %s service.',
                        $originBranchId,
                        $destinationBranchId,
                        $serviceType
                    ),
                ],
            ]);
        }

        // Get the first active route for this lane
        $route = BranchTransferRoute::query()
            ->where('branch_transfer_lane_id', $lane->id)
            ->where('service_type', $serviceType)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->orderBy('id')
            ->first();

        if (!$route) {
            throw ValidationException::withMessages([
                'transfer_route' => [
                    sprintf(
                        'No active transfer route is configured for lane %d with %s service.',
                        $lane->id,
                        $serviceType
                    ),
                ],
            ]);
        }

        return $this->formatRoute($route);
    }

    public function formatRoute(BranchTransferRoute $route): array
    {
        $route->loadMissing('lane.fromBranch', 'lane.toBranch');

        $lane = $route->lane;
        if (!$lane) {
            return $this->getEmptyFormat($route);
        }

        $fromBranch = $lane->fromBranch;
        $toBranch = $lane->toBranch;

        // Build the ordered path: origin -> transits -> destination
        $path = [];
        $sequence = 0;

        $path[] = [
            'id'              => (int) $lane->from_branch_id,
            'name'            => $fromBranch?->name,
            'code'            => $fromBranch?->code,
            'latitude'        => $fromBranch?->latitude,
            'longitude'       => $fromBranch?->longitude,
            'sequence'        => $sequence++,
            'transport_mode'  => null,
        ];

        // Resolve transit branches (ordered)
        $transitIds = is_array($route->transit_branch_ids) ? $route->transit_branch_ids : [];
        $transitBranches = [];
        if (!empty($transitIds)) {
            $found = \Modules\Branch\Models\CoverageLocation::whereIn('id', $transitIds)
                ->get()
                ->keyBy('id');

            foreach ($transitIds as $tid) {
                $branch = $found->get($tid);
                if (!$branch) {
                    continue;
                }
                $transitBranches[] = [
                    'id'         => (int) $branch->id,
                    'name'       => $branch->name,
                    'code'       => $branch->code,
                    'latitude'   => $branch->latitude,
                    'longitude'  => $branch->longitude,
                    'sequence'   => $sequence++,
                ];
            }
        }

        $path = array_merge($path, $transitBranches);

        $path[] = [
            'id'              => (int) $lane->to_branch_id,
            'name'            => $toBranch?->name,
            'code'            => $toBranch?->code,
            'latitude'        => $toBranch?->latitude,
            'longitude'       => $toBranch?->longitude,
            'sequence'        => $sequence++,
            'transport_mode'  => $lane->transport_mode,
        ];

        $pathText = implode(' → ', array_filter(array_column($path, 'name')));

        return [
            'route_id'              => (int) $route->id,
            'route_code'            => (string) $route->route_code,
            'route_name'            => (string) $route->name,
            'origin_branch_id'      => (int) $lane->from_branch_id,
            'destination_branch_id' => (int) $lane->to_branch_id,
            'service_type'          => (string) $route->service_type,
            'transit_branch_ids'    => array_map('intval', $transitIds),
            'transit_branches'      => $transitBranches,
            'transit_count'         => count($transitBranches),
            'transfer_count'        => max(1, count($path) - 1),
            'total_distance_km'     => (float) ($route->distance_km ?? $lane->distance_km ?? 0),
            'total_estimated_hours' => (int) ($route->estimated_hours ?? $lane->estimated_hours ?? 0),
            'base_rate'             => (float) ($route->base_rate ?? 0),
            'currency'              => (string) ($route->currency ?? 'NPR'),
            'path'                  => $path,
            'path_text'             => $pathText,
            'is_active'             => (bool) $route->is_active,
            'is_default'            => (bool) $route->is_default,
            'priority'              => (int) $route->priority,
        ];
    }

    private function getEmptyFormat(BranchTransferRoute $route): array
    {
        return [
            'route_id'              => (int) $route->id,
            'route_code'            => (string) $route->route_code,
            'route_name'            => (string) $route->name,
            'origin_branch_id'      => null,
            'destination_branch_id' => null,
            'service_type'          => (string) $route->service_type,
            'total_distance_km'     => 0,
            'total_estimated_hours' => 0,
            'base_rate'             => (float) ($route->base_rate ?? 0),
            'currency'              => (string) ($route->currency ?? 'NPR'),
            'path'                  => [],
            'path_text'             => '',
            'is_active'             => (bool) $route->is_active,
            'priority'              => (int) $route->priority,
        ];
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
