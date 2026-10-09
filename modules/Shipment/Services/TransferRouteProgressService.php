<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Dispatch\Models\DispatchManifestItem;
use Modules\Rate\Models\BranchTransferRoute;
use Modules\Rate\Services\ConfiguredTransferRouteService;
use Modules\Shipment\Models\Shipment;

/**
 * TransferRouteProgressService
 * ----------------------------
 * Single source of truth for hop-by-hop transfer progression.
 *
 * Prefers the shipment's assigned transfer_route_id snapshot; falls back to
 * ConfiguredTransferRouteService only when no snapshot is set.
 * Distinguishes: final destination, current branch, next hop, remaining route,
 * ready-for-last-mile. Never overwrites destination_branch_id.
 */
final class TransferRouteProgressService
{
    /** @var array<int, int|null> */
    private array $coverageByBranch = [];

    /** @var array<int, int|null> */
    private array $branchByCoverage = [];

    /** @var array<int, bool> branch id => exists (route operability checks) */
    private array $branchExists = [];

    public function __construct(
        private readonly ConfiguredTransferRouteService $configuredRoutes,
    ) {
    }

    /**
     * Resolve operational progress for a shipment at an optional branch.
     *
     * @return array{
     *   transfer_route_id: ?int,
     *   route_code: ?string,
     *   route_name: ?string,
     *   service_type: string,
     *   path_coverage_ids: int[],
     *   path_branch_ids: int[],
     *   path_text: ?string,
     *   current_branch_id: ?int,
     *   current_coverage_id: ?int,
     *   destination_branch_id: ?int,
     *   next_hop_branch_id: ?int,
     *   next_hop_coverage_id: ?int,
     *   next_hop_name: ?string,
     *   remaining_path_branch_ids: int[],
     *   remaining_path_coverage_ids: int[],
     *   transfer_leg_index: ?int,
     *   is_at_destination: bool,
     *   ready_for_last_mile: bool,
     *   has_route: bool,
     * }
     */
    public function resolveForShipment(Shipment $shipment, ?int $atBranchId = null): array
    {
        $serviceType = strtolower((string) ($shipment->service_type ?? 'standard')) ?: 'standard';
        $destinationBranchId = (int) ($shipment->destination_sub_branch_id
            ?? $shipment->destination_branch_id
            ?? 0) ?: null;

        $currentBranchId = $atBranchId
            ?: (int) ($shipment->current_branch_id
                ?? $shipment->origin_sub_branch_id
                ?? $shipment->origin_branch_id
                ?? 0) ?: null;

        $route = $this->resolveRouteModel($shipment, $currentBranchId, $destinationBranchId, $serviceType);

        if (!$route) {
            // Thin decision: at-final uses operational/coverage equality only.
            // Do NOT invent next_hop = final when no route exists - Transfers Outbound owns next-hop resolution.
            $atDest = $this->sameOperationalLocation($currentBranchId, $destinationBranchId);

            return [
                'transfer_route_id' => null,
                'route_code' => null,
                'route_name' => null,
                'service_type' => $serviceType,
                'path_coverage_ids' => [],
                'path_branch_ids' => [],
                'path_text' => $shipment->path_text ?? null,
                'current_branch_id' => $currentBranchId,
                'current_coverage_id' => $this->coverageIdForBranch($currentBranchId),
                'destination_branch_id' => $destinationBranchId,
                'next_hop_branch_id' => null,
                'next_hop_coverage_id' => null,
                'next_hop_name' => null,
                'remaining_path_branch_ids' => [],
                'remaining_path_coverage_ids' => [],
                'transfer_leg_index' => null,
                'is_at_destination' => $atDest,
                'ready_for_last_mile' => $atDest,
                'has_route' => false,
            ];
        }

        $pathCoverage = array_map('intval', $route->getPathBranchIds());
        $pathBranches = [];
        foreach ($pathCoverage as $cov) {
            $pathBranches[] = $this->branchIdForCoverage((int) $cov) ?? (int) $cov;
        }
        $pathBranches = array_map('intval', $pathBranches);

        $currentCoverageId = $this->coverageIdForBranch($currentBranchId);
        $idx = $this->indexOnPath($pathCoverage, $pathBranches, $currentBranchId, $currentCoverageId);

        $isAtDestination = false;
        $nextHopCoverageId = null;
        $nextHopBranchId = null;
        $legIndex = null;
        $remainingCoverage = [];
        $remainingBranches = [];

        if ($idx !== false) {
            $legIndex = (int) $idx;
            $isAtDestination = $idx >= count($pathCoverage) - 1;
            // Next hop is ALWAYS the immediate next stop on the path — never jump to final
            // while transit hubs remain (e.g. ITA→BHA→BRN at ITA must yield BHA, not BRN).
            if (!$isAtDestination && isset($pathCoverage[$idx + 1])) {
                $nextHopCoverageId = (int) $pathCoverage[$idx + 1];
                $nextHopBranchId = $this->branchIdForCoverage($nextHopCoverageId)
                    ?? (isset($pathBranches[$idx + 1]) ? (int) $pathBranches[$idx + 1] : $nextHopCoverageId);
                $remainingCoverage = array_values(array_slice($pathCoverage, $idx + 1));
                $remainingBranches = array_values(array_slice($pathBranches, $idx + 1));
            }
        } elseif ($this->sameOperationalLocation($currentBranchId, $destinationBranchId)) {
            $isAtDestination = true;
            $legIndex = max(0, count($pathCoverage) - 1);
        }

        // Destination branch wins over coverage final for "at destination"
        // (ID match OR shared coverage / operational mapping).
        if ($this->sameOperationalLocation($currentBranchId, $destinationBranchId)) {
            $isAtDestination = true;
            $nextHopBranchId = null;
            $nextHopCoverageId = null;
            $remainingCoverage = [];
            $remainingBranches = [];
        }

        // Guard: never report final destination as next hop while an earlier path stop remains.
        if ($nextHopBranchId !== null && $destinationBranchId !== null
            && (int) $nextHopBranchId === (int) $destinationBranchId
            && count($remainingBranches) > 1) {
            $nextHopBranchId = (int) $remainingBranches[0];
            $nextHopCoverageId = $remainingCoverage[0] ?? $this->coverageIdForBranch($nextHopBranchId);
        }

        $pathText = $this->pathTextFromCoverage($pathCoverage) ?: ($shipment->path_text ?? null);

        return [
            'transfer_route_id' => (int) $route->id,
            'route_code' => (string) $route->route_code,
            'route_name' => (string) $route->name,
            'service_type' => (string) ($route->service_type ?? $serviceType),
            'path_coverage_ids' => $pathCoverage,
            'path_branch_ids' => $pathBranches,
            'path_text' => $pathText,
            'current_branch_id' => $currentBranchId,
            'current_coverage_id' => $currentCoverageId,
            'destination_branch_id' => $destinationBranchId,
            'next_hop_branch_id' => $nextHopBranchId,
            'next_hop_coverage_id' => $nextHopCoverageId,
            'next_hop_name' => $nextHopBranchId ? $this->branchName($nextHopBranchId) : null,
            'remaining_path_branch_ids' => $remainingBranches,
            'remaining_path_coverage_ids' => $remainingCoverage,
            'transfer_leg_index' => $legIndex,
            'is_at_destination' => $isAtDestination,
            'ready_for_last_mile' => $isAtDestination,
            'has_route' => true,
        ];
    }

