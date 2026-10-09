<?php

declare(strict_types=1);

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\CourierStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\TransferService;

/**
 * Transfer Management Controller
 *
 * Handles cross-branch transfers with support for multi-hop routing:
 * - 1-hop: Direct transfer (A → B)
 * - 2-hop: Transfer via hub (A → Hub → B)
 * - 3-hop: Transfer via multiple hubs (A → Hub1 → Hub2 → B)
 *
 * Tabs:
 *   Outbound = Transfers ready to leave this branch
 *   In Transit = Transfers currently moving to/through this branch
 *   Received = Transfers arrived at this branch, ready for last-mile
 *   Completed = Transfers successfully delivered
 *   History = Complete transfer timeline
 */
final class TransferController extends Controller
{
    /** Tracking statuses that record a parcel arriving at a branch. */
    private const RECEIPT_EVENT_STATUSES = [
        CourierStatus::RECEIVED_AT_TRANSIT_HUB,
        CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
        CourierStatus::RECEIVED_AT_DESTINATION_SUB_BRANCH,
    ];

    /** Statuses a cross-branch parcel can have after it reached its destination. */
    private const POST_RECEIPT_STATUSES = [
        CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
        CourierStatus::RECEIVED_AT_DESTINATION_SUB_BRANCH,
        CourierStatus::SORTED_FOR_DELIVERY,
        CourierStatus::ASSIGNED_TO_RIDER,
        CourierStatus::OUT_FOR_DELIVERY,
        CourierStatus::DELIVERED,
        CourierStatus::DELIVERY_FAILED,
    ];

    /** Statuses a cross-branch parcel can have after it left its origin. */
    private const POST_DISPATCH_STATUSES = [
        CourierStatus::IN_TRANSIT,
        CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
        CourierStatus::RECEIVED_AT_TRANSIT_HUB,
        CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
        CourierStatus::RECEIVED_AT_DESTINATION_SUB_BRANCH,
        CourierStatus::SORTED_FOR_DELIVERY,
        CourierStatus::ASSIGNED_TO_RIDER,
        CourierStatus::OUT_FOR_DELIVERY,
        CourierStatus::DELIVERED,
        CourierStatus::DELIVERY_FAILED,
    ];

    /** Tracking statuses shown in transfer history / timelines. */
    private const TRANSFER_EVENT_STATUSES = [
        CourierStatus::SORTED_FOR_TRANSFER,
        CourierStatus::IN_TRANSIT,
        CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
        CourierStatus::RECEIVED_AT_TRANSIT_HUB,
        CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
        CourierStatus::RECEIVED_AT_DESTINATION_SUB_BRANCH,
        CourierStatus::SORTED_FOR_DELIVERY,
        CourierStatus::ASSIGNED_TO_RIDER,
        CourierStatus::OUT_FOR_DELIVERY,
        CourierStatus::DELIVERED,
        CourierStatus::DELIVERY_FAILED,
        CourierStatus::RETURN_INITIATED,
    ];

    public function __construct(
        private readonly TransferService $service,
        private readonly \Modules\Shipment\Services\TransferRouteProgressService $progress,
        private readonly \Modules\Dispatch\Services\TransferContainerService $containers,
    ) {
    }

