<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Rate\Models\BranchTransferLane;
use Modules\Rate\Models\BranchTransferRoute;

final class ConfiguredTransferRouteService
{
    /**
     * Resolve an already configured complete transfer route.
     */
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

        if ($routeLanes->isEmpty()) {
            throw ValidationException::withMessages([
                'transfer_route' => [
                    'The configured transfer route has no lane mappings.',
                ],
            ]);
        }

        if ($routeLanes->count() !== (int) $route->transfer_count) {
            throw ValidationException::withMessages([
                'transfer_route' => [
                    'The configured transfer route contains missing lane mappings.',
                ],
            ]);
        }

        $laneMappings = $routeLanes
            ->map(static fn ($routeLane) => $routeLane->lane)
            ->filter()
            ->values();

        return $this->validateAndCalculateLanes(
            $laneMappings,
            $originBranchId,
            $destinationBranchId,
            $serviceType
        ) + [
            'route_id'   => (int) $route->id,
            'route_code' => (string) $route->route_code,
            'route_name' => (string) $route->name,
        ];
    }

    /**
     * Validate direct transfer lanes and calculate route path, distance, and ETA.
     */
    public function validateAndCalculateLanes(
        Collection|array $laneMappings,
        int $originBranchId,
        int $destinationBranchId,
        string $serviceType = 'standard'
    ): array {
        $serviceType = $this->normalizeServiceType($serviceType);

        $lanes = $laneMappings instanceof Collection
            ? $laneMappings->values()
            : collect($laneMappings)->values();

        if ($lanes->isEmpty()) {
            throw ValidationException::withMessages([
                'transfer_route' => [
                    'At least one active transfer lane is required.',
                ],
            ]);
        }

        $path         = [];
        $laneResponse = [];

        $totalDistance       = 0.0;
        $totalEstimatedHours = 0;

        $expectedFromBranchId = $originBranchId;

        foreach ($lanes as $index => $lane) {
            if (!$lane instanceof BranchTransferLane) {
                throw ValidationException::withMessages([
                    'transfer_route' => ['Invalid transfer lane supplied.'],
                ]);
            }

            if (!(bool) $lane->is_active) {
                throw ValidationException::withMessages([
                    'transfer_route' => [
                        sprintf('Transfer lane %d is inactive.', (int) $lane->id),
                    ],
                ]);
            }

            if (strtolower(trim((string) $lane->service_type)) !== $serviceType) {
                throw ValidationException::withMessages([
                    'transfer_route' => [
                        sprintf(
                            'Transfer lane %d uses service type %s instead of %s.',
                            (int) $lane->id,
                            (string) $lane->service_type,
                            $serviceType
                        ),
                    ],
                ]);
            }

            if ((int) $lane->from_branch_id !== $expectedFromBranchId) {
                throw ValidationException::withMessages([
                    'transfer_route' => [
                        sprintf(
                            'Transfer lane sequence is disconnected. Expected branch %d but lane %d starts from branch %d.',
                            $expectedFromBranchId,
                            (int) $lane->id,
                            (int) $lane->from_branch_id
                        ),
                    ],
                ]);
            }

            if ($index === 0) {
                $path[] = [
                    'id'   => (int) $lane->from_branch_id,
                    'name' => $lane->fromBranch?->name,
                    'code' => $lane->fromBranch?->code,
                ];
            }

            $path[] = [
                'id'   => (int) $lane->to_branch_id,
                'name' => $lane->toBranch?->name,
                'code' => $lane->toBranch?->code,
            ];

            $distanceKm      = (float) ($lane->distance_km ?? 0);
            $estimatedHours  = (int) ($lane->estimated_hours ?? 0);

            $totalDistance       += $distanceKm;
            $totalEstimatedHours += $estimatedHours;

            $laneResponse[] = [
                'sequence'       => $index + 1,
                'lane_id'        => (int) $lane->id,
                'from_branch_id' => (int) $lane->from_branch_id,
                'from_branch_name' => $lane->fromBranch?->name,
                'to_branch_id'   => (int) $lane->to_branch_id,
                'to_branch_name' => $lane->toBranch?->name,
                'service_type'   => $serviceType,
                'transport_mode' => $lane->transport_mode,
                'distance_km'    => $distanceKm,
                'estimated_hours' => $estimatedHours,
            ];

            $expectedFromBranchId = (int) $lane->to_branch_id;
        }

        if ($expectedFromBranchId !== $destinationBranchId) {
            throw ValidationException::withMessages([
                'transfer_route' => [
                    sprintf(
                        'The transfer route ends at branch %d but destination branch %d was requested.',
                        $expectedFromBranchId,
                        $destinationBranchId
                    ),
                ],
            ]);
        }

        $transitBranches = [];

        if (count($path) > 2) {
            $transitBranches = array_slice($path, 1, count($path) - 2);
            $transitBranches = array_values(array_map(
                static fn (array $branch, int $i): array => ['sequence' => $i + 1, ...$branch],
                $transitBranches,
                array_keys($transitBranches)
            ));
        }

        $pathText = implode(
            ' -> ',
            array_values(array_filter(array_column($path, 'name')))
        );

        return [
            'origin_branch_id'      => $originBranchId,
            'destination_branch_id' => $destinationBranchId,
            'service_type'          => $serviceType,
            'lane_count'            => $lanes->count(),
            'transfer_count'        => $lanes->count(),
            'transit_count'         => count($transitBranches),
            'total_distance_km'     => round($totalDistance, 2),
            'total_estimated_hours' => $totalEstimatedHours,
            'path'                  => $path,
            'path_text'             => $pathText,
            'transit_branches'      => $transitBranches,
            'lanes'                 => $laneResponse,
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