    /**
     * Persist next_hop / leg / path onto the shipment without touching destination.
     */
    public function applyProgressToShipment(Shipment $shipment, ?int $atBranchId = null): Shipment
    {
        $progress = $this->resolveForShipment($shipment, $atBranchId);
        $columns = Schema::getColumnListing('shipments');
        $updates = [];

        if (in_array('transfer_route_id', $columns, true) && !empty($progress['transfer_route_id'])) {
            $updates['transfer_route_id'] = $progress['transfer_route_id'];
        }
        if (in_array('next_hop_branch_id', $columns, true)) {
            $updates['next_hop_branch_id'] = $progress['ready_for_last_mile']
                ? null
                : ($progress['next_hop_branch_id'] ?? null);
        }
        if (in_array('transfer_leg_index', $columns, true)) {
            $updates['transfer_leg_index'] = $progress['transfer_leg_index'];
        }
        if (in_array('path_text', $columns, true) && !empty($progress['path_text'])) {
            $updates['path_text'] = $progress['path_text'];
        }
        if (in_array('route_code', $columns, true) && !empty($progress['route_code'])) {
            $updates['route_code'] = $progress['route_code'];
        }
        if (in_array('route_name', $columns, true) && !empty($progress['route_name'])) {
            $updates['route_name'] = $progress['route_name'];
        }

        if ($updates !== []) {
            $shipment->update($updates);
        }

        return $shipment->fresh();
    }

