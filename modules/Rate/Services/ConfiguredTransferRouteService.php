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

        $route = BranchTransferRoute::query()
            ->with([
                'originBranch',
                'destinationBranch',
                'lanes' => fn ($q) => $q->with('lane'),
            ])
            ->where('origin_branch_id', $originBranchId)
            ->where('destination_branch_id', $destinationBranchId)
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
                        'No active transfer route is configured from branch %d to branch %d for %s service.',
                        $originBranchId,
                        $destinationBranchId,
                        $serviceType
                    ),
                ],
            ]);
        }

        return $this->formatRoute($route);
    }

    public function formatRoute(BranchTransferRoute $route): array
    {
        $route->loadMissing(['originBranch', 'destinationBranch', 'lanes']);

        // Build full path: origin → lanes (with distances) → destination
        $path = [];
        $transitBranches = [];
        $totalDistanceKm = 0;
        $totalEstimatedHours = 0;

        // Add origin
        $path[] = [
            'id'              => (int) $route->origin_branch_id,
            'name'            => $route->originBranch?->name,
            'code'            => $route->originBranch?->code,
            'sequence'        => 0,
            'distance_km'     => 0,
            'estimated_hours' => 0,
            'transport_mode'  => null,
        ];

        // Add lanes as transit stops
        foreach ($route->lanes as $routeLane) {
            $lane = $routeLane->lane;

            if (!$lane || !$lane->is_active) {
                continue;
            }

            $distanceKm = (float) ($lane->distance_km ?? 0);
            $estimatedHours = (int) ($lane->estimated_hours ?? 0);

            $totalDistanceKm += $distanceKm;
            $totalEstimatedHours += $estimatedHours;

            $path[] = [
                'id'              => (int) $lane->to_branch_id,
                'name'            => $lane->toBranch?->name,
                'code'            => $lane->toBranch?->code,
                'sequence'        => (int) $routeLane->sequence_number,
                'distance_km'     => $distanceKm,
                'estimated_hours' => $estimatedHours,
                'transport_mode'  => $lane->transport_mode,
            ];

            // Add to transit branches list (all except the final destination)
            if ((int) $lane->to_branch_id !== (int) $route->destination_branch_id) {
                $transitBranches[] = [
                    'sequence'        => (int) $routeLane->sequence_number,
                    'branch_id'       => (int) $lane->to_branch_id,
                    'name'            => $lane->toBranch?->name,
                    'distance_km'     => $distanceKm,
                    'estimated_hours' => $estimatedHours,
                    'transport_mode'  => $lane->transport_mode,
                ];
            }
        }

        // Add destination (if no lanes, it's a direct route)
        if (empty($route->lanes) || (int) end($path)['id'] !== (int) $route->destination_branch_id) {
            $path[] = [
                'id'              => (int) $route->destination_branch_id,
                'name'            => $route->destinationBranch?->name,
                'code'            => $route->destinationBranch?->code,
                'sequence'        => count($path),
                'distance_km'     => 0,
                'estimated_hours' => 0,
                'transport_mode'  => null,
            ];
        }

        $pathText = implode(
            ' → ',
            array_values(array_filter(array_column($path, 'name')))
        );

        return [
            'route_id'              => (int) $route->id,
            'route_code'            => (string) $route->route_code,
            'route_name'            => (string) $route->name,
            'origin_branch_id'      => (int) $route->origin_branch_id,
            'destination_branch_id' => (int) $route->destination_branch_id,
            'service_type'          => (string) $route->service_type,
            'transfer_count'        => (int) $route->transfer_count,
            'transit_count'         => count($transitBranches),
            'lane_count'            => $route->lanes->count(),
            'total_distance_km'     => $totalDistanceKm,
            'total_estimated_hours' => $totalEstimatedHours,
            'base_rate'             => (float) ($route->base_rate ?? 0),
            'path'                  => $path,
            'path_text'             => $pathText,
            'transit_branches'      => $transitBranches,
        ];
    }

    private function normalizeServiceType(string $serviceType): string
    {
        $serviceType = strtolower(trim($serviceType));

        if (!in_array($serviceType, ['standard', 'express', 'same_day'], true)) {
            throw ValidationException::withMessages([
                'service_type' => ['Invalid transfer service type.'],
            ]);
        }

        return $serviceType;
    }
}
