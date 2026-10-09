<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Illuminate\Support\Facades\DB;
use Modules\Rate\Models\BranchTransferLane;
use Modules\Rate\Models\BranchTransferRoute;

/**
 * Suggests lane chains (direct + via transit branches) between two coverage
 * locations, built ONLY from existing active lanes of one service type.
 *
 * Lanes and routes reference coverage_locations ids (from_branch_id /
 * to_branch_id, origin_branch_id / destination_branch_id), so all ids here
 * are coverage ids, never franchise `branches` ids.
 *
 * Runs server-side over ALL lanes, so it is not affected by the admin list
 * pagination (the routes page used to load only the newest 500 lanes, which
 * silently dropped older lanes such as KTM -> Bharatpur from suggestions).
 *
 * Ranking:
 *   1. paths whose every hop already has an active route ("existing_routes"),
 *   2. other lane paths ("lanes"),
 *   then fewer hops, then shorter total distance.
 */
final class TransferLanePathFinder
{
    public const DEFAULT_MAX_LANES = 3; // up to 2 transit branches
    public const HARD_MAX_LANES = 4;
    public const DEFAULT_LIMIT = 20;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suggest(
        int $fromId,
        int $toId,
        string $serviceType,
        int $maxLanes = self::DEFAULT_MAX_LANES,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        if ($fromId <= 0 || $toId <= 0 || $fromId === $toId) {
            return [];
        }

        $lanes = BranchTransferLane::query()
            ->where('service_type', $serviceType)
            ->where('is_active', true)
            ->whereColumn('from_branch_id', '!=', 'to_branch_id')
            ->get([
                'id', 'from_branch_id', 'to_branch_id', 'service_type',
                'transport_mode', 'distance_km', 'estimated_hours',
                'priority', 'variant_name',
            ])
            ->map(fn (BranchTransferLane $l): array => [
                'id'              => (int) $l->id,
                'from_branch_id'  => (int) $l->from_branch_id,
                'to_branch_id'    => (int) $l->to_branch_id,
                'service_type'    => (string) $l->service_type,
                'transport_mode'  => (string) ($l->transport_mode ?? 'road'),
                'distance_km'     => (float) $l->distance_km,
                'estimated_hours' => (float) $l->estimated_hours,
                'priority'        => (int) ($l->priority ?? 100),
                'variant_name'    => $l->variant_name,
            ])
            ->all();

        $routePairs = [];
        BranchTransferRoute::query()
            ->where('service_type', $serviceType)
            ->where('is_active', true)
            ->get(['origin_branch_id', 'destination_branch_id'])
            ->each(function (BranchTransferRoute $r) use (&$routePairs): void {
                $routePairs[(int) $r->origin_branch_id . '-' . (int) $r->destination_branch_id] = true;
            });

        $paths = self::findPaths($lanes, $routePairs, $fromId, $toId, $maxLanes, $limit);

        // Attach coverage-location names for display.
        $ids = [];
        foreach ($paths as $p) {
            foreach ($p['branch_ids'] as $id) {
                $ids[$id] = true;
            }
        }
        $names = $ids
            ? DB::table('coverage_locations')->whereIn('id', array_keys($ids))->pluck('name', 'id')->all()
            : [];

        foreach ($paths as &$p) {
            $p['branch_names'] = array_map(
                fn (int $id): string => (string) ($names[$id] ?? "Branch #{$id}"),
                $p['branch_ids'],
            );
            foreach ($p['lanes'] as &$lane) {
                $lane['from_branch'] = ['id' => $lane['from_branch_id'], 'name' => $names[$lane['from_branch_id']] ?? null];
                $lane['to_branch'] = ['id' => $lane['to_branch_id'], 'name' => $names[$lane['to_branch_id']] ?? null];
                $lane['is_active'] = true;
            }
            unset($lane);
        }
        unset($p);

        return $paths;
    }

    /**
     * Pure path search over in-memory lanes (unit-testable).
     *
     * @param  array<int, array<string, mixed>>  $lanes  active lanes of one service
     * @param  array<string, bool>  $routePairs  "from-to" => true when an active route exists
     * @return array<int, array<string, mixed>>
     */
    public static function findPaths(
        array $lanes,
        array $routePairs,
        int $fromId,
        int $toId,
        int $maxLanes = self::DEFAULT_MAX_LANES,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        if ($fromId === $toId) {
            return [];
        }

        $maxLanes = max(1, min($maxLanes, self::HARD_MAX_LANES));
        $limit = max(1, $limit);

        $byFrom = [];
        $byPair = [];
        foreach ($lanes as $lane) {
            $a = (int) $lane['from_branch_id'];
            $b = (int) $lane['to_branch_id'];
            if ($a === $b) {
                continue;
            }
            $byFrom[$a][] = $lane;
            $byPair["{$a}-{$b}"][] = $lane;
        }

        $found = [];

        $dfs = function (int $current, array $path, array $visited) use (
            &$dfs, &$found, $byFrom, $byPair, $toId, $maxLanes
        ): void {
            $depth = count($path);

            // Last allowed hop: only lanes that land on the destination.
            if ($depth === $maxLanes - 1) {
                foreach ($byPair["{$current}-{$toId}"] ?? [] as $lane) {
                    $found[] = [...$path, $lane];
                }

                return;
            }

            foreach ($byFrom[$current] ?? [] as $lane) {
                $next = (int) $lane['to_branch_id'];
                if ($next === $toId) {
                    $found[] = [...$path, $lane];
                    continue;
                }
                if (isset($visited[$next])) {
                    continue;
                }
                $visited[$next] = true;
                $dfs($next, [...$path, $lane], $visited);
                unset($visited[$next]);
            }
        };

        $dfs($fromId, [], [$fromId => true]);

        $result = [];
        foreach ($found as $pathLanes) {
            $branchIds = [(int) $pathLanes[0]['from_branch_id']];
            $distance = 0.0;
            $hours = 0.0;
            $allRouted = true;
            foreach ($pathLanes as $lane) {
                $branchIds[] = (int) $lane['to_branch_id'];
                $distance += (float) ($lane['distance_km'] ?? 0);
                $hours += (float) ($lane['estimated_hours'] ?? 0);
                if (! isset($routePairs[(int) $lane['from_branch_id'] . '-' . (int) $lane['to_branch_id']])) {
                    $allRouted = false;
                }
            }

            $result[] = [
                'lane_ids'              => array_map(fn ($l): int => (int) $l['id'], $pathLanes),
                'branch_ids'            => $branchIds,
                'transit_count'         => count($pathLanes) - 1,
                'total_distance_km'     => round($distance, 2),
                'total_estimated_hours' => round($hours, 2),
                'all_hops_have_routes'  => $allRouted,
                'source'                => $allRouted ? 'existing_routes' : 'lanes',
                'lanes'                 => array_values($pathLanes),
            ];
        }

        usort($result, function (array $x, array $y): int {
            return [$x['all_hops_have_routes'] ? 0 : 1, $x['transit_count'], $x['total_distance_km']]
                <=> [$y['all_hops_have_routes'] ? 0 : 1, $y['transit_count'], $y['total_distance_km']];
        });

        return array_slice($result, 0, $limit);
    }
}