    /**
     * Reject dispatch when to_branch is not the computed next hop (skip-hop guard).
     */
    public function assertNextHopMatches(Shipment $shipment, int $toBranchId, ?int $atBranchId = null): void
    {
        $progress = $this->resolveForShipment($shipment, $atBranchId);

        if ($progress['ready_for_last_mile']) {
            throw ValidationException::withMessages([
                'to_branch_id' => [
                    sprintf(
                        'Shipment %s is already at its final destination — not eligible for transfer.',
                        $shipment->tracking_number ?? $shipment->id
                    ),
                ],
            ]);
        }

        $expected = (int) ($progress['next_hop_branch_id'] ?? 0);
        $to = (int) ($this->resolveOperationalBranchId($toBranchId) ?? $toBranchId);
        $expectedOp = (int) ($this->resolveOperationalBranchId($expected) ?? $expected);

        if ($expected <= 0) {
            throw ValidationException::withMessages([
                'to_branch_id' => [
                    sprintf(
                        'Cannot determine next hop for shipment %s. Assign a transfer route first.',
                        $shipment->tracking_number ?? $shipment->id
                    ),
                ],
            ]);
        }

        if ($to !== $expectedOp && !$this->sameOperationalLocation($to, $expectedOp)) {
            throw ValidationException::withMessages([
                'to_branch_id' => [
                    sprintf(
                        'Skip-hop rejected for %s: next hop is %s (branch #%d), not branch #%d.',
                        $shipment->tracking_number ?? $shipment->id,
                        $progress['next_hop_name'] ?? 'unknown',
                        $expected,
                        $to
                    ),
                ],
            ]);
        }
    }

