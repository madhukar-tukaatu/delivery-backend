<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Rate\Models\BranchTransferRoute;

final class BranchTransferRouteService
{
    private const MAX_STOPS = 5;

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
            $originBranchId      = (int) $data['origin_branch_id'];
            $destinationBranchId = (int) $data['destination_branch_id'];
            $serviceType         = strtolower(trim((string) ($data['service_type'] ?? 'standard')));

            $stops = $this->buildStops(
                (array) ($data['stops'] ?? []),
                $originBranchId,
                $destinationBranchId
            );

            $transitCount    = count($stops);
            $transferCount   = $transitCount + 1;
            $totalDistance   = array_sum(array_column($stops, 'distance_km'))
                + (float) ($data['destination_distance_km'] ?? 0);
            $totalHours      = array_sum(array_column($stops, 'estimated_hours'))
                + (int) ($data['destination_estimated_hours'] ?? 0);

            $isDefault = (bool) ($data['is_default'] ?? true);

            if ($isDefault) {
                BranchTransferRoute::query()
                    ->where('origin_branch_id', $originBranchId)
                    ->where('destination_branch_id', $destinationBranchId)
                    ->where('service_type', $serviceType)
                    ->when(
                        $route !== null,
                        static fn ($q) => $q->where('id', '!=', $route->id)
                    )
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            $routeData = [
                'route_code'            => strtoupper(trim((string) $data['route_code'])),
                'name'                  => trim((string) $data['name']),
                'origin_branch_id'      => $originBranchId,
                'destination_branch_id' => $destinationBranchId,
                'service_type'          => $serviceType,
                'transfer_count'        => $transferCount,
                'transit_count'         => $transitCount,
                'stops'                 => $stops ?: null,
                'total_distance_km'     => round($totalDistance, 2),
                'total_estimated_hours' => $totalHours,
                'priority'              => max(1, (int) ($data['priority'] ?? 100)),
                'is_default'            => $isDefault,
                'is_active'             => (bool) ($data['is_active'] ?? true),
                'notes'                 => $data['notes'] ?? null,
            ];

            if ($route === null) {
                $route = BranchTransferRoute::query()->create($routeData);
            } else {
                $route->update($routeData);
            }

            return $route->fresh(['originBranch', 'destinationBranch']);
        }, 3);
    }

    private function buildStops(
        array $stops,
        int $originBranchId,
        int $destinationBranchId
    ): array {
        if (empty($stops)) {
            return [];
        }

        if (count($stops) > self::MAX_STOPS) {
            throw ValidationException::withMessages([
                'stops' => ['A route can have a maximum of ' . self::MAX_STOPS . ' transit stops.'],
            ]);
        }

        $branchIds = array_column($stops, 'branch_id');

        if (in_array($originBranchId, $branchIds, true)) {
            throw ValidationException::withMessages([
                'stops' => ['Origin branch cannot be a transit stop.'],
            ]);
        }

        if (in_array($destinationBranchId, $branchIds, true)) {
            throw ValidationException::withMessages([
                'stops' => ['Destination branch cannot be a transit stop.'],
            ]);
        }

        if (count($branchIds) !== count(array_unique($branchIds))) {
            throw ValidationException::withMessages([
                'stops' => ['Each transit stop must be a unique branch.'],
            ]);
        }

        $branchNames = DB::table('branches')
            ->whereIn('id', $branchIds)
            ->pluck('name', 'id');

        $built = [];

        foreach (array_values($stops) as $index => $stop) {
            $branchId = (int) $stop['branch_id'];

            if (!$branchNames->has($branchId)) {
                throw ValidationException::withMessages([
                    "stops.{$index}.branch_id" => ["Branch {$branchId} does not exist."],
                ]);
            }

            $built[] = [
                'sequence'        => $index + 1,
                'branch_id'       => $branchId,
                'name'            => $branchNames->get($branchId),
                'distance_km'     => max(0, (float) ($stop['distance_km'] ?? 0)),
                'estimated_hours' => max(0, (int) ($stop['estimated_hours'] ?? 0)),
                'transport_mode'  => $stop['transport_mode'] ?? null,
            ];
        }

        return $built;
    }
}
