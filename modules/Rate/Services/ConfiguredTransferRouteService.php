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
        $serviceType = strtolower(trim($serviceType));

        $route = BranchTransferRoute::query()
            ->with([
                'originBranch',
                'destinationBranch',
                'routeLanes.lane.fromBranch',
                'routeLanes.lane.toBranch',
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
                        'No active complete transfer route is configured from branch %d to branch %d for %s service.',
                        $originBranchId,
                        $destinationBranchId,
                        $serviceType
                    ),
                ],
            ]);
        }

        $routeLanes = $route->routeLanes
            ->sortBy('sequence_number')
            ->values();

        if ($routeLanes->count() !== (int) $route->transfer_count) {
            throw ValidationException::withMessages([
                'transfer_route' => [
                    'The configured transfer route contains missing lane mappings.',
                ],
            ]);
        }

        $path = [];
        $lanes = [];
        $expectedFromBranchId = $originBranchId;

        foreach ($routeLanes as $index => $routeLane) {
            $lane = $routeLane->lane;

            if (!$lane || !$lane->is_active) {
                throw ValidationException::withMessages([
                    'transfer_route' => [
                        'The configured transfer route contains an inactive or deleted direct lane.',
                    ],
                ]);
            }

            if ((string) $lane->service_type !== $serviceType) {
                throw ValidationException::withMessages([
                    'transfer_route' => [
                        'The configured route contains a lane for a different service type.',
                    ],
                ]);
            }

            if ((int) $lane->from_branch_id !== $expectedFromBranchId) {
                throw ValidationException::withMessages([
                    'transfer_route' => [
                        'The configured transfer-lane sequence is disconnected.',
                    ],
                ]);
            }

            if ($index === 0) {
                $path[] = [
                    'id' => (int) $lane->from_branch_id,
                    'name' => $lane->fromBranch?->name,
                    'code' => $lane->fromBranch?->code,
                ];
            }

            $path[] = [
                'id' => (int) $lane->to_branch_id,
                'name' => $lane->toBranch?->name,
                'code' => $lane->toBranch?->code,
            ];

            $lanes[] = [
                'sequence' => (int) $routeLane->sequence_number,
                'lane_id' => (int) $lane->id,
                'from_branch_id' => (int) $lane->from_branch_id,
                'from_branch_name' => $lane->fromBranch?->name,
                'to_branch_id' => (int) $lane->to_branch_id,
                'to_branch_name' => $lane->toBranch?->name,
                'service_type' => (string) $lane->service_type,
                'transport_mode' => $lane->transport_mode,
                'distance_km' => (float) ($lane->distance_km ?? 0),
                'estimated_hours' => (int) ($lane->estimated_hours ?? 0),
            ];

            $expectedFromBranchId = (int) $lane->to_branch_id;
        }

        if ($expectedFromBranchId !== $destinationBranchId) {
            throw ValidationException::withMessages([
                'transfer_route' => [
                    'The configured transfer route does not finish at the selected destination branch.',
                ],
            ]);
        }

        $transitBranches = count($path) > 2
            ? array_slice($path, 1, count($path) - 2)
            : [];

        $transitBranches = array_values(array_map(
            static fn (array $branch, int $index): array => [
                'sequence' => $index + 1,
                ...$branch,
            ],
            $transitBranches,
            array_keys($transitBranches)
        ));

        return [
            'route_id' => (int) $route->id,
            'route_code' => (string) $route->route_code,
            'route_name' => (string) $route->name,
            'origin_branch' => [
                'id' => (int) $route->origin_branch_id,
                'name' => $route->originBranch?->name,
                'code' => $route->originBranch?->code,
            ],
            'destination_branch' => [
                'id' => (int) $route->destination_branch_id,
                'name' => $route->destinationBranch?->name,
                'code' => $route->destinationBranch?->code,
            ],
            'service_type' => (string) $route->service_type,
            'base_rate' => (float) $route->base_rate,
            'currency' => (string) $route->currency,
            'transfer_count' => count($lanes),
            'lane_count' => count($lanes),
            'transit_count' => count($transitBranches),
            'total_distance_km' => (float) $route->total_distance_km,
            'total_estimated_hours' => (int) $route->total_estimated_hours,
            'path' => $path,
            'path_text' => implode(' -> ', array_column($path, 'name')),
            'transit_branches' => $transitBranches,
            'lanes' => $lanes,
        ];
    }
}