    /**
     * Prevent a shipment from sitting on two open transfer manifests.
     */
    public function assertNotInActiveManifest(Shipment $shipment, ?int $exceptManifestId = null): void
    {
        if (!Schema::hasTable('dispatch_manifest_items')) {
            return;
        }

        $query = DispatchManifestItem::query()
            ->where('shipment_id', $shipment->id)
            ->whereIn('status', ['sent', 'dispatched', 'in_transit', 'draft', 'pending', 'added'])
            ->whereHas('manifest', function ($q) {
                // open = TR being loaded, partially_received = TR whose other parcels are still open.
                $q->whereIn('status', ['open', 'draft', 'dispatched', 'in_transit', 'partially_received', 'pending', 'created']);
            });

        if ($exceptManifestId) {
            $query->where('dispatch_manifest_id', '!=', $exceptManifestId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'shipment_ids' => [
                    sprintf(
                        'Shipment %s is already on an active transfer manifest.',
                        $shipment->tracking_number ?? $shipment->id
                    ),
                ],
            ]);
        }
    }

    /**
     * Prefer assigned route snapshot; otherwise resolve configured route for OD+service.
     */
    public function resolveRouteModel(
        Shipment $shipment,
        ?int $currentBranchId,
        ?int $destinationBranchId,
        string $serviceType
    ): ?BranchTransferRoute {
        $assignedId = (int) ($shipment->transfer_route_id ?? 0);
        if ($assignedId > 0) {
            $assigned = BranchTransferRoute::query()
                ->with(['routeLanes.lane.fromBranch', 'routeLanes.lane.toBranch', 'lane.fromBranch', 'lane.toBranch'])
                ->find($assignedId);
            if ($assigned && $assigned->is_active) {
                return $assigned;
            }
        }

        if (!$destinationBranchId) {
            return null;
        }

        $originBranchId = (int) ($currentBranchId
            ?? $shipment->origin_sub_branch_id
            ?? $shipment->origin_branch_id
            ?? 0);
        if ($originBranchId <= 0) {
            return null;
        }

        // Match using coverage ids on the lane path (same as TransferController).
        $fromCoverage = $this->coverageIdForBranch($originBranchId) ?? $originBranchId;
        $toCoverage = $this->coverageIdForBranch($destinationBranchId) ?? $destinationBranchId;

        $candidates = BranchTransferRoute::query()
            ->where('service_type', $serviceType)
            ->where('is_active', true)
            ->with(['routeLanes.lane.fromBranch', 'routeLanes.lane.toBranch', 'lane.fromBranch', 'lane.toBranch'])
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $exact = null;
        $partial = null;

        foreach ($candidates as $route) {
            $path = array_map('intval', $route->getPathBranchIds());
            if ($path === []) {
                continue;
            }
            // A stop with no branch behind it cannot receive a TR; using it
            // would make "next hop" a coverage id that is not a branch.
            if (!$this->pathIsOperable($path)) {
                continue;
            }
            $pathStart = (int) $path[0];
            $pathEnd = (int) end($path);

            if ($pathStart === (int) $fromCoverage && $pathEnd === (int) $toCoverage) {
                $exact = $route;
                break;
            }

            $idx = array_search((int) $fromCoverage, $path, true);
            if ($partial === null
                && $idx !== false
                && $idx < count($path) - 1
                && $pathEnd === (int) $toCoverage) {
                $partial = $route;
            }
        }

        return $exact ?? $partial;
    }

    /**
     * Shape suitable for shipment detail / API route_progress.
     */
    public function progressPayload(Shipment $shipment, ?int $atBranchId = null): array
    {
        $p = $this->resolveForShipment($shipment, $atBranchId);
        $hops = [];
        foreach ($p['path_coverage_ids'] as $i => $covId) {
            $branchId = $this->branchIdForCoverage((int) $covId);
            $isCurrent = $p['current_coverage_id'] !== null
                && (int) $p['current_coverage_id'] === (int) $covId;
            $isNext = $p['next_hop_coverage_id'] !== null
                && (int) $p['next_hop_coverage_id'] === (int) $covId;
            $hops[] = [
                'sequence' => $i,
                'coverage_id' => (int) $covId,
                'branch_id' => $branchId,
                'name' => $this->branchName($branchId) ?? $this->coverageName((int) $covId),
                'is_origin' => $i === 0,
                'is_destination' => $i === count($p['path_coverage_ids']) - 1,
                'is_current' => $isCurrent,
                'is_next_hop' => $isNext,
                'completed' => $p['transfer_leg_index'] !== null && $i < (int) $p['transfer_leg_index'],
            ];
        }

        return array_merge($p, ['hops' => $hops]);
    }


    /**
     * Locate current branch on a coverage/operational path.
     * Returns int index or false.
     *
     * @param  int[]  $pathCoverage
     * @param  int[]  $pathBranches
     */
    public function indexOnPath(array $pathCoverage, array $pathBranches, ?int $currentBranchId, ?int $currentCoverageId): int|false
    {
        if ($currentCoverageId !== null) {
            $idx = array_search((int) $currentCoverageId, array_map('intval', $pathCoverage), true);
            if ($idx !== false) {
                return (int) $idx;
            }
        }

        if ($currentBranchId !== null) {
            $idx = array_search((int) $currentBranchId, array_map('intval', $pathBranches), true);
            if ($idx !== false) {
                return (int) $idx;
            }
            // Path nodes may store coverage ids that map to the operational branch.
            foreach ($pathCoverage as $i => $cov) {
                $op = $this->branchIdForCoverage((int) $cov);
                if ($op !== null && (int) $op === (int) $currentBranchId) {
                    return (int) $i;
                }
            }
            $idx = array_search((int) $currentBranchId, array_map('intval', $pathCoverage), true);
            if ($idx !== false) {
                return (int) $idx;
            }
        }

        return false;
    }

    /**
     * True when candidate next hop equals the path final while an intermediate stop remains after current.
     *
     * @param  int[]  $pathBranchIds  operational branch ids along the route
     */
    public function nextHopSkipsPath(?int $candidateNextHopId, array $pathBranchIds, ?int $currentBranchId, ?int $finalBranchId): bool
    {
        if (!$candidateNextHopId || count($pathBranchIds) < 3) {
            return false;
        }
        $path = array_map('intval', $pathBranchIds);
        $final = (int) ($finalBranchId ?? end($path));
        if ((int) $candidateNextHopId !== $final && (int) $candidateNextHopId !== (int) end($path)) {
            return false;
        }
        $idx = $currentBranchId !== null ? array_search((int) $currentBranchId, $path, true) : false;
        if ($idx === false) {
            return true; // cannot place current but candidate is final on multi-hop → treat as skip
        }

        return $idx < count($path) - 2;
    }

    public function coverageIdForBranch(?int $branchId): ?int
    {
        if (!$branchId) {
            return null;
        }
        if (array_key_exists($branchId, $this->coverageByBranch)) {
            return $this->coverageByBranch[$branchId];
        }
        $cov = Branch::query()->whereKey($branchId)->value('coverage_location_id');
        $this->coverageByBranch[$branchId] = $cov !== null ? (int) $cov : null;

        return $this->coverageByBranch[$branchId];
    }

    /**
     * True when every stop on a route path maps to a branch (by coverage
     * location, or a path entry that is itself a branch id). Routes through a
     * coverage location with no branch are skipped by origin/destination
     * auto-matching; an explicitly assigned route is still honoured.
     *
     * @param  list<int>  $path
     */
    public function pathIsOperable(array $path): bool
    {
        foreach ($path as $stop) {
            $stop = (int) $stop;
            if ($stop <= 0) {
                return false;
            }
            if ($this->branchIdForCoverage($stop) !== null) {
                continue;
            }
            if (!array_key_exists($stop, $this->branchExists)) {
                $this->branchExists[$stop] = Branch::query()->whereKey($stop)->exists();
            }
            if (!$this->branchExists[$stop]) {
                return false;
            }
        }

        return true;
    }

    public function branchIdForCoverage(?int $coverageId): ?int
    {
        if (!$coverageId) {
            return null;
        }
        if (array_key_exists($coverageId, $this->branchByCoverage)) {
            return $this->branchByCoverage[$coverageId];
        }
        $query = Branch::query()->where('coverage_location_id', $coverageId);
        $franchise = (clone $query)->where('type', 'franchise_branch')->orderBy('id')->value('id');
        if ($franchise) {
            $this->branchByCoverage[$coverageId] = (int) $franchise;

            return $this->branchByCoverage[$coverageId];
        }
        $any = $query->orderBy('id')->value('id');
        $this->branchByCoverage[$coverageId] = $any !== null ? (int) $any : null;

        return $this->branchByCoverage[$coverageId];
    }

    public function resolveOperationalBranchId(?int $id): ?int
    {
        if (!$id) {
            return null;
        }
        if (Branch::query()->whereKey($id)->exists()) {
            return $id;
        }

        return $this->branchIdForCoverage($id);
    }

    /**
     * True when two branch/coverage ids refer to the same operational location
     * (exact id, resolveOperationalBranchId, or shared coverage_location_id).
     */
    public function sameOperationalLocation(?int $a, ?int $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }
        if ((int) $a === (int) $b) {
            return true;
        }

        $opA = $this->resolveOperationalBranchId((int) $a) ?? (int) $a;
        $opB = $this->resolveOperationalBranchId((int) $b) ?? (int) $b;
        if ((int) $opA === (int) $opB) {
            return true;
        }

        $covA = $this->coverageIdForBranch((int) $opA) ?? $this->coverageIdForBranch((int) $a);
        $covB = $this->coverageIdForBranch((int) $opB) ?? $this->coverageIdForBranch((int) $b);

        return $covA !== null && $covB !== null && (int) $covA === (int) $covB;
    }


    private function branchName(?int $branchId): ?string
    {
        if (!$branchId) {
            return null;
        }

        return Branch::query()->whereKey($branchId)->value('name');
    }

    private function coverageName(int $coverageId): ?string
    {
        try {
            return \Modules\Branch\Models\CoverageLocation::query()->whereKey($coverageId)->value('name')
                ; // Geo namespace optional
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function pathTextFromCoverage(array $pathCoverage): ?string
    {
        if ($pathCoverage === []) {
            return null;
        }
        $names = [];
        foreach ($pathCoverage as $covId) {
            $branchId = $this->branchIdForCoverage((int) $covId);
            $names[] = $this->branchName($branchId) ?? $this->coverageName((int) $covId) ?? ("#{$covId}");
        }

        return implode(' -> ', array_filter($names));
    }
}
