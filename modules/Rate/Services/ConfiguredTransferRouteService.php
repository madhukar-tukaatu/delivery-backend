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
            ->with(['originBranch', 'destinationBranch'])
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
        $route->loadMissing(['originBranch', 'destinationBranch']);

        $stops = is_array($route->stops) ? $route->stops : [];

        // Build full path: origin → stops → destination
        $path = [];

        $path[] = [
            'id'   => (int) $route->origin_branch_id,
            'name' => $route->originBranch?->name,
            'code' => $route->originBranch?->code,
        ];

        foreach ($stops as $stop) {
            $path[] = [
                'id'              => (int) $stop['branch_id'],
                'name'            => $stop['name'] ?? null,
                'code'            => null,
                'sequence'        => (int) $stop['sequence'],
                'distance_km'     => (float) ($stop['distance_km'] ?? 0),
                'estimated_hours' => (int) ($stop['estimated_hours'] ?? 0),
                'transport_mode'  => $stop['transport_mode'] ?? null,
            ];
        }

        $path[] = [
            'id'   => (int) $route->destination_branch_id,
            'name' => $route->destinationBranch?->name,
            'code' => $route->destinationBranch?->code,
        ];

        $transitBranches = count($stops) > 0
            ? array_values(array_map(
                static fn (array $stop, int $i): array => [
                    'sequence'        => $i + 1,
                    'branch_id'       => (int) $stop['branch_id'],
                    'name'            => $stop['name'] ?? null,
                    'distance_km'     => (float) ($stop['distance_km'] ?? 0),
                    'estimated_hours' => (int) ($stop['estimated_hours'] ?? 0),
                    'transport_mode'  => $stop['transport_mode'] ?? null,
                ],
                $stops,
                array_keys($stops)
            ))
            : [];

        $pathText = implode(
            ' -> ',
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
            'transit_count'         => count($stops),
            'lane_count'            => (int) $route->transfer_count,
            'total_distance_km'     => (float) $route->total_distance_km,
            'total_estimated_hours' => (int) $route->total_estimated_hours,
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
