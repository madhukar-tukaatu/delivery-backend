<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Rate\Models\BranchTransferLane;
use Modules\Rate\Models\BranchTransferRoute;

/**
 * Manages transfer routes on the 2-table design:
 *   - branch_transfer_lanes  = physical connection (from -> to) with distance/ETA
 *   - branch_transfer_routes = named, service-specific route wrapping ONE lane,
 *     plus an optional ordered list of transit hubs (transit_branch_ids JSON).
 *
 * Route code + name are generated from origin, transits, destination and service.
 */
final class BranchTransferRouteService
{
    private const MAX_TRANSITS = 5;

    public function __construct(
        private readonly BranchTransferRouteCodeGenerator $codeGenerator
    ) {}

    public function create(array $data): BranchTransferRoute
    {
        return $this->save($data, null);
    }

    public function update(BranchTransferRoute $route, array $data): BranchTransferRoute
    {
        return $this->save($data, $route);
    }

    private function save(array $data, ?BranchTransferRoute $route): BranchTransferRoute
    {
        return DB::transaction(function () use ($data, $route): BranchTransferRoute {
            $originBranchId      = (int) $data['origin_branch_id'];
            $destinationBranchId = (int) $data['destination_branch_id'];
            $serviceType         = strtolower(trim((string) ($data['service_type'] ?? 'standard')));

            // Clean transit hubs (unique, integer, not origin/destination).
            $transitBranchIds = $this->normalizeTransits(
                (array) ($data['transit_branch_ids'] ?? []),
                $originBranchId,
                $destinationBranchId
            );

            // Find the physical lane for origin -> destination + service.
            $lane = BranchTransferLane::query()
                ->where('from_branch_id', $originBranchId)
                ->where('to_branch_id', $destinationBranchId)
                ->where('service_type', $serviceType)
                ->first();

            if (!$lane) {
                throw ValidationException::withMessages([
                    'origin_branch_id' => [
                        $this->buildMissingLaneMessage($originBranchId, $destinationBranchId, $serviceType),
                    ],
                ]);
            }

            // Generate code and name (respect what the client sent, else auto-build).
            $routeCode = !empty($data['route_code'])
                ? strtoupper(trim((string) $data['route_code']))
                : $this->codeGenerator->generate($originBranchId, $destinationBranchId, $serviceType, $transitBranchIds);

            $routeName = !empty($data['name'])
                ? trim((string) $data['name'])
                : $this->codeGenerator->generateName($originBranchId, $destinationBranchId, $transitBranchIds);

            // Enforce unique route code (ignore self on update).
            $codeExists = BranchTransferRoute::query()
                ->where('route_code', $routeCode)
                ->when($route !== null, static fn ($q) => $q->where('id', '!=', $route->id))
                ->exists();

            if ($codeExists) {
                throw ValidationException::withMessages([
                    'route_code' => ['A route with this code already exists.'],
                ]);
            }

            $isDefault = (bool) ($data['is_default'] ?? false);

            // Only one default per lane + service.
            if ($isDefault) {
                BranchTransferRoute::query()
                    ->where('branch_transfer_lane_id', $lane->id)
                    ->where('service_type', $serviceType)
                    ->when($route !== null, static fn ($q) => $q->where('id', '!=', $route->id))
                    ->update(['is_default' => false, 'updated_at' => now()]);
            }

            $routeData = [
                'route_code'              => $routeCode,
                'name'                    => $routeName,
                'branch_transfer_lane_id' => $lane->id,
                'transit_branch_ids'      => $transitBranchIds ?: null,
                'service_type'            => $serviceType,
                'base_rate'               => (float) ($data['base_rate'] ?? 0),
                'currency'                => $data['currency'] ?? 'NPR',
                'distance_km'             => $lane->distance_km,
                'estimated_hours'         => $lane->estimated_hours,
                'priority'                => max(1, (int) ($data['priority'] ?? 100)),
                'is_default'              => $isDefault,
                'is_active'               => (bool) ($data['is_active'] ?? true),
                'notes'                   => $data['notes'] ?? null,
            ];

            if ($route === null) {
                $route = BranchTransferRoute::query()->create($routeData);
            } else {
                $route->update($routeData);
            }

            return $route->fresh(['lane.fromBranch', 'lane.toBranch']);
        }, 3);
    }

    /**
     * Build a precise, actionable message explaining why the lane could not be found.
     * Names the branches and detects whether the lane exists for another service,
     * is inactive, or is completely missing (including the reverse direction).
     */
    private function buildMissingLaneMessage(int $originId, int $destinationId, string $serviceType): string
    {
        $branches = \Modules\Branch\Models\CoverageLocation::query()
            ->whereIn('id', [$originId, $destinationId])
            ->pluck('name', 'id');

        $originName      = $branches->get($originId) ?? "Branch #{$originId}";
        $destinationName = $branches->get($destinationId) ?? "Branch #{$destinationId}";
        $service         = strtoupper($serviceType);
        $pair            = "{$originName} → {$destinationName}";

        // Any lane between these two branches, regardless of service or status?
        $sameDirection = BranchTransferLane::query()
            ->where('from_branch_id', $originId)
            ->where('to_branch_id', $destinationId)
            ->get();

        // Lane exists for this service but is inactive.
        $inactiveSameService = $sameDirection->firstWhere(
            fn ($lane) => strtolower($lane->service_type) === $serviceType && !$lane->is_active
        );
        if ($inactiveSameService) {
            return "The lane {$pair} for {$service} service exists but is inactive. Activate it in Transfer Lanes before creating this route.";
        }

        // Lane exists between these branches, but only for other service types.
        if ($sameDirection->isNotEmpty()) {
            $available = $sameDirection
                ->pluck('service_type')
                ->map(fn ($s) => strtoupper($s))
                ->unique()
                ->implode(', ');

            return "A lane for {$pair} exists but not for {$service} service (available: {$available}). Add a {$service} lane in Transfer Lanes first.";
        }

        // Only the reverse direction exists.
        $reverseExists = BranchTransferLane::query()
            ->where('from_branch_id', $destinationId)
            ->where('to_branch_id', $originId)
            ->exists();
        if ($reverseExists) {
            return "Only the reverse lane ({$destinationName} → {$originName}) exists. Create the {$service} lane {$pair} in Transfer Lanes, or use 'Create reverse route'.";
        }

        // Completely missing.
        return "No transfer lane exists for {$pair} ({$service} service). Create this lane in Transfer Lanes first.";
    }

    /**
     * Clean transit IDs: unique, positive integers, never origin/destination, capped.
     *
     * @return int[]
     */
    private function normalizeTransits(array $transits, int $originBranchId, int $destinationBranchId): array
    {
        $clean = [];

        foreach ($transits as $id) {
            $id = (int) $id;
            if ($id <= 0 || $id === $originBranchId || $id === $destinationBranchId) {
                continue;
            }
            if (!in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        if (count($clean) > self::MAX_TRANSITS) {
            throw ValidationException::withMessages([
                'transit_branch_ids' => ['A route can have a maximum of ' . self::MAX_TRANSITS . ' transit hubs.'],
            ]);
        }

        return $clean;
    }
}
