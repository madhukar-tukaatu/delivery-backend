<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Rate\Models\BranchTransferLane;
use Modules\Rate\Models\BranchTransferRoute;

final class BranchTransferRouteService
{
    private const MAXIMUM_TRANSFERS = 4;

    public function create(array $data): BranchTransferRoute
    {
        return $this->save($data, null);
    }

    public function update(
        BranchTransferRoute $route,
        array $data
    ): BranchTransferRoute {
        return $this->save($data, $route);
    }

    private function save(
        array $data,
        ?BranchTransferRoute $route
    ): BranchTransferRoute {
        return DB::transaction(function () use ($data, $route): BranchTransferRoute {
            $originBranchId = (int) $data['origin_branch_id'];
            $destinationBranchId = (int) $data['destination_branch_id'];
            $serviceType = strtolower(trim(
                (string) ($data['service_type'] ?? 'standard')
            ));

            $transitBranchIds = array_values(array_map(
                'intval',
                is_array($data['transit_branch_ids'] ?? null)
                    ? $data['transit_branch_ids']
                    : []
            ));

            $branchPath = [
                $originBranchId,
                ...$transitBranchIds,
                $destinationBranchId,
            ];

            if (count($branchPath) !== count(array_unique($branchPath))) {
                throw ValidationException::withMessages([
                    'transit_branch_ids' => [
                        'A transfer route cannot contain the same branch more than once.',
                    ],
                ]);
            }

            $transferCount = count($branchPath) - 1;

            if ($transferCount < 1) {
                throw ValidationException::withMessages([
                    'destination_branch_id' => [
                        'Origin and destination branches must be different.',
                    ],
                ]);
            }

            if ($transferCount > self::MAXIMUM_TRANSFERS) {
                throw ValidationException::withMessages([
                    'transit_branch_ids' => [
                        'A route can contain a maximum of four transfer lanes.',
                    ],
                ]);
            }

            $lanes = [];

            for ($index = 0; $index < count($branchPath) - 1; $index++) {
                $fromBranchId = $branchPath[$index];
                $toBranchId = $branchPath[$index + 1];

                $lane = BranchTransferLane::query()
                    ->where('from_branch_id', $fromBranchId)
                    ->where('to_branch_id', $toBranchId)
                    ->where('service_type', $serviceType)
                    ->where('is_active', true)
                    ->orderBy('priority')
                    ->orderBy('id')
                    ->first();

                if (!$lane) {
                    throw ValidationException::withMessages([
                        'transit_branch_ids' => [
                            sprintf(
                                'No active %s direct transfer lane exists from branch %d to branch %d.',
                                $serviceType,
                                $fromBranchId,
                                $toBranchId
                            ),
                        ],
                    ]);
                }

                $lanes[] = $lane;
            }

            $totalDistanceKm = array_reduce(
                $lanes,
                static fn (float $total, BranchTransferLane $lane): float =>
                    $total + (float) ($lane->distance_km ?? 0),
                0.0
            );

            $totalEstimatedHours = array_reduce(
                $lanes,
                static fn (int $total, BranchTransferLane $lane): int =>
                    $total + (int) ($lane->estimated_hours ?? 0),
                0
            );

            $isDefault = (bool) ($data['is_default'] ?? true);

            if ($isDefault) {
                BranchTransferRoute::query()
                    ->where('origin_branch_id', $originBranchId)
                    ->where('destination_branch_id', $destinationBranchId)
                    ->where('service_type', $serviceType)
                    ->when(
                        $route !== null,
                        static fn ($query) => $query->where('id', '!=', $route->id)
                    )
                    ->update([
                        'is_default' => false,
                        'updated_at' => now(),
                    ]);
            }

            $routeData = [
                'route_code' => strtoupper(trim((string) $data['route_code'])),
                'name' => trim((string) $data['name']),
                'origin_branch_id' => $originBranchId,
                'destination_branch_id' => $destinationBranchId,
                'service_type' => $serviceType,
                'base_rate' => round((float) $data['base_rate'], 2),
                'currency' => strtoupper((string) ($data['currency'] ?? 'NPR')),
                'transfer_count' => count($lanes),
                'transit_count' => count($transitBranchIds),
                'total_distance_km' => round($totalDistanceKm, 2),
                'total_estimated_hours' => $totalEstimatedHours,
                'priority' => max(1, (int) ($data['priority'] ?? 100)),
                'is_default' => $isDefault,
                'is_active' => (bool) ($data['is_active'] ?? true),
                'notes' => $data['notes'] ?? null,
            ];

            if ($route === null) {
                $route = BranchTransferRoute::query()->create($routeData);
            } else {
                $route->update($routeData);
            }

            $route->routeLanes()->delete();

            foreach ($lanes as $index => $lane) {
                $route->routeLanes()->create([
                    'branch_transfer_lane_id' => $lane->id,
                    'sequence_number' => $index + 1,
                ]);
            }

            return $route->fresh([
                'originBranch',
                'destinationBranch',
                'routeLanes.lane.fromBranch',
                'routeLanes.lane.toBranch',
            ]);
        }, 3);
    }
}