    /**
     * Main transfer board - list transfers by direction
     * 
     * OUTBOUND: sorted_for_transfer status at current_branch or origin_branch
     * INBOUND: in_transit or received_at_destination statuses for this branch
     */
    public function index(Request $request)
    {
        $user = $request->user();
        // Admins pick a branch with ?branch_id=; branch staff stay on their own branch.
        $branchId = $this->resolveReceivingBranchId($user, $request);
        $direction = $request->string('direction')->toString() ?: 'outbound';

        if (!in_array($direction, ['outbound', 'inbound', 'sent'], true)) {
            $direction = 'outbound';
        }

        $query = Shipment::query()->with([
            'merchant',
            'originBranch',
            'originSubBranch.parent',
            'destinationBranch',
            'destinationSubBranch.parent',
            'currentBranch',
            'currentSubBranch.parent',
        ]);

        if ($direction === 'sent') {
            // SENT (outbounded): everything this branch has dispatched, in any
            // later state (in transit, received, out for delivery, delivered).
            $this->applySentScope($query, $branchId);
            $this->applyCommonListFilters($query, $request);

            $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
            $results = $query->latest('id')->paginate($perPage);
            $this->attachTransferInfo($results->getCollection(), $branchId);

            return ApiResponse::success($results);
        }

        if ($direction === 'inbound') {
            // INBOUND: transfers coming TO this branch as NEXT hop or final destination.
            // in_transit parcels have current_branch_id=null, so also match open
            // dispatch manifests whose to_branch_id is this branch (hop-by-hop).
            $query->whereIn('status', [
                CourierStatus::IN_TRANSIT,
                CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
                CourierStatus::RECEIVED_AT_TRANSIT_HUB,
                CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
            ]);

            $query->whereColumn('origin_branch_id', '!=', 'destination_branch_id');

            if ($branchId !== 0) {
                $query->where(function ($q) use ($branchId) {
                    $q->where('destination_branch_id', $branchId)
                        ->orWhere('current_branch_id', $branchId)
                        ->orWhereExists(function ($sub) use ($branchId) {
                            $sub->selectRaw('1')
                                ->from('dispatch_manifest_items as dmi')
                                ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
                                ->whereColumn('dmi.shipment_id', 'shipments.id')
                                ->where('dm.to_branch_id', $branchId)
                                ->whereIn('dm.status', ['dispatched', 'in_transit'])
                                ->whereIn('dmi.status', ['sent', 'dispatched', 'in_transit']);
                        });
                });
            }
        } else {
            // OUTBOUND: cross-branch parcels ready to leave this branch.
            //
            // The correct "ready to transfer" status is SORTED_FOR_TRANSFER,
            // but legacy / mis-sorted cross-branch parcels can be stuck at
            // SORTED_FOR_DELIVERY (or still PICKED_UP / RECEIVED_AT_ORIGIN_BRANCH)
            // while their origin != destination. We surface all of those here so
            // the branch manager can dispatch them, and the dispatch action
            // repairs the status.
            $this->applyOutboundScope($query, $branchId);
        }

        // Search
        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('tracking_number', 'like', "%{$search}%")
                        ->orWhere('receiver_name', 'like', "%{$search}%")
                        ->orWhere('receiver_phone', 'like', "%{$search}%");
                });
            }
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        // If grouping by next hop is requested (hub bagging board)
        if ($direction === 'outbound' && $request->boolean('group_by_next_hop')) {
            return $this->getOutboundGroupedByNextHop($query, $branchId, $perPage);
        }

        // If grouping by route is requested (for outbound board)
        if ($direction === 'outbound' && $request->boolean('group_by_route')) {
            return $this->getOutboundGroupedByRoute($query, $branchId, $perPage);
        }

        return ApiResponse::success($query->latest('id')->paginate($perPage));
    }

    /**
     * Get outbound transfers grouped by route for dispatch planning.
     * Returns: { routes: [{ route_info, shipments: [...], count: N }], unmatched: [...] }
     */
    private function getOutboundGroupedByRoute($query, int $branchId, int $perPage): \Illuminate\Http\JsonResponse
    {
        $shipments = $query->with(['routeSteps'])->get();

        // Group by destination branch (and transit path if available)
        $groups = [];
        $unmatched = [];

        foreach ($shipments as $shipment) {
            $originNode = $shipment->origin_sub_branch_id ?? $shipment->origin_branch_id;
            $destNode = $shipment->destination_sub_branch_id ?? $shipment->destination_branch_id;

            if (!$originNode || !$destNode) {
                $unmatched[] = $shipment;
                continue;
            }

            // Try to find a configured route for this origin-destination pair
            $route = $this->findMatchingRoute($branchId, $destNode, $shipment->service_type ?? 'standard');

            if ($route) {
                $routeKey = $route['id'];
                if (!isset($groups[$routeKey])) {
                    $groups[$routeKey] = [
                        'route' => $route,
                        'shipments' => [],
                        'count' => 0,
                    ];
                }
                $groups[$routeKey]['shipments'][] = $shipment;
                $groups[$routeKey]['count']++;
            } else {
                // No configured route - group by destination branch as fallback
                $fallbackKey = "fallback_{$destNode}";
                if (!isset($groups[$fallbackKey])) {
                    $destBranch = \Modules\Branch\Models\Branch::find($destNode);
                    $groups[$fallbackKey] = [
                        'route' => [
                            'id' => null,
                            'route_code' => 'AUTO',
                            'route_name' => 'Auto: ' . ($destBranch?->name ?? "Branch #{$destNode}"),
                            'service_type' => $shipment->service_type ?? 'standard',
                            'origin_branch_id' => $branchId,
                            'destination_branch_id' => $destNode,
                            'transit_count' => 0,
                            'transfer_count' => 1,
                            'path_text' => ($shipment->originBranch?->name ?? 'Origin') . ' → ' . ($destBranch?->name ?? 'Destination'),
                            'is_configured' => false,
                        ],
                        'shipments' => [],
                        'count' => 0,
                    ];
                }
                $groups[$fallbackKey]['shipments'][] = $shipment;
                $groups[$fallbackKey]['count']++;
            }
        }

        // Paginate each group's shipments
        $paginatedGroups = [];
        foreach ($groups as $group) {
            $paginatedGroups[] = [
                'route' => $group['route'],
                'count' => $group['count'],
                'shipments' => array_slice($group['shipments'], 0, $perPage), // First page
                'has_more' => count($group['shipments']) > $perPage,
            ];
        }

        return ApiResponse::success([
            'routes' => array_values($paginatedGroups),
            'unmatched' => array_slice($unmatched, 0, $perPage),
            'total_shipments' => $shipments->count(),
        ]);
    }

    /**
     * Outbound transfers grouped by NEXT HOP (hub bagging).
     * KTM→Bharatpur includes finals Bharatpur AND via-to-Birendranagar.
     */
    private function getOutboundGroupedByNextHop($query, int $branchId, int $perPage): \Illuminate\Http\JsonResponse
    {
        $shipments = $query->with(['originBranch', 'destinationBranch', 'currentBranch'])->get();
        $groups = [];
        $unmatched = [];

        foreach ($shipments as $shipment) {
            $atBranch = $branchId !== 0 ? $branchId : null;
            $progress = $this->progress->resolveForShipment($shipment, $atBranch);

            // Route may have been added/matched after sort — persist corrected next hop + hop_meta.
            $persistedHop = (int) ($shipment->next_hop_branch_id ?? 0);
            $computedHop = (int) ($progress['next_hop_branch_id'] ?? 0);
            $persistedRoute = (int) ($shipment->transfer_route_id ?? 0);
            $computedRoute = (int) ($progress['transfer_route_id'] ?? 0);
            if (
                !empty($progress['has_route'])
                && $computedHop > 0
                && ($persistedHop !== $computedHop || ($computedRoute > 0 && $persistedRoute !== $computedRoute))
            ) {
                try {
                    $shipment = $this->progress->applyProgressToShipment($shipment, $atBranch);
                    $progress = $this->progress->resolveForShipment($shipment, $atBranch);
                    $computedHop = (int) ($progress['next_hop_branch_id'] ?? 0);
                } catch (\Throwable $e) {
                    // Still serve live progress below even if persist fails.
                }
            }

            if ($progress['ready_for_last_mile'] || empty($progress['next_hop_branch_id'])) {
                $unmatched[] = $shipment;
                continue;
            }

            $hopId = (int) $progress['next_hop_branch_id'];
            $service = (string) ($progress['service_type'] ?? $shipment->service_type ?? 'standard');
            // ONE TR per next hop: services ride together (listed per parcel).
            $key = (string) $hopId;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'next_hop_branch_id' => $hopId,
                    'next_hop_name' => $progress['next_hop_name'] ?? ("Branch #{$hopId}"),
                    'next_hop_coverage_id' => $progress['next_hop_coverage_id'] ?? null,
                    'service_type' => $service,
                    'services' => [],
                    'shipments' => [],
                    'count' => 0,
                    'last_mile_count' => 0,
                    'onward_count' => 0,
                    'finals' => [],
                    'route_codes' => [],
                ];
            }
            $groups[$key]['services'][$service] = ($groups[$key]['services'][$service] ?? 0) + 1;

            // Force hop_meta / JSON to reflect live path next hop (not stale destination).
            $shipment->setAttribute('next_hop_branch_id', $progress['next_hop_branch_id']);
            if (!empty($progress['transfer_route_id'])) {
                $shipment->setAttribute('transfer_route_id', $progress['transfer_route_id']);
            }
            if (!empty($progress['path_text'])) {
                $shipment->setAttribute('path_text', $progress['path_text']);
            }
            if (!empty($progress['route_code'])) {
                $shipment->setAttribute('route_code', $progress['route_code']);
            }
            if (!empty($progress['route_name'])) {
                $shipment->setAttribute('route_name', $progress['route_name']);
            }
            if (array_key_exists('transfer_leg_index', $progress)) {
                $shipment->setAttribute('transfer_leg_index', $progress['transfer_leg_index']);
            }
            $shipment->setAttribute('_hop_progress', $progress);
            $groups[$key]['shipments'][] = $shipment;
            $groups[$key]['count']++;

            $finalId = (int) ($shipment->destination_branch_id ?? 0);
            if ($finalId === $hopId || $this->progress->sameOperationalLocation($finalId, $hopId)) {
                $groups[$key]['last_mile_count']++;
            } else {
                $groups[$key]['onward_count']++;
            }
            $finalName = $shipment->destinationBranch?->name
                ?? $progress['destination_branch_id']
                ?? ("Branch #{$finalId}");
            $finalKey = (string) $finalId;
            if (!isset($groups[$key]['finals'][$finalKey])) {
                $groups[$key]['finals'][$finalKey] = [
                    'destination_branch_id' => $finalId,
                    'destination_name' => $finalName,
                    'count' => 0,
                ];
            }
            $groups[$key]['finals'][$finalKey]['count']++;

            if (!empty($progress['route_code'])) {
                $groups[$key]['route_codes'][$progress['route_code']] = true;
            }
        }

        // Open (loading) TR per next hop for this branch, if any.
        $openTr = collect();
        if ($branchId !== 0 && $groups !== []) {
            $openTr = \Modules\Dispatch\Models\DispatchManifest::query()
                ->where('from_branch_id', $branchId)
                ->whereIn('to_branch_id', array_map(fn ($g) => $g['next_hop_branch_id'], $groups))
                ->where('status', 'open')
                ->withCount(['items as loaded_count' => fn ($q) => $q->where('status', 'added')])
                ->get()
                ->keyBy('to_branch_id');
        }

        $paginated = [];
        foreach ($groups as $group) {
            $services = $group['services'];
            $open = $openTr->get($group['next_hop_branch_id']);
            $paginated[] = [
                'next_hop_branch_id' => $group['next_hop_branch_id'],
                'next_hop_name' => $group['next_hop_name'],
                'next_hop_coverage_id' => $group['next_hop_coverage_id'],
                'service_type' => count($services) === 1 ? (string) array_key_first($services) : 'mixed',
                'services' => $services,
                'last_mile_count' => $group['last_mile_count'],
                'onward_count' => $group['onward_count'],
                'open_container' => $open ? [
                    'id' => $open->id,
                    'transfer_number' => $open->display_number,
                    'loaded_count' => (int) $open->loaded_count,
                ] : null,
                'count' => $group['count'],
                'finals' => array_values($group['finals']),
                'route_codes' => array_keys($group['route_codes']),
                'shipments' => array_slice($group['shipments'], 0, $perPage),
                'has_more' => count($group['shipments']) > $perPage,
            ];
        }

        usort($paginated, static function ($a, $b) {
            return ($b['count'] <=> $a['count'])
                ?: strcmp((string) $a['next_hop_name'], (string) $b['next_hop_name']);
        });

        return ApiResponse::success([
            'next_hops' => array_values($paginated),
            'unmatched' => array_slice($unmatched, 0, $perPage),
            'total_shipments' => $shipments->count(),
        ]);
    }
    /**
     * Find a configured transfer route matching origin->destination.
     */
    private function findMatchingRoute(int $originBranchId, int $destinationBranchId, string $serviceType): ?array
    {
        $fromCoverage = $this->coverageIdForBranch($originBranchId) ?? $originBranchId;
        $toCoverage = $this->coverageIdForBranch($destinationBranchId) ?? $destinationBranchId;

        $routes = \Modules\Rate\Models\BranchTransferRoute::query()
            ->when(
                $serviceType !== '' && strtolower($serviceType) !== 'all',
                fn ($q) => $q->where('service_type', $serviceType)
            )
            ->where('is_active', true)
            ->with(['routeLanes.lane.fromBranch', 'routeLanes.lane.toBranch'])
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $exact = null;
        $partial = null;

        foreach ($routes as $route) {
            $path = array_map('intval', $route->getPathBranchIds());
            if ($path === []) {
                continue;
            }
            // Skip routes through a stop with no branch (cannot receive a TR).
            if (!$this->progress->pathIsOperable($path)) {
                continue;
            }

            $pathStart = (int) $path[0];
            $pathEnd = (int) end($path);

            if ($pathStart === $fromCoverage && $pathEnd === $toCoverage) {
                $exact = $this->formatRouteForDispatch($route, $fromCoverage, $originBranchId);
                break;
            }

            $idx = array_search($fromCoverage, $path, true);
            if ($partial === null
                && $idx !== false
                && $idx < count($path) - 1
                && $pathEnd === $toCoverage) {
                $partial = $this->formatRouteForDispatch($route, $fromCoverage, $originBranchId);
            }
        }

        return $exact ?? $partial;
    }

    /**
     * Transfer statistics
     * 
     * Shows counts for this branch:
     * - outbound: ready to leave this branch (sorted_for_transfer)
     * - in_transit: transfers in transit TO this branch (in_transit)
     * - received: transfers received and ready for delivery (received_at_destination_branch)
     * - completed: transfers successfully delivered (delivered)
     */
    public function stats(Request $request)
    {
        $user = $request->user();
        // Admins pick a branch with ?branch_id=; branch staff stay on their own branch.
        $branchId = $this->resolveReceivingBranchId($user, $request);

        // Outbound: cross-branch parcels ready to send from this branch
        // (uses the same scope as the Outbound list so counts always match).
        $outboundQuery = Shipment::query();
        $this->applyOutboundScope($outboundQuery, $branchId);
        $outbound = $outboundQuery->count();

        // In Transit: cross-branch transfers in transit to this branch
        $inTransit = Shipment::query()
            ->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
            ->whereIn('status', [
                CourierStatus::IN_TRANSIT,
                CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
                CourierStatus::RECEIVED_AT_TRANSIT_HUB,
            ]);
        
        if ($branchId !== 0) {
            $inTransit->where(function ($q) use ($branchId) {
                $q->where('destination_branch_id', $branchId)
                    ->orWhere('current_branch_id', $branchId)
                    ->orWhereExists(function ($sub) use ($branchId) {
                        $sub->selectRaw('1')
                            ->from('dispatch_manifest_items as dmi')
                            ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
                            ->whereColumn('dmi.shipment_id', 'shipments.id')
                            ->where('dm.to_branch_id', $branchId)
                            ->whereIn('dm.status', ['dispatched', 'in_transit'])
                            ->whereIn('dmi.status', ['sent', 'dispatched', 'in_transit']);
                    });
            });
        }
        $inTransit = $inTransit->count();

        // Received: every transfer that arrived at this branch, including ones
        // already assigned to a rider, out for delivery or delivered.
        $receivedQuery = Shipment::query();
        $this->applyReceivedScope($receivedQuery, $branchId);
        $received = $receivedQuery->count();

        // Sent: everything this branch has dispatched (any later state).
        $sentQuery = Shipment::query();
        $this->applySentScope($sentQuery, $branchId);
        $sent = $sentQuery->count();

        // Completed: cross-branch parcels delivered at destination.
        $completed = Shipment::query()
            ->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
            ->where('status', CourierStatus::DELIVERED);
        
        if ($branchId !== 0) {
            $completed->where('destination_branch_id', $branchId);
        }
        $completed = $completed->count();

        return ApiResponse::success([
            'outbound' => $outbound,
            'sent' => $sent,
            'in_transit' => $inTransit,
            'received' => $received,
            'completed' => $completed,
            'branch_id' => $branchId ?: null,
        ]);
    }

    /**
     * Summary for transfer tabs
     */
    public function summary(Request $request)
    {
        $user = $request->user();
        // Admins pick a branch with ?branch_id=; branch staff stay on their own branch.
        $branchId = $this->resolveReceivingBranchId($user, $request);

        $outboundQuery = Shipment::query();
        $this->applyOutboundScope($outboundQuery, $branchId);
        $outbound = $outboundQuery->count();

        $inbound = Shipment::query()
            ->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
            ->whereIn('status', [
                CourierStatus::IN_TRANSIT,
                CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
                CourierStatus::RECEIVED_AT_TRANSIT_HUB,
            ]);
        
        if ($branchId !== 0) {
            $inbound->where(function ($q) use ($branchId) {
                $q->where('destination_branch_id', $branchId)
                    ->orWhere('current_branch_id', $branchId)
                    ->orWhereExists(function ($sub) use ($branchId) {
                        $sub->selectRaw('1')
                            ->from('dispatch_manifest_items as dmi')
                            ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
                            ->whereColumn('dmi.shipment_id', 'shipments.id')
                            ->where('dm.to_branch_id', $branchId)
                            ->whereIn('dm.status', ['dispatched', 'in_transit'])
                            ->whereIn('dmi.status', ['sent', 'dispatched', 'in_transit']);
                    });
            });
        }
        $inbound = $inbound->count();

        return ApiResponse::success([
            'outbound' => $outbound,
            'inbound' => $inbound,
        ]);
    }

    /**
     * Get received transfers at this branch
     * Note: Only shows CROSS-BRANCH transfers (origin != destination)
     */
    public function received(Request $request)
    {
        $user = $request->user();
        // Admins pick a branch with ?branch_id=; branch staff stay on their own branch.
        $branchId = $this->resolveReceivingBranchId($user, $request);

        $query = Shipment::query()->with([
            'merchant',
            'originBranch',
            'destinationBranch',
            'currentBranch',
        ]);

        // Cross-branch transfers that ARRIVED at this branch (final destination
        // or transit hub), in any later state: received, sorted, assigned to a
        // rider, out for delivery, delivered, delivery failed.
        $this->applyReceivedScope($query, $branchId);
        $this->applyCommonListFilters($query, $request);

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $results = $query->latest('id')->paginate($perPage);
        $this->attachTransferInfo($results->getCollection(), $branchId);

        return ApiResponse::success($results);
    }

    /**
     * Get completed transfers delivered to this branch
     * Note: Only shows CROSS-BRANCH transfers (origin != destination), not same-branch local deliveries
     */
    public function completed(Request $request)
    {
        $user = $request->user();
        // Admins pick a branch with ?branch_id=; branch staff stay on their own branch.
        $branchId = $this->resolveReceivingBranchId($user, $request);

        $query = Shipment::query()->with([
            'merchant',
            'originBranch',
            'destinationBranch',
        ]);

        // ONLY cross-branch transfers (delivered to this branch from another branch)
        $query->where('status', CourierStatus::DELIVERED)
            ->whereColumn('origin_branch_id', '!=', 'destination_branch_id'); // Cross-branch only!

        if ($branchId !== 0) {
            $query->where('destination_branch_id', $branchId);
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('tracking_number', 'like', "%{$search}%")
                        ->orWhere('receiver_name', 'like', "%{$search}%")
                        ->orWhere('receiver_phone', 'like', "%{$search}%");
                });
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('delivered_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('delivered_at', '<=', $request->date('date_to'));
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $results = $query->latest('delivered_at')->paginate($perPage);

        // Add transfer metadata
        $results->getCollection()->transform(function ($shipment) {
            $shipment->transfer_details = [
                'origin' => $shipment->originBranch?->name ?? 'Unknown',
                'destination' => $shipment->destinationBranch?->name ?? 'Unknown',
                'dispatched_at' => $shipment->dispatched_at,
                'received_at' => $shipment->received_at_destination_at,
                'delivered_at' => $shipment->delivered_at,
            ];
            return $shipment;
        });

        return ApiResponse::success($results);
    }

    /**
     * Get complete transfer history with all statuses
     */
    public function history(Request $request)
    {
        $user = $request->user();
        // Admins pick a branch with ?branch_id=; branch staff stay on their own branch.
        $branchId = $this->resolveReceivingBranchId($user, $request);
        $direction = $request->string('direction')->toString();

        $query = Shipment::query()->with([
            'merchant',
            'originBranch',
            'destinationBranch',
            'currentBranch',
        ]);

        // Transfers this branch sent, received, or still has to send. No status
        // whitelist: a parcel stays in history after it is assigned to a rider,
        // out for delivery, delivered or failed.
        $query->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
            ->where(function ($q) use ($branchId, $direction) {
                if ($direction !== 'received') {
                    $q->orWhere(fn ($sent) => $this->applySentScope($sent, $branchId));
                }
                if ($direction !== 'sent') {
                    $q->orWhere(fn ($received) => $this->applyReceivedScope($received, $branchId));
                }
                if (! in_array($direction, ['sent', 'received'], true)) {
                    $q->orWhere(fn ($pending) => $this->applyOutboundScope($pending, $branchId));
                }
            });

        $this->applyCommonListFilters($query, $request);

        $from = $request->filled('date_from') ? $request->date('date_from')?->startOfDay() : null;
        $to = $request->filled('date_to') ? $request->date('date_to')?->endOfDay() : null;
        if ($from || $to) {
            // A transfer event (or the dispatch) happened in the date range.
            $query->where(function ($q) use ($from, $to) {
                $q->whereExists(function ($sub) use ($from, $to) {
                    $sub->selectRaw('1')
                        ->from('tracking_events as te')
                        ->whereColumn('te.shipment_id', 'shipments.id')
                        ->whereIn('te.status', self::TRANSFER_EVENT_STATUSES);
                    if ($from) {
                        $sub->where('te.created_at', '>=', $from);
                    }
                    if ($to) {
                        $sub->where('te.created_at', '<=', $to);
                    }
                })->orWhere(function ($d) use ($from, $to) {
                    $d->whereNotNull('dispatched_at');
                    if ($from) {
                        $d->where('dispatched_at', '>=', $from);
                    }
                    if ($to) {
                        $d->where('dispatched_at', '<=', $to);
                    }
                });
            });
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $results = $query->latest('updated_at')->paginate($perPage);
        $this->attachTransferInfo($results->getCollection(), $branchId);

        $results->getCollection()->transform(function ($shipment) {
            $shipment->transfer_route = [
                'origin' => $shipment->originBranch?->name ?? 'Unknown',
                'destination' => $shipment->destinationBranch?->name ?? 'Unknown',
            ];

            return $shipment;
        });

        return ApiResponse::success($results);
    }

    /**
     * Get available transfer routes from the current branch.
     * Returns all configured routes originating from this branch, grouped by destination.
     * Used by the frontend to show route options for dispatch.
     * 
     * For admin users (branch_id = 0), they can specify a branch_id via query parameter.
     * If not specified, returns all routes grouped by origin branch.
     */
    public function availableRoutes(Request $request)
    {
        $user = $request->user();
        $branchId = $this->getBranchScope($user);
        $serviceType = strtolower(trim($request->string('service_type')->toString() ?: 'all'));

        // For admin users, allow specifying branch_id via query parameter
        if ($branchId === 0) {
            $branchId = $request->integer('branch_id');
        }

        // Get all active routes (optionally filtered by service type)
        $routesQuery = \Modules\Rate\Models\BranchTransferRoute::query()
            ->when(
                $serviceType !== '' && $serviceType !== 'all',
                fn ($q) => $q->where('service_type', $serviceType)
            )
            ->where('is_active', true)
            ->with(['routeLanes.lane.fromBranch', 'routeLanes.lane.toBranch'])
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->orderBy('id');

        // If branch_id is specified, filter routes whose path includes this branch's
        // coverage location (routes use coverage_locations.id; shipments use branches.id).
        if ($branchId !== 0) {
            $coverageId = $this->coverageIdForBranch($branchId) ?? $branchId;
            $routes = $routesQuery->get();

            $formattedRoutes = [];
            $routesByDestination = [];

            foreach ($routes as $route) {
                $path = $route->getPathBranchIds();
                if (empty($path)) {
                    continue;
                }

                $path = array_map('intval', $path);
                $idx = array_search($coverageId, $path, true);
                // Current coverage must be on the path and not already the final stop.
                if ($idx === false || $idx >= count($path) - 1) {
                    continue;
                }

                $formatted = $this->formatRouteForDispatch($route, $coverageId, $branchId);
                $formattedRoutes[] = $formatted;

                $destCoverageId = (int) end($path);
                $destOperationalId = $formatted['destination_branch_id'] ?? $this->branchIdForCoverage($destCoverageId);
                $destKey = (int) ($destOperationalId ?? $destCoverageId);
                if (!isset($routesByDestination[$destKey])) {
                    $routesByDestination[$destKey] = [
                        'destination_branch_id' => $destKey,
                        'destination_coverage_id' => $destCoverageId,
                        'destination_branch_name' => $formatted['destination_branch_name']
                            ?? (\Modules\Branch\Models\Branch::find($destKey)?->name)
                            ?? 'Unknown',
                        'destination_branch_code' => $formatted['destination_branch_code'] ?? '',
                        'routes' => [],
                    ];
                }
                $routesByDestination[$destKey]['routes'][] = $formatted;
            }

            return ApiResponse::success([
                'routes' => array_values($formattedRoutes),
                'routes_by_destination' => array_values($routesByDestination),
                'branch_id' => $branchId,
                'coverage_id' => $coverageId,
                'service_type' => $serviceType,
                'routes_count' => count($formattedRoutes),
                'configure_routes_path' => '/admin/branch-transfer-routes',
            ]);
        }

        // For admin without branch_id: return FLAT route cards (same shape as
        // branch-scoped) plus optional routes_by_origin grouping. Nested groups
        // in `routes` previously broke FE shipmentMatchesRoute / unmatched.
        $routes = $routesQuery->get();

        $formattedRoutes = [];
        $routesByOrigin = [];

        foreach ($routes as $route) {
            $path = $route->getPathBranchIds();
            if (empty($path)) {
                continue;
            }

            $path = array_map('intval', $path);
            $originCoverageId = (int) $path[0];
            $operationalOrigin = $this->branchIdForCoverage($originCoverageId) ?? $originCoverageId;
            $formatted = $this->formatRouteForDispatch($route, $originCoverageId, $operationalOrigin);
            $formattedRoutes[] = $formatted;

            $originKey = (int) $operationalOrigin;
            if (!isset($routesByOrigin[$originKey])) {
                $originBranch = \Modules\Branch\Models\Branch::find($operationalOrigin);
                $routesByOrigin[$originKey] = [
                    'origin_branch_id' => $originKey,
                    'origin_coverage_id' => $originCoverageId,
                    'origin_branch_name' => $originBranch?->name
                        ?? ($formatted['origin_branch_name'] ?? 'Unknown'),
                    'origin_branch_code' => $originBranch?->code ?? '',
                    'routes' => [],
                ];
            }
            $routesByOrigin[$originKey]['routes'][] = $formatted;
        }

        return ApiResponse::success([
            'routes' => array_values($formattedRoutes),
            'routes_by_origin' => array_values($routesByOrigin),
            'branch_id' => null,
            'service_type' => $serviceType,
            'routes_count' => count($formattedRoutes),
        ]);
    }

    /**
     * Format a route for the dispatch UI.
     */
    private function formatRouteForDispatch(\Modules\Rate\Models\BranchTransferRoute $route, ?int $fromCoverageId = null, ?int $operationalFromBranchId = null): array
    {
        $route->loadMissing('routeLanes.lane.fromBranch', 'routeLanes.lane.toBranch');
        $lanes = $route->orderedLanes();

        $path = [];
        $transitBranches = [];

        if ($lanes->isNotEmpty()) {
            $first = $lanes->first();
            $path[] = [
                'branch_id' => (int) $first->from_branch_id,
                'branch_name' => $first->fromBranch?->name ?? 'Unknown',
                'branch_code' => $first->fromBranch?->code ?? '',
                'is_origin' => true,
                'is_transit' => false,
                'is_destination' => false,
            ];

            foreach ($lanes as $index => $lane) {
                $isLast = $index === $lanes->count() - 1;
                $path[] = [
                    'branch_id' => (int) $lane->to_branch_id,
                    'branch_name' => $lane->toBranch?->name ?? 'Unknown',
                    'branch_code' => $lane->toBranch?->code ?? '',
                    'is_origin' => false,
                    'is_transit' => !$isLast,
                    'is_destination' => $isLast,
                    'lane_id' => (int) $lane->id,
                    'lane_name' => $lane->variant_name ?? 'Direct',
                    'distance_km' => (float) $lane->distance_km,
                    'estimated_hours' => (float) $lane->estimated_hours,
                    'transport_mode' => $lane->transport_mode ?? 'road',
                ];

                if (!$isLast) {
                    $transitBranches[] = [
                        'branch_id' => (int) $lane->to_branch_id,
                        'branch_name' => $lane->toBranch?->name ?? 'Unknown',
                        'branch_code' => $lane->toBranch?->code ?? '',
                        'sequence' => $index + 1,
                    ];
                }
            }
        }

        $originBranchId = (int) ($lanes->first()?->from_branch_id ?? 0);
        $destinationBranchId = (int) ($lanes->last()?->to_branch_id ?? 0);

        $pathIds = array_map(static fn ($n) => (int) $n['branch_id'], $path);
        $nextHopCoverageId = null;
        $remainingTransitIds = array_column($transitBranches, 'branch_id');
        $legIndex = 0;

        $operationalOrigin = $operationalFromBranchId
            ?? $this->branchIdForCoverage($fromCoverageId ?? $originBranchId)
            ?? $originBranchId;

        $idx = false;
        if ($fromCoverageId !== null && $pathIds !== []) {
            $idx = array_search((int) $fromCoverageId, $pathIds, true);
        }
        // Also match operational branch against coverage→branch mapped path nodes.
        if ($idx === false && $operationalOrigin && $pathIds !== []) {
            foreach ($pathIds as $i => $covId) {
                $op = $this->branchIdForCoverage((int) $covId);
                if ($op !== null && (int) $op === (int) $operationalOrigin) {
                    $idx = $i;
                    break;
                }
                if ((int) $covId === (int) $operationalOrigin) {
                    $idx = $i;
                    break;
                }
            }
        }
        if ($idx !== false && isset($pathIds[$idx + 1])) {
            // Immediate next stop only — never default to path final while transit remains.
            $nextHopCoverageId = (int) $pathIds[$idx + 1];
            $remainingTransitIds = array_values(array_slice($pathIds, $idx + 2, -1));
            $legIndex = (int) $idx;
        } elseif ($pathIds !== [] && isset($pathIds[1])) {
            // At unknown position but path exists: prefer first hop after origin (safe for hub origin),
            // never jump straight to final on 3+ stop routes.
            $originOp = $this->branchIdForCoverage((int) $pathIds[0]) ?? (int) $pathIds[0];
            if ($operationalOrigin && (int) $operationalOrigin === (int) $originOp) {
                $nextHopCoverageId = (int) $pathIds[1];
                $remainingTransitIds = array_values(array_slice($pathIds, 2, -1));
                $legIndex = 0;
            } elseif (count($pathIds) === 2) {
                $nextHopCoverageId = (int) $pathIds[1];
                $remainingTransitIds = [];
                $legIndex = 0;
            }
        }
        if ($nextHopCoverageId === null) {
            // Last resort for malformed paths: still avoid skipping when 3+ nodes.
            $nextHopCoverageId = count($pathIds) >= 2 ? (int) $pathIds[1] : (int) $destinationBranchId;
            $remainingTransitIds = count($pathIds) > 2
                ? array_values(array_slice($pathIds, 2, -1))
                : [];
        }

        $operationalDestination = $this->branchIdForCoverage($destinationBranchId) ?? $destinationBranchId;
        $operationalNextHop = $this->branchIdForCoverage($nextHopCoverageId) ?? $nextHopCoverageId;
        $operationalTransitIds = array_values(array_filter(array_map(
            fn (int $coverageId): ?int => $this->branchIdForCoverage($coverageId),
            $remainingTransitIds,
        )));

        $nextHopName = null;
        foreach ($path as $node) {
            if ((int) $node['branch_id'] === (int) $nextHopCoverageId) {
                $nextHopName = $node['branch_name'];
                break;
            }
        }
        if (!$nextHopName) {
            $nextHopName = \Modules\Branch\Models\Branch::query()->whereKey($operationalNextHop)->value('name')
                ?: ("Branch #{$operationalNextHop}");
        }

        $destName = \Modules\Branch\Models\Branch::query()->whereKey($operationalDestination)->value('name')
            ?: (end($path)['branch_name'] ?? "Branch #{$operationalDestination}");
        $originName = \Modules\Branch\Models\Branch::query()->whereKey($operationalOrigin)->value('name')
            ?: ($path[0]['branch_name'] ?? "Branch #{$operationalOrigin}");

        return [
            'id' => (int) $route->id,
            'route_id' => (int) $route->id,
            'route_code' => (string) $route->route_code,
            'route_name' => (string) $route->name,
            'service_type' => (string) $route->service_type,
            // Operational branch IDs (match shipments.branches.id) — preferred for UI/dispatch.
            'origin_branch_id' => (int) $operationalOrigin,
            'destination_branch_id' => (int) $operationalDestination,
            'next_hop_branch_id' => (int) $operationalNextHop,
            'from_branch_id' => (int) $operationalOrigin,
            // Coverage IDs (route config / lane path).
            'origin_coverage_id' => (int) ($fromCoverageId ?? $originBranchId),
            'destination_coverage_id' => (int) $destinationBranchId,
            'next_hop_coverage_id' => (int) $nextHopCoverageId,
            'origin_branch_name' => $originName,
            'destination_branch_name' => $destName,
            'destination_branch_code' => '',
            'next_hop_name' => $nextHopName,
            // Route paths use coverage_locations.id; manifests use branches.id.
            'transit_branch_ids' => $operationalTransitIds,
            'transit_coverage_ids' => $remainingTransitIds,
            'transit_branches' => $transitBranches,
            'transit_count' => count($remainingTransitIds),
            'transfer_count' => max(1, $lanes->count()),
            'transfer_leg_index' => $legIndex,
            'total_distance_km' => (float) $route->getTotalDistanceKm(),
            'total_estimated_hours' => (int) round($route->getTotalEstimatedHours()),
            'base_rate' => (float) ($route->base_rate ?? 0),
            'currency' => (string) ($route->currency ?? 'NPR'),
            'path' => $path,
            'path_text' => implode(' → ', array_column($path, 'branch_name')),
            'is_default' => (bool) $route->is_default,
            'priority' => (int) $route->priority,
            'is_configured' => true,
        ];
    }

    /**
     * Dispatch transfers (bulk or single) - creates DispatchManifest per route
     */
    public function dispatch(Request $request)
    {
        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer'],
            'transfer_route_id' => ['nullable', 'integer', 'exists:branch_transfer_routes,id'],
            'next_hop_branch_id' => ['nullable', 'integer'],
            'vehicle_type' => ['nullable', 'string', 'in:'.implode(',', \Modules\Dispatch\Models\DispatchManifest::VEHICLE_TYPES)],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'rider_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'driver_name' => ['nullable', 'string', 'max:100'],
            'driver_phone' => ['nullable', 'string', 'max:20'],
            'seal_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // Trip cost entered by the dispatching branch, split over the parcels.
            'transport_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'transport_cost_split_mode' => ['nullable', 'in:equal,weight'],
            // Load parcels on an open TR without sending it yet.
            'hold' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $branchId = $this->resolveReceivingBranchId($user, $request);
        $shipmentIds = array_values(array_unique(array_map(fn($id) => (int) $id, $data['shipment_ids'])));

        // Verify shipments are cross-branch parcels ready for transfer
        $availableQuery = Shipment::query()->whereIn('id', $shipmentIds);
        $this->applyOutboundScope($availableQuery, $branchId);
        $available = $availableQuery->pluck('id')->all();

        $skipped = array_diff($shipmentIds, $available);

        if (empty($available)) {
            return ApiResponse::error('No valid shipments to dispatch.', 422);
        }

        $shipments = Shipment::query()->whereIn('id', $available)->orderBy('id')->get();

        // One TR container per (this branch, next hop). Parcels with different
        // final destinations share the TR when their next hop is the same.
        $forcedHop = !empty($data['next_hop_branch_id'])
            ? (int) ($this->resolveOperationalBranchId((int) $data['next_hop_branch_id']) ?? (int) $data['next_hop_branch_id'])
            : null;

        try {
            $out = $this->containers->dispatch($shipments, $forcedHop, $branchId, $data, (int) $user->id);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            return ApiResponse::error('Dispatch failed: '.$e->getMessage(), 422);
        }

        $result = ['dispatched' => [], 'skipped' => $out['skipped']];
        foreach ($skipped as $id) {
            $result['skipped'][(int) $id] = 'Shipment not ready for dispatch (must be sorted first)';
        }

        $manifests = $out['manifests'];
        foreach ($manifests as $m) {
            foreach ($m->items as $item) {
                if (in_array($item->status, ['sent', 'added'], true)) {
                    $result['dispatched'][] = (int) $item->shipment_id;
                }
            }
        }
        $result['dispatched'] = array_values(array_unique($result['dispatched']));
        $result['dispatched_count'] = count($result['dispatched']);
        $result['manifests'] = $manifests;
        $result['containers'] = array_map(fn ($m) => $this->containers->summarize($m), $manifests);
        $result['transfer_numbers'] = array_map(fn ($m) => $m->display_number, $manifests);
        $result['transfer_number'] = $result['transfer_numbers'][0] ?? null;

        if ($manifests === []) {
            $first = collect($result['skipped'])->first();
            return ApiResponse::error($first ?: 'No shipments could be dispatched.', 422, ['skipped' => $result['skipped']]);
        }

        $ok = count($result['dispatched']);
        $fail = count($result['skipped']);
        $verb = !empty($data['hold']) ? 'loaded on' : 'dispatched on';
        $msg = "{$ok} parcel(s) {$verb} ".implode(', ', $result['transfer_numbers']).'.';

        return ApiResponse::success($result, $fail === 0 ? $msg : $msg." {$fail} skipped.");
    }

    /**
     * Receive one transfer parcel at this branch (single-parcel path). The TR
     * check-in (containerReceive) is the normal path; this stays for scans of
     * a lone parcel and older clients. Sorting matches the TR receive.
     */
    public function receive(Request $request, Shipment $shipment)
    {
        $user = $request->user();
        $branchId = $this->resolveReceivingBranchId($user, $request);

        if ($branchId <= 0) {
            return ApiResponse::error('Branch context is required to receive a transfer.', 422);
        }

        if (!in_array($shipment->status, [
            CourierStatus::IN_TRANSIT,
            CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
        ], true)) {
            return ApiResponse::error('Only an in-transit transfer can be received.', 403);
        }

        $isFinalDestination = $this->containers->isFinalHere($shipment, $branchId);
        $isNextHop = $this->isExpectedNextHop($shipment, $branchId);

        if (!$isFinalDestination && !$isNextHop) {
            return ApiResponse::error(
                'This shipment is not expected at your branch (not next hop or final destination).',
                403
            );
        }

        try {
            $res = DB::transaction(fn () => $this->containers->receiveAndSort($shipment, $branchId, (int) $user->id));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error(collect($e->errors())->flatten()->first() ?? $e->getMessage(), 422);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(
            $res['shipment'],
            $res['mode'] === 'last_mile'
                ? 'Transfer received and queued for last-mile delivery.'
                : 'Transfer received at transit hub and sorted for the next TR.'
        );
    }

    /**
     * Receive parcels at a transit hub (intermediate branch). With re_dispatch
     * they leave again on ONE TR per next hop (never one TR per parcel).
     */
    public function receiveAtTransitHub(Request $request, Shipment $shipment)
    {
        $user = $request->user();
        $branchId = $this->resolveReceivingBranchId($user, $request);

        if ($branchId <= 0) {
            return ApiResponse::error('Branch context is required.', 422);
        }

        if ($this->containers->isFinalHere($shipment, $branchId)) {
            // Final destination should use the destination receive path.
            return $this->receive($request, $shipment);
        }

        if (!in_array($shipment->status, [
            CourierStatus::IN_TRANSIT,
            CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
        ], true)) {
            return ApiResponse::error('This shipment is not in transit for transit-hub receive.', 403);
        }

        if (!$this->isExpectedNextHop($shipment, $branchId)) {
            return ApiResponse::error(
                'This shipment is not expected at your branch as a transit hub.',
                403
            );
        }

        $data = $request->validate([
            'received_shipment_ids' => ['nullable', 'array'],
            'received_shipment_ids.*' => ['integer'],
            're_dispatch' => ['boolean'],
            'next_hop_route_id' => ['nullable', 'integer', 'exists:branch_transfer_routes,id'],
            'vehicle_type' => ['nullable', 'string', 'in:'.implode(',', \Modules\Dispatch\Models\DispatchManifest::VEHICLE_TYPES)],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'rider_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'driver_name' => ['nullable', 'string', 'max:100'],
            'driver_phone' => ['nullable', 'string', 'max:20'],
            'seal_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'transport_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'transport_cost_split_mode' => ['nullable', 'in:equal,weight'],
        ]);

        $receivedIds = $data['received_shipment_ids'] ?? [$shipment->id];
        $reDispatch = (bool) ($data['re_dispatch'] ?? false);

        try {
            $result = DB::transaction(function () use ($receivedIds, $reDispatch, $data, $user, $branchId) {
                $receivedShipments = [];
                $onward = [];

                foreach ($receivedIds as $id) {
                    $s = Shipment::query()->find($id);
                    if (!$s || $this->containers->isFinalHere($s, $branchId)) {
                        continue;
                    }
                    if (!in_array($s->status, [CourierStatus::IN_TRANSIT, CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH], true)
                        || !$this->isExpectedNextHop($s, $branchId)) {
                        continue;
                    }

                    $res = $this->containers->receiveAndSort($s, $branchId, (int) $user->id);
                    $receivedShipments[] = $res['shipment'];
                    if ($res['mode'] === 'onward') {
                        $onward[] = $res['shipment'];
                    }
                }

                $out = ['manifests' => [], 'skipped' => []];
                if ($reDispatch && $onward !== []) {
                    $meta = $data;
                    if (!empty($data['next_hop_route_id'])) {
                        $meta['transfer_route_id'] = (int) $data['next_hop_route_id'];
                    }
                    $out = $this->containers->dispatch(collect($onward), null, $branchId, $meta, (int) $user->id);
                }

                return [
                    'received' => $receivedShipments,
                    're_dispatched_manifests' => $out['manifests'],
                    're_dispatch_skipped' => $out['skipped'],
                    'transfer_numbers' => array_map(fn ($m) => $m->display_number, $out['manifests']),
                ];
            });

            return ApiResponse::success(
                $result,
                'Transfer received at transit hub' . ($reDispatch
                    ? ' and re-dispatched on '.(implode(', ', $result['transfer_numbers']) ?: 'no TR')
                    : ' and sorted for the next TR')
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            return ApiResponse::error(collect($e->errors())->flatten()->first() ?? $e->getMessage(), 422);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * True when this branch is the open manifest's to_branch (next hop),
     * or when current_branch_id already matches (post-receive / legacy).
     */
    private function isExpectedNextHop(Shipment $shipment, int $branchId): bool
    {
        if ((int) ($shipment->current_branch_id ?? 0) === $branchId) {
            return true;
        }

        if (!\Illuminate\Support\Facades\Schema::hasTable('dispatch_manifest_items')) {
            // Fallback without manifests: any non-final in_transit may be received
            // by a transit hub that is not the destination.
            return (int) ($shipment->destination_branch_id ?? 0) !== $branchId
                && in_array($shipment->status, [
                    CourierStatus::IN_TRANSIT,
                    CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
                ], true);
        }

        return \Modules\Dispatch\Models\DispatchManifestItem::query()
            ->where('shipment_id', $shipment->id)
            ->whereIn('status', ['sent', 'dispatched', 'in_transit'])
            ->whereHas('manifest', function ($q) use ($branchId) {
                $q->where('to_branch_id', $branchId)
                    ->whereIn('status', ['dispatched', 'in_transit', 'partially_received']);
            })
            ->exists();
    }

    private function resolveReceivingBranchId($user, Request $request): int
    {
        $branchId = $this->getBranchScope($user);
        $requested = $request->integer('branch_id');
        if ($requested > 0 && ($user->isSuperAdmin() || $user->hasRole('main_admin') || $user->hasRole('admin'))) {
            return $requested;
        }
        return $branchId;
    }

    /**
     * Apply the OUTBOUND scope to a query: cross-branch parcels that are ready
     * to leave the origin branch. Shared by index() and stats()/summary() so
     * the board count always matches the list.
     *
     * "Ready" = one of the pre-dispatch statuses AND origin != destination.
     * For SORTED_FOR_DELIVERY we additionally require the parcel to still be at
     * its origin (current_branch_id is null or equals origin) so parcels that
     * already arrived at the destination are NOT shown as outbound again.
     */
    private function applyOutboundScope($query, int $branchId): void
    {
        // Ready to leave THIS branch toward another branch (origin or transit hub).
        $query->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
            ->where(function ($q) {
                $q->whereIn('status', [
                    CourierStatus::SORTED_FOR_TRANSFER,
                    CourierStatus::RECEIVED_AT_ORIGIN_BRANCH,
                    CourierStatus::RECEIVED_AT_TRANSIT_HUB,
                    CourierStatus::PICKED_UP,
                ])->orWhere(function ($q2) {
                    // Mis-sorted cross-branch parcel still at its origin (not yet at dest).
                    $q2->where('status', CourierStatus::SORTED_FOR_DELIVERY)
                        ->where(function ($q3) {
                            $q3->whereNull('current_branch_id')
                                ->orWhereColumn('current_branch_id', '=', 'origin_branch_id');
                        });
                });
            });

        if ($branchId !== 0) {
            $query->where(function ($q) use ($branchId) {
                $q->where('current_branch_id', $branchId)
                    ->orWhere(function ($q2) use ($branchId) {
                        $q2->where('origin_branch_id', $branchId)
                            ->where(function ($q3) {
                                $q3->whereNull('current_branch_id')
                                    ->orWhereColumn('current_branch_id', 'origin_branch_id');
                            });
                    });
            });
            // Do not list parcels already at their final destination as outbound.
            $query->where(function ($q) use ($branchId) {
                $q->whereNull('destination_branch_id')
                    ->orWhere('destination_branch_id', '!=', $branchId);
            });
        }
    }


    /**
     * Transfer routes are keyed by coverage_locations.id.
     * Operational shipments/manifests use branches.id.
     * Kathmandu Franchise (branch 19) -> coverage 1 (Kathmandu main).
     */
    private function coverageIdForBranch(?int $branchId): ?int
    {
        if (!$branchId) {
            return null;
        }

        static $cache = [];
        if (array_key_exists($branchId, $cache)) {
            return $cache[$branchId];
        }

        $cov = \Modules\Branch\Models\Branch::query()
            ->whereKey($branchId)
            ->value('coverage_location_id');

        $cache[$branchId] = $cov !== null ? (int) $cov : null;

        return $cache[$branchId];
    }

    /**
     * Resolve an operational branch for a coverage location.
     * Prefers franchise_branch, then main/active branch for that coverage.
     */

    /**
     * Resolve a value that may be either branches.id OR coverage_locations.id
     * into an operational branches.id (FK-safe for dispatch_manifests).
     */
    private function resolveOperationalBranchId(?int $id): ?int
    {
        if (!$id) {
            return null;
        }

        if (\Modules\Branch\Models\Branch::query()->whereKey($id)->exists()) {
            return $id;
        }

        return $this->branchIdForCoverage($id);
    }

    private function branchIdForCoverage(?int $coverageId): ?int
    {
        if (!$coverageId) {
            return null;
        }

        static $cache = [];
        if (array_key_exists($coverageId, $cache)) {
            return $cache[$coverageId];
        }

        $query = \Modules\Branch\Models\Branch::query()
            ->where('coverage_location_id', $coverageId);

        $franchise = (clone $query)->where('type', 'franchise_branch')->orderBy('id')->value('id');
        if ($franchise) {
            $cache[$coverageId] = (int) $franchise;
            return $cache[$coverageId];
        }

        $any = $query->orderBy('id')->value('id');
        $cache[$coverageId] = $any !== null ? (int) $any : null;

        return $cache[$coverageId];
    }

    /**
     * SENT scope: cross-branch parcels this branch dispatched (branchId 0 = any
     * branch). Uses dispatch manifests; when a parcel has no manifest row
     * (older data) it falls back to the parcel's own origin + dispatch facts.
     */
    private function applySentScope($query, int $branchId): void
    {
        $query->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
            ->where(function ($w) use ($branchId) {
                $w->whereExists(function ($sub) use ($branchId) {
                    $sub->selectRaw('1')
                        ->from('dispatch_manifest_items as dmi')
                        ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
                        ->whereColumn('dmi.shipment_id', 'shipments.id')
                        ->whereNotIn('dm.status', ['open', 'cancelled'])
                        ->whereNotIn('dmi.status', ['added', 'cancelled']);
                    if ($branchId !== 0) {
                        $sub->where('dm.from_branch_id', $branchId);
                    }
                })->orWhere(function ($d) use ($branchId) {
                    if ($branchId !== 0) {
                        $d->where('origin_branch_id', $branchId);
                    }
                    $d->where(function ($x) {
                        $x->whereNotNull('dispatched_at')
                            ->orWhereIn('status', self::POST_DISPATCH_STATUSES);
                    });
                });
            });
    }

    /**
     * RECEIVED scope: cross-branch parcels that arrived at this branch (final
     * destination or transit hub), in any later state. Uses the recorded
     * receipt event / received manifest item; for the final destination it
     * also accepts the parcel's own state (older data without events).
     */
    private function applyReceivedScope($query, int $branchId): void
    {
        $query->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
            ->where(function ($w) use ($branchId) {
                $w->whereExists(function ($sub) use ($branchId) {
                    $sub->selectRaw('1')
                        ->from('tracking_events as te')
                        ->whereColumn('te.shipment_id', 'shipments.id')
                        ->whereIn('te.status', self::RECEIPT_EVENT_STATUSES);
                    if ($branchId !== 0) {
                        $sub->where('te.branch_id', $branchId);
                    }
                })->orWhereExists(function ($sub) use ($branchId) {
                    $sub->selectRaw('1')
                        ->from('dispatch_manifest_items as dmi')
                        ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
                        ->whereColumn('dmi.shipment_id', 'shipments.id')
                        ->where('dmi.status', 'received');
                    if ($branchId !== 0) {
                        $sub->where('dm.to_branch_id', $branchId);
                    }
                })->orWhere(function ($d) use ($branchId) {
                    if ($branchId !== 0) {
                        $d->where('destination_branch_id', $branchId);
                    }
                    $d->whereIn('status', self::POST_RECEIPT_STATUSES)
                        ->where(function ($x) {
                            $x->whereNotNull('received_at_destination_at')
                                ->orWhereColumn('current_branch_id', 'destination_branch_id');
                        });
                });
            });
    }

    /**
     * search (tracking / receiver / phone), status (comma list), service_type.
     */
    private function applyCommonListFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('tracking_number', 'like', "%{$search}%")
                        ->orWhere('receiver_name', 'like', "%{$search}%")
                        ->orWhere('receiver_phone', 'like', "%{$search}%");
                });
            }
        }

        if ($request->filled('status') && $request->string('status')->toString() !== 'all') {
            $statuses = array_values(array_filter(array_map('trim', explode(',', $request->string('status')->toString()))));
            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }

        if ($request->filled('service_type') && $request->string('service_type')->toString() !== 'all') {
            $query->where('service_type', $request->string('service_type')->toString());
        }
    }

    /**
     * Adds timeline, branch_events and transfer_info to each shipment, built
     * only from recorded tracking events and dispatch manifests. Nothing is
     * invented: when a fact is missing it is reported in transfer_info.data_gaps.
     */
    private function attachTransferInfo($shipments, int $branchId): void
    {
        $ids = collect($shipments)->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();
        if ($ids === []) {
            return;
        }

        $events = DB::table('tracking_events')
            ->whereIn('shipment_id', $ids)
            ->whereIn('status', self::TRANSFER_EVENT_STATUSES)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'shipment_id', 'status', 'branch_id', 'description', 'created_by', 'created_at'])
            ->groupBy('shipment_id');

        $hasTr = \Illuminate\Support\Facades\Schema::hasColumn('dispatch_manifests', 'transfer_number');
        $manifests = DB::table('dispatch_manifest_items as dmi')
            ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
            ->whereIn('dmi.shipment_id', $ids)
            ->whereNotIn('dm.status', ['open', 'cancelled'])
            ->whereNotIn('dmi.status', ['added', 'cancelled'])
            ->orderBy('dm.id')
            ->get([
                'dmi.shipment_id', 'dmi.status as item_status', 'dm.id',
                // TR number where assigned; MF number for older containers.
                $hasTr ? DB::raw('COALESCE(dm.transfer_number, dm.manifest_number) as manifest_number') : 'dm.manifest_number',
                'dm.from_branch_id', 'dm.to_branch_id', 'dm.status', 'dm.dispatched_at',
                'dm.received_at', 'dm.created_by', 'dm.received_by', 'dm.created_at',
            ])
            ->groupBy('shipment_id');

        $userIds = $events->flatten()->pluck('created_by')
            ->merge($manifests->flatten()->pluck('created_by'))
            ->merge($manifests->flatten()->pluck('received_by'))
            ->filter()->unique()->values()->all();
        $users = $userIds ? DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id') : collect();

        $branchIds = $events->flatten()->pluck('branch_id')
            ->merge($manifests->flatten()->pluck('from_branch_id'))
            ->merge($manifests->flatten()->pluck('to_branch_id'))
            ->merge(collect($shipments)->pluck('origin_branch_id'))
            ->merge(collect($shipments)->pluck('destination_branch_id'))
            ->merge(collect($shipments)->pluck('current_branch_id'))
            ->filter()->unique()->values()->all();
        $branches = $branchIds ? DB::table('branches')->whereIn('id', $branchIds)->pluck('name', 'id') : collect();

        $name = fn ($map, $id) => $id ? ($map[$id] ?? null) : null;

        foreach ($shipments as $shipment) {
            $sid = (int) $shipment->id;
            $rows = $manifests->get($sid, collect());
            $originId = (int) ($shipment->origin_branch_id ?? 0);
            $destinationId = (int) ($shipment->destination_branch_id ?? 0);

            $timeline = [];
            $lastBranch = $originId ?: null;
            foreach ($events->get($sid, collect()) as $e) {
                $eventBranch = $e->branch_id ? (int) $e->branch_id : null;
                $entry = [
                    'status' => $e->status,
                    'type' => $this->transferEventType((string) $e->status),
                    'description' => $e->description,
                    'at' => $e->created_at,
                    'branch_id' => $eventBranch,
                    'branch_name' => $name($branches, $eventBranch),
                    'by' => $name($users, $e->created_by),
                ];

                if (in_array($e->status, [CourierStatus::IN_TRANSIT, CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH], true)) {
                    // Dispatch events are written without branch_id: the parcel
                    // leaves the last branch that recorded it.
                    $fromBranch = $eventBranch ?: $lastBranch;
                    $manifest = $this->matchManifest($rows, $fromBranch, (string) $e->created_at);
                    $toBranch = $manifest ? (int) $manifest->to_branch_id : null;
                    $entry['from_branch_id'] = $fromBranch;
                    $entry['from_branch_name'] = $name($branches, $fromBranch);
                    $entry['to_branch_id'] = $toBranch;
                    $entry['to_branch_name'] = $name($branches, $toBranch);
                    $entry['manifest_number'] = $manifest->manifest_number ?? null;
                    $entry['manifest_status'] = $manifest->status ?? null;
                    $entry['branch_name'] = $entry['branch_name'] ?? $entry['from_branch_name'];
                } elseif ($eventBranch) {
                    $lastBranch = $eventBranch;
                }

                $timeline[] = $entry;
            }

            $sentBranch = $branchId ?: $originId;
            $receivedBranch = $branchId ?: $destinationId;
            $gaps = [];

            // Sent from $sentBranch.
            $sentEvent = collect($timeline)->first(fn ($ev) => ($ev['type'] ?? '') === 'dispatched'
                && (int) ($ev['from_branch_id'] ?? 0) === $sentBranch);
            $sentManifest = $rows->last(fn ($m) => (int) $m->from_branch_id === $sentBranch);
            $sent = null;
            if ($sentEvent || $sentManifest) {
                $sent = [
                    'branch_id' => $sentBranch,
                    'branch_name' => $name($branches, $sentBranch),
                    'at' => $sentEvent['at'] ?? $sentManifest->dispatched_at ?? $sentManifest->created_at ?? null,
                    'by' => $sentEvent['by'] ?? $name($users, $sentManifest->created_by ?? null),
                    'to_branch_id' => $sentManifest ? (int) $sentManifest->to_branch_id : ($sentEvent['to_branch_id'] ?? null),
                    'to_branch_name' => $sentManifest ? $name($branches, (int) $sentManifest->to_branch_id) : ($sentEvent['to_branch_name'] ?? null),
                    'manifest_number' => $sentManifest->manifest_number ?? ($sentEvent['manifest_number'] ?? null),
                    'source' => $sentManifest ? 'manifest' : 'tracking_event',
                ];
                if (! $sentManifest) {
                    $gaps[] = 'No dispatch manifest row for this branch; dispatch taken from the tracking event.';
                }
            } elseif ($sentBranch === $originId && $shipment->dispatched_at) {
                $sent = [
                    'branch_id' => $sentBranch,
                    'branch_name' => $name($branches, $sentBranch),
                    'at' => $shipment->dispatched_at,
                    'by' => null,
                    'to_branch_id' => null,
                    'to_branch_name' => null,
                    'manifest_number' => null,
                    'source' => 'shipment',
                ];
                $gaps[] = 'No dispatch manifest or dispatch event; only the shipment dispatched_at is recorded.';
            }

            // Received at $receivedBranch.
            $receivedEvent = collect($timeline)->last(fn ($ev) => ($ev['type'] ?? '') === 'received'
                && (int) ($ev['branch_id'] ?? 0) === $receivedBranch);
            $receivedManifest = $rows->last(fn ($m) => (int) $m->to_branch_id === $receivedBranch);
            $received = null;
            if ($receivedEvent || ($receivedManifest && $receivedManifest->item_status === 'received')) {
                $received = [
                    'branch_id' => $receivedBranch,
                    'branch_name' => $name($branches, $receivedBranch),
                    'at' => $receivedEvent['at'] ?? $receivedManifest->received_at ?? null,
                    'by' => $receivedEvent['by'] ?? $name($users, $receivedManifest->received_by ?? null),
                    'from_branch_id' => $receivedManifest ? (int) $receivedManifest->from_branch_id : null,
                    'from_branch_name' => $receivedManifest ? $name($branches, (int) $receivedManifest->from_branch_id) : null,
                    'manifest_number' => $receivedManifest->manifest_number ?? null,
                    'source' => $receivedEvent ? 'tracking_event' : 'manifest',
                ];
            } elseif ($receivedBranch === $destinationId
                && in_array($shipment->status, self::POST_RECEIPT_STATUSES, true)
                && ($shipment->received_at_destination_at || (int) ($shipment->current_branch_id ?? 0) === $destinationId)) {
                $received = [
                    'branch_id' => $receivedBranch,
                    'branch_name' => $name($branches, $receivedBranch),
                    'at' => $shipment->received_at_destination_at,
                    'by' => null,
                    'from_branch_id' => null,
                    'from_branch_name' => null,
                    'manifest_number' => null,
                    'source' => 'shipment',
                ];
                $gaps[] = 'No receipt event at this branch; received taken from the shipment state.';
            }

            if ($receivedManifest && $received && in_array($receivedManifest->item_status, ['sent', 'dispatched', 'in_transit'], true)) {
                $gaps[] = 'Manifest '.($receivedManifest->manifest_number ?? '#'.$receivedManifest->id)
                    .' still shows the parcel as '.$receivedManifest->item_status.' although it was received '
                    .'(run php artisan transfers:repair-manifest-receipts).';
            }

            $role = null;
            if ($branchId !== 0) {
                $role = $branchId === $originId ? 'origin' : ($branchId === $destinationId ? 'destination' : 'transit');
            }

            $branchEvents = $branchId === 0
                ? $timeline
                : array_values(array_filter($timeline, fn ($ev) => (int) ($ev['branch_id'] ?? 0) === $branchId
                    || (int) ($ev['from_branch_id'] ?? 0) === $branchId
                    || (int) ($ev['to_branch_id'] ?? 0) === $branchId));

            $currentBranchId = $shipment->current_branch_id ? (int) $shipment->current_branch_id : null;

            $shipment->setAttribute('timeline', $timeline);
            $shipment->setAttribute('branch_events', $branchEvents);
            $shipment->setAttribute('transfer_info', [
                'branch_id' => $branchId ?: null,
                'branch_name' => $name($branches, $branchId ?: null),
                'role' => $role,
                'sent' => $sent,
                'received' => $received,
                'current_status' => $shipment->status,
                'current_status_label' => ucwords(str_replace('_', ' ', (string) $shipment->status)),
                'current_branch_id' => $currentBranchId,
                'current_branch_name' => $name($branches, $currentBranchId),
                'data_gaps' => $gaps,
            ]);
        }
    }

    private function transferEventType(string $status): string
    {
        return match ($status) {
            CourierStatus::SORTED_FOR_TRANSFER => 'sorted_for_transfer',
            CourierStatus::IN_TRANSIT, CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH => 'dispatched',
            CourierStatus::RECEIVED_AT_TRANSIT_HUB,
            CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
            CourierStatus::RECEIVED_AT_DESTINATION_SUB_BRANCH => 'received',
            CourierStatus::SORTED_FOR_DELIVERY => 'sorted_for_delivery',
            CourierStatus::ASSIGNED_TO_RIDER, CourierStatus::OUT_FOR_DELIVERY => 'last_mile',
            CourierStatus::DELIVERED => 'delivered',
            CourierStatus::DELIVERY_FAILED => 'delivery_failed',
            CourierStatus::RETURN_INITIATED => 'returning',
            default => 'other',
        };
    }

    /**
     * Manifest that carried a dispatch from $fromBranch, closest in time to $at.
     */
    private function matchManifest($rows, ?int $fromBranch, string $at): ?object
    {
        if (! $fromBranch) {
            return null;
        }

        $candidates = collect($rows)->filter(fn ($m) => (int) $m->from_branch_id === $fromBranch);
        if ($candidates->isEmpty()) {
            return null;
        }

        $target = strtotime($at) ?: 0;

        return $candidates->sortBy(function ($m) use ($target) {
            $time = strtotime((string) ($m->dispatched_at ?? $m->created_at ?? '')) ?: 0;

            return abs($time - $target);
        })->first();
    }

    /**
     * Get branch scope for current user
     */
    /* ------------------------------------------------------------------ */
    /* TR transfer containers                                              */
    /* ------------------------------------------------------------------ */

    /**
     * GET transfers/containers?direction=outbound|inbound|all&status=a,b&search=
     * TR containers sent from / headed to this branch.
     */
    public function containers(Request $request)
    {
        $branchId = $this->resolveReceivingBranchId($request->user(), $request);
        $direction = $request->string('direction')->toString() ?: 'outbound';

        $query = \Modules\Dispatch\Models\DispatchManifest::query()
            ->with(['items.shipment:id,tracking_number,destination_branch_id,status,weight,chargeable_weight', 'fromBranch:id,name', 'toBranch:id,name', 'rider:id,name,phone']);

        if ($branchId !== 0) {
            match ($direction) {
                'inbound' => $query->where('to_branch_id', $branchId),
                'all' => $query->where(fn ($q) => $q->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId)),
                default => $query->where('from_branch_id', $branchId),
            };
        }

        $statuses = array_values(array_filter(explode(',', (string) $request->input('status', ''))));
        if ($statuses === [] && $direction === 'inbound') {
            $statuses = ['dispatched', 'in_transit', 'partially_received'];
        }
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('search')) {
            $term = trim($request->string('search')->toString());
            $hasTr = \Illuminate\Support\Facades\Schema::hasColumn('dispatch_manifests', 'transfer_number');
            $query->where(function ($q) use ($term, $hasTr) {
                $q->where('manifest_number', 'like', "%{$term}%")
                    ->orWhere('vehicle_number', 'like', "%{$term}%")
                    ->orWhere('driver_name', 'like', "%{$term}%")
                    ->orWhereHas('items.shipment', fn ($s) => $s->where('tracking_number', 'like', "%{$term}%"));
                if ($hasTr) {
                    $q->orWhere('transfer_number', 'like', "%{$term}%");
                }
            });
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $page = $query->orderByRaw("FIELD(status, 'partially_received', 'in_transit', 'dispatched', 'open') DESC")
            ->latest('id')
            ->paginate($perPage);
        $page->setCollection($page->getCollection()->map(fn ($m) => $this->containers->summarize($m)));

        return ApiResponse::success($page);
    }

    /** GET transfers/containers/{container} : TR with its parcels. */
    public function containerShow(Request $request, \Modules\Dispatch\Models\DispatchManifest $container)
    {
        $this->assertContainerVisible($request, $container);
        $container->load(['items.shipment.destinationBranch:id,name', 'fromBranch:id,name', 'toBranch:id,name', 'rider:id,name,phone']);

        $data = $this->containers->summarize($container, true);
        $data['can_receive'] = $this->branchMatches($request, (int) $container->to_branch_id)
            && in_array($container->status, ['dispatched', 'in_transit'], true);
        $data['can_resolve'] = $this->branchMatches($request, (int) $container->to_branch_id)
            && $container->status === 'partially_received';
        $data['can_dispatch'] = $this->branchMatches($request, (int) $container->from_branch_id) && $container->status === 'open';
        $data['can_cancel'] = $data['can_dispatch'];

        return ApiResponse::success($data);
    }

    /**
     * POST transfers/containers/{container}/receive
     * { scanned: [shipment ids or tracking numbers], remarks? }
     */
    public function containerReceive(Request $request, \Modules\Dispatch\Models\DispatchManifest $container)
    {
        $data = $request->validate([
            'scanned' => ['present', 'array', 'max:2000'],
            'scanned.*' => ['nullable'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        $branchId = $this->resolveReceivingBranchId($request->user(), $request);

        $res = $this->containers->receive(
            $container,
            array_values(array_filter($data['scanned'], fn ($v) => is_scalar($v) && trim((string) $v) !== '')),
            $data['remarks'] ?? null,
            (int) $request->user()->id,
            $branchId,
        );

        $c = $res['container'];
        $msg = sprintf(
            '%s received: %d in (%d last mile, %d onward)%s%s.',
            $c->display_number,
            count($res['received']) + count($res['extras']),
            collect($res['received'])->merge($res['extras'])->where('result', 'last_mile')->count(),
            collect($res['received'])->merge($res['extras'])->where('result', 'onward')->count(),
            $res['missing'] ? ', '.count($res['missing']).' missing' : '',
            $res['extras'] ? ', '.count($res['extras']).' extra' : '',
        );

        return ApiResponse::success([
            'container' => $this->containers->summarize($c->load('items.shipment.destinationBranch:id,name'), true),
            'received' => $res['received'],
            'missing' => $res['missing'],
            'extras' => $res['extras'],
            'rejected' => $res['rejected'],
        ], $msg);
    }

    /**
     * POST transfers/containers/{container}/items/{item}/receive
     * { action: found|lost, note? } : resolve a parcel flagged missing.
     */
    public function containerResolveItem(Request $request, \Modules\Dispatch\Models\DispatchManifest $container, \Modules\Dispatch\Models\DispatchManifestItem $item)
    {
        $data = $request->validate([
            'action' => ['required', 'in:found,lost'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $branchId = $this->resolveReceivingBranchId($request->user(), $request);

        $res = $this->containers->resolveItem($container, $item, $data['action'], $data['note'] ?? null, (int) $request->user()->id, $branchId);

        return ApiResponse::success([
            'container' => $this->containers->summarize($res['container']->load('items.shipment.destinationBranch:id,name'), true),
            'item' => $res['item'],
        ], $data['action'] === 'found' ? 'Parcel received and sorted.' : 'Parcel marked lost.');
    }

    /** POST transfers/containers/{container}/dispatch : send an open TR. */
    public function containerDispatch(Request $request, \Modules\Dispatch\Models\DispatchManifest $container)
    {
        $data = $request->validate([
            'vehicle_type' => ['nullable', 'string', 'in:'.implode(',', \Modules\Dispatch\Models\DispatchManifest::VEHICLE_TYPES)],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'rider_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'driver_name' => ['nullable', 'string', 'max:100'],
            'driver_phone' => ['nullable', 'string', 'max:20'],
            'seal_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'transport_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'transport_cost_split_mode' => ['nullable', 'in:equal,weight'],
        ]);
        abort_unless($this->branchMatches($request, (int) $container->from_branch_id), 403, 'Only the sending branch can dispatch this TR.');

        $c = $this->containers->dispatchOpen($container, $data, (int) $request->user()->id);

        return ApiResponse::success($this->containers->summarize($c), $c->display_number.' dispatched.');
    }

    /** POST transfers/containers/{container}/cancel/dispatch { reason? } : cancel an open TR. */
    public function containerCancel(Request $request, \Modules\Dispatch\Models\DispatchManifest $container)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        abort_unless($this->branchMatches($request, (int) $container->from_branch_id), 403, 'Only the sending branch can cancel this TR.');

        $c = $this->containers->cancel($container, $data['reason'] ?? null, (int) $request->user()->id);

        return ApiResponse::success($this->containers->summarize($c), $c->display_number.' cancelled. Its parcels stay ready for transfer.');
    }

    /** GET transfers/riders : staff of the dispatching branch for the TR rider picker. */
    public function riders(Request $request)
    {
        $branchId = $this->resolveReceivingBranchId($request->user(), $request);
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('users');
        $select = array_values(array_intersect(['id', 'name', 'phone', 'role', 'branch_id'], $columns));

        $q = DB::table('users')->select($select);
        if ($branchId !== 0) {
            $q->where('branch_id', $branchId);
        } else {
            $q->whereNotNull('branch_id');
        }
        if (in_array('role', $columns, true)) {
            $q->whereNotIn('role', ['merchant', 'customer', 'super_admin', 'main_admin']);
        }
        if (in_array('status', $columns, true)) {
            $q->where(fn ($w) => $w->whereNull('status')->orWhereIn('status', ['active', '1']));
        }
        if (in_array('deleted_at', $columns, true)) {
            $q->whereNull('deleted_at');
        }
        if ($request->filled('search')) {
            $term = trim($request->string('search')->toString());
            $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"));
        }

        $riderRoles = ['rider', 'pickup_rider', 'delivery_staff', 'dispatch_staff'];
        $rows = $q->orderBy('name')->limit(200)->get()->map(function ($u) use ($riderRoles) {
            $u->is_rider = in_array($u->role ?? null, $riderRoles, true);
            return $u;
        })->sortByDesc('is_rider')->values();

        return ApiResponse::success($rows);
    }

    /** GET transfers/shipments/{shipment}/hops : per-hop TR, cost and times for one parcel. */
    public function shipmentHops(Request $request, Shipment $shipment)
    {
        return ApiResponse::success([
            'shipment_id' => $shipment->id,
            'hops' => app(\Modules\Dispatch\Services\ManifestHopService::class)->hopsForShipment((int) $shipment->id),
        ]);
    }

    private function branchMatches(Request $request, int $branchId): bool
    {
        $scope = $this->resolveReceivingBranchId($request->user(), $request);

        return $scope === 0 || $scope === $branchId || $this->progress->sameOperationalLocation($scope, $branchId);
    }

    private function assertContainerVisible(Request $request, \Modules\Dispatch\Models\DispatchManifest $container): void
    {
        abort_unless(
            $this->branchMatches($request, (int) $container->from_branch_id) || $this->branchMatches($request, (int) $container->to_branch_id),
            403,
            'This TR belongs to other branches.'
        );
    }

    private function getBranchScope($user): int
    {
        if ($user->isSuperAdmin() || $user->hasRole('main_admin')) {
            return 0; // No scope restriction
        }

        return (int) ($user->branch_id ?? 0);
    }
}
