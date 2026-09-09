<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Rate\Models\BranchTransferLane;
use Modules\Rate\Models\BranchTransferRoute;
use Modules\Rate\Models\BranchTransferRouteLane;

/**
 * Manages transfer routes on the "ordered lanes + checkpoints" design.
 *
 * Concepts:
 *   - LANE  = a direct physical connection between two BRANCHES (from -> to) with
 *             distance/ETA. Stored once, reusable.
 *   - ROUTE = a named, service-specific PATH from an origin branch to a destination
 *             branch, built from an ORDERED LIST OF LANES:
 *               * 1 lane  => direct route (e.g. KTM -> PKR)
 *               * 2+ lanes => route via transit BRANCHES (e.g. KTM -> Bardibas -> Itahari)
 *             The transit branches are DERIVED from the lane boundaries; a direct
 *             origin->destination lane is NOT required for a multi-lane route.
 *   - CHECKPOINTS = map-picked ROAD WAYPOINTS (name/city/landmark + coordinates),
 *             NOT branches. They describe the road a route drives (e.g. via Mugling
 *             vs via Gorkha) and let two routes share the same lane(s) yet differ.
 *
 * Multiple routes may exist for the same origin/destination/service = ALTERNATIVES,
 * selected at resolve time by is_default, then priority, among is_active routes
 * (so a blocked road = deactivate that route and fall back to the next).
 *
 * Distance/ETA are summed from the lanes. Customer price is NOT set here; it lives
 * in branch pricing. base_rate on the route is operational metadata only.
 */
final class BranchTransferRouteService
{
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
            $serviceType = strtolower(trim((string) ($data['service_type'] ?? 'standard')));

            // 1. Load the ordered lanes exactly as given.
            $laneIds = array_values(array_map('intval', (array) ($data['lane_ids'] ?? [])));
            $lanes   = $this->loadOrderedLanes($laneIds);

            // 2. Validate connectivity and service consistency across the chain.
            $this->assertChainIsValid($lanes, $serviceType);

            // 3. Derive endpoints from the chain.
            $originBranchId      = (int) $lanes[0]->from_branch_id;
            $destinationBranchId = (int) $lanes[count($lanes) - 1]->to_branch_id;

            // 4. Normalize checkpoints (road waypoints, not branches).
            $checkpoints = $this->normalizeCheckpoints((array) ($data['checkpoints'] ?? []));

            // 5. Code + name (respect client input, else auto-build from endpoints/transits).
            $transitBranchIds = $this->deriveTransitBranchIds($lanes);

            $routeCode = !empty($data['route_code'])
                ? strtoupper(trim((string) $data['route_code']))
                : $this->codeGenerator->generate($originBranchId, $destinationBranchId, $serviceType, $transitBranchIds);

            $routeName = !empty($data['name'])
                ? trim((string) $data['name'])
                : $this->codeGenerator->generateName($originBranchId, $destinationBranchId, $transitBranchIds);

            // 6. Unique route code (ignore self on update).
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

            // 7. Only one default per origin+destination+service (across alternatives).
            if ($isDefault) {
                $this->clearOtherDefaults($originBranchId, $destinationBranchId, $serviceType, $route);
            }

            // 8. Aggregate distance/ETA from the lane segments (single source of truth).
            $totalDistance = array_sum(array_map(static fn ($l) => (float) $l->distance_km, $lanes));
            $totalHours    = array_sum(array_map(static fn ($l) => (float) $l->estimated_hours, $lanes));

            // Build the payload with only columns that exist on this environment,
            // so a pending migration degrades gracefully instead of a SQL error.
            $routeData = [
                'route_code'      => $routeCode,
                'name'            => $routeName,
                'service_type'    => $serviceType,
                'priority'        => max(1, (int) ($data['priority'] ?? 100)),
                'is_default'      => $isDefault,
                'is_active'       => (bool) ($data['is_active'] ?? true),
                'notes'           => $data['notes'] ?? null,
            ];

            $optional = [
                'branch_transfer_lane_id' => $lanes[0]->id,             // anchor = first lane
                'transit_branch_ids'      => $transitBranchIds ?: null,
                'checkpoints'             => $checkpoints ?: null,
                'base_rate'               => (float) ($data['base_rate'] ?? 0),
                'currency'                => $data['currency'] ?? 'NPR',
                'distance_km'             => $totalDistance,
                'estimated_hours'         => (int) round($totalHours),
            ];

            foreach ($optional as $column => $value) {
                if (Schema::hasColumn('branch_transfer_routes', $column)) {
                    $routeData[$column] = $value;
                }
            }

            if ($route === null) {
                $route = BranchTransferRoute::query()->create($routeData);
            } else {
                $route->update($routeData);
            }

            // 9. Replace ordered lane segments.
            $this->syncRouteLanes($route, $lanes);

            return $route->fresh(['routeLanes.lane.fromBranch', 'routeLanes.lane.toBranch']);
        }, 3);
    }

    /**
     * Load lanes in the exact order of the given IDs.
     *
     * @param int[] $laneIds
     * @return BranchTransferLane[]
     */
    private function loadOrderedLanes(array $laneIds): array
    {
        if ($laneIds === []) {
            throw ValidationException::withMessages([
                'lane_ids' => ['A route must include at least one lane.'],
            ]);
        }

        $found = BranchTransferLane::query()
            ->whereIn('id', $laneIds)
            ->get()
            ->keyBy('id');

        $ordered = [];
        foreach ($laneIds as $id) {
            $lane = $found->get($id);
            if (!$lane) {
                throw ValidationException::withMessages([
                    'lane_ids' => ["Lane #{$id} does not exist."],
                ]);
            }
            $ordered[] = $lane;
        }

        return $ordered;
    }

    /**
     * Validate that the lanes form a connected chain for the given service:
     *   - every lane is active and matches the route's service_type
     *   - each lane's to_branch equals the next lane's from_branch
     *   - the path does not revisit a branch (no loops)
     *
     * @param BranchTransferLane[] $lanes
     */
    private function assertChainIsValid(array $lanes, string $serviceType): void
    {
        $count = count($lanes);
        $seenBranches = [];

        for ($i = 0; $i < $count; $i++) {
            $lane = $lanes[$i];

            if (strtolower((string) $lane->service_type) !== $serviceType) {
                throw ValidationException::withMessages([
                    'lane_ids' => [
                        sprintf(
                            'Lane %s → %s is for %s service, but this route is %s.',
                            $this->branchName((int) $lane->from_branch_id),
                            $this->branchName((int) $lane->to_branch_id),
                            strtoupper((string) $lane->service_type),
                            strtoupper($serviceType)
                        ),
                    ],
                ]);
            }

            if (!$lane->is_active) {
                throw ValidationException::withMessages([
                    'lane_ids' => [
                        sprintf(
                            'Lane %s → %s is inactive. Activate it before using it in a route.',
                            $this->branchName((int) $lane->from_branch_id),
                            $this->branchName((int) $lane->to_branch_id)
                        ),
                    ],
                ]);
            }

            // Connectivity: this lane's "from" must equal the previous lane's "to".
            if ($i > 0) {
                $prev = $lanes[$i - 1];
                if ((int) $prev->to_branch_id !== (int) $lane->from_branch_id) {
                    throw ValidationException::withMessages([
                        'lane_ids' => [
                            sprintf(
                                'Lanes are not connected: %s → %s cannot be followed by %s → %s. '
                                . 'Each lane must start where the previous one ends.',
                                $this->branchName((int) $prev->from_branch_id),
                                $this->branchName((int) $prev->to_branch_id),
                                $this->branchName((int) $lane->from_branch_id),
                                $this->branchName((int) $lane->to_branch_id)
                            ),
                        ],
                    ]);
                }
            }

            // Loop detection on branch path.
            $from = (int) $lane->from_branch_id;
            if ($i === 0) {
                $seenBranches[$from] = true;
            }
            $to = (int) $lane->to_branch_id;
            if (isset($seenBranches[$to])) {
                throw ValidationException::withMessages([
                    'lane_ids' => ['The route path revisits a branch (loop). Each branch may appear once.'],
                ]);
            }
            $seenBranches[$to] = true;
        }
    }

    /**
     * Transit branches derived from the lane chain: the "to" of every lane except
     * the last (equivalently the "from" of every lane except the first).
     *
     * @param BranchTransferLane[] $lanes
     * @return int[]
     */
    private function deriveTransitBranchIds(array $lanes): array
    {
        $count = count($lanes);
        if ($count < 2) {
            return [];
        }

        $transits = [];
        for ($i = 0; $i < $count - 1; $i++) {
            $transits[] = (int) $lanes[$i]->to_branch_id;
        }

        return $transits;
    }

    /**
     * Persist the ordered lane segments for a route, replacing any existing ones.
     *
     * @param BranchTransferLane[] $lanes
     */
    private function syncRouteLanes(BranchTransferRoute $route, array $lanes): void
    {
        BranchTransferRouteLane::query()
            ->where('branch_transfer_route_id', $route->id)
            ->delete();

        $sequence = 1;
        foreach ($lanes as $lane) {
            BranchTransferRouteLane::query()->create([
                'branch_transfer_route_id' => $route->id,
                'branch_transfer_lane_id'  => $lane->id,
                'sequence_number'          => $sequence++,
            ]);
        }
    }

    /**
     * Clear the default flag on other routes for the same origin/destination/service.
     * Origin/destination are derived from each route's ordered lanes.
     */
    private function clearOtherDefaults(int $originBranchId, int $destinationBranchId, string $serviceType, ?BranchTransferRoute $current): void
    {
        BranchTransferRoute::query()
            ->where('service_type', $serviceType)
            ->where('is_default', true)
            ->when($current !== null, static fn ($q) => $q->where('id', '!=', $current->id))
            ->get()
            ->each(function (BranchTransferRoute $other) use ($originBranchId, $destinationBranchId): void {
                $path = $other->getPathBranchIds();
                if ($path === []) {
                    return;
                }
                if ((int) $path[0] === $originBranchId
                    && (int) end($path) === $destinationBranchId) {
                    $other->update(['is_default' => false]);
                }
            });
    }

    /**
     * Clean checkpoints: keep only entries with a usable name or coordinates.
     *
     * @return array<int, array{name:?string, city:?string, landmark:?string, latitude:?float, longitude:?float}>
     */
    private function normalizeCheckpoints(array $checkpoints): array
    {
        $clean = [];

        foreach ($checkpoints as $cp) {
            if (!is_array($cp)) {
                continue;
            }

            $name      = isset($cp['name']) ? trim((string) $cp['name']) : '';
            $city      = isset($cp['city']) ? trim((string) $cp['city']) : null;
            $landmark  = isset($cp['landmark']) ? trim((string) $cp['landmark']) : null;
            $latitude  = isset($cp['latitude']) && $cp['latitude'] !== '' ? (float) $cp['latitude'] : null;
            $longitude = isset($cp['longitude']) && $cp['longitude'] !== '' ? (float) $cp['longitude'] : null;

            // Skip empty rows (no name and no coordinates).
            if ($name === '' && $latitude === null && $longitude === null) {
                continue;
            }

            $clean[] = [
                'name'      => $name !== '' ? $name : null,
                'city'      => $city !== '' ? $city : null,
                'landmark'  => $landmark !== '' ? $landmark : null,
                'latitude'  => $latitude,
                'longitude' => $longitude,
            ];
        }

        return $clean;
    }

    private function branchName(int $id): string
    {
        $name = \Modules\Branch\Models\CoverageLocation::query()
            ->whereKey($id)
            ->value('name');

        return $name ?: "Branch #{$id}";
    }
}
