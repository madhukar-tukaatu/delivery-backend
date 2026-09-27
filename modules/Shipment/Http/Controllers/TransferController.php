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
    public function __construct(
        private readonly TransferService $service,
        private readonly \Modules\Shipment\Services\TransferRouteProgressService $progress,
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
        $branchId = $this->getBranchScope($user);
        $direction = $request->string('direction')->toString() ?: 'outbound';

        if (!in_array($direction, ['outbound', 'inbound'], true)) {
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
            $progress = $this->progress->resolveForShipment($shipment, $branchId !== 0 ? $branchId : null);

            if ($progress['ready_for_last_mile'] || empty($progress['next_hop_branch_id'])) {
                $unmatched[] = $shipment;
                continue;
            }

            $hopId = (int) $progress['next_hop_branch_id'];
            $service = (string) ($progress['service_type'] ?? $shipment->service_type ?? 'standard');
            $key = $hopId . '|' . $service;

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'next_hop_branch_id' => $hopId,
                    'next_hop_name' => $progress['next_hop_name'] ?? ("Branch #{$hopId}"),
                    'next_hop_coverage_id' => $progress['next_hop_coverage_id'] ?? null,
                    'service_type' => $service,
                    'shipments' => [],
                    'count' => 0,
                    'finals' => [],
                    'route_codes' => [],
                ];
            }

            $shipment->setAttribute('_hop_progress', $progress);
            $groups[$key]['shipments'][] = $shipment;
            $groups[$key]['count']++;

            $finalId = (int) ($shipment->destination_branch_id ?? 0);
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

        $paginated = [];
        foreach ($groups as $group) {
            $paginated[] = [
                'next_hop_branch_id' => $group['next_hop_branch_id'],
                'next_hop_name' => $group['next_hop_name'],
                'next_hop_coverage_id' => $group['next_hop_coverage_id'],
                'service_type' => $group['service_type'],
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
        $branchId = $this->getBranchScope($user);

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

        // Received: arrived at this branch, ready for last-mile delivery.
        // Includes both the explicit received status and cross-branch parcels
        // that auto-advanced to sorted_for_delivery AT the destination.
        $received = Shipment::query()
            ->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
            ->where(function ($q) {
                $q->where('status', CourierStatus::RECEIVED_AT_DESTINATION_BRANCH)
                    ->orWhere(function ($q2) {
                        $q2->where('status', CourierStatus::SORTED_FOR_DELIVERY)
                            ->whereColumn('current_branch_id', '=', 'destination_branch_id');
                    });
            });
        
        if ($branchId !== 0) {
            $received->where('destination_branch_id', $branchId);
        }
        $received = $received->count();

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
            'in_transit' => $inTransit,
            'received' => $received,
            'completed' => $completed,
        ]);
    }

    /**
     * Summary for transfer tabs
     */
    public function summary(Request $request)
    {
        $user = $request->user();
        $branchId = $this->getBranchScope($user);

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
        $branchId = $this->getBranchScope($user);

        $query = Shipment::query()->with([
            'merchant',
            'originBranch',
            'destinationBranch',
            'currentBranch',
        ]);

        // ONLY cross-branch transfers received at this branch. Includes parcels
        // that auto-advanced to sorted_for_delivery once received at destination.
        $query->whereColumn('origin_branch_id', '!=', 'destination_branch_id') // Cross-branch only!
            ->where(function ($q) {
                $q->where('status', CourierStatus::RECEIVED_AT_DESTINATION_BRANCH)
                    ->orWhere(function ($q2) {
                        $q2->where('status', CourierStatus::SORTED_FOR_DELIVERY)
                            ->whereColumn('current_branch_id', '=', 'destination_branch_id');
                    });
            });

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

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return ApiResponse::success($query->latest('id')->paginate($perPage));
    }

    /**
     * Get completed transfers delivered to this branch
     * Note: Only shows CROSS-BRANCH transfers (origin != destination), not same-branch local deliveries
     */
    public function completed(Request $request)
    {
        $user = $request->user();
        $branchId = $this->getBranchScope($user);

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
        $branchId = $this->getBranchScope($user);

        $query = Shipment::query()->with([
            'merchant',
            'originBranch',
            'destinationBranch',
        ]);

        // All transfer-related statuses, cross-branch only.
        $query->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
            ->whereIn('status', [
                CourierStatus::SORTED_FOR_TRANSFER,
                CourierStatus::IN_TRANSIT,
                CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
                CourierStatus::SORTED_FOR_DELIVERY,
                CourierStatus::DELIVERED,
                CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
                CourierStatus::RECEIVED_AT_TRANSIT_HUB,
            ]);

        if ($branchId !== 0) {
            $query->where(function ($q) use ($branchId) {
                // Show transfers that originate from or are destined for this branch
                $q->where('origin_branch_id', $branchId)
                    ->orWhere('destination_branch_id', $branchId);
            });
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
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $results = $query->latest('updated_at')->paginate($perPage);

        // Add timeline events
        $results->getCollection()->transform(function ($shipment) {
            $trackingEvents = DB::table('tracking_events')
                ->where('shipment_id', $shipment->id)
                ->whereIn('status', [
                    CourierStatus::SORTED_FOR_TRANSFER,
                    CourierStatus::IN_TRANSIT,
                    CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
                    CourierStatus::SORTED_FOR_DELIVERY,
                    CourierStatus::DELIVERED,
                ])
                ->orderBy('created_at')
                ->get();

            $shipment->timeline = $trackingEvents->map(function ($event) {
                return [
                    'status' => $event->status,
                    'description' => $event->description,
                    'at' => $event->created_at,
                ];
            });

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
        $nextHopCoverageId = $destinationBranchId;
        $remainingTransitIds = array_column($transitBranches, 'branch_id');
        $legIndex = 0;

        if ($fromCoverageId !== null && $pathIds !== []) {
            $idx = array_search((int) $fromCoverageId, $pathIds, true);
            if ($idx !== false && isset($pathIds[$idx + 1])) {
                $nextHopCoverageId = (int) $pathIds[$idx + 1];
                $remainingTransitIds = array_values(array_slice($pathIds, $idx + 2, -1));
                $legIndex = (int) $idx;
            }
        }

        $operationalOrigin = $operationalFromBranchId
            ?? $this->branchIdForCoverage($fromCoverageId ?? $originBranchId)
            ?? $originBranchId;
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
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'driver_name' => ['nullable', 'string', 'max:100'],
            'driver_phone' => ['nullable', 'string', 'max:20'],
            'seal_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $branchId = $this->getBranchScope($user);
        $shipmentIds = array_values(array_unique(array_map(fn($id) => (int) $id, $data['shipment_ids'])));

        // Verify shipments are cross-branch parcels ready for transfer
        $availableQuery = Shipment::query()->whereIn('id', $shipmentIds);
        $this->applyOutboundScope($availableQuery, $branchId);
        $available = $availableQuery->pluck('id')->all();

        $skipped = array_diff($shipmentIds, $available);

        if (empty($available)) {
            return ApiResponse::error('No valid shipments to dispatch.', 422);
        }

        // Load shipments with route info to group by route
        $shipments = Shipment::query()
            ->whereIn('id', $available)
            ->with(['originBranch', 'destinationBranch', 'routeSteps'])
            ->get();

        // If transfer_route_id provided, use it; otherwise group by destination
        $routeId = $data['transfer_route_id'] ?? null;
        $route = $routeId ? \Modules\Rate\Models\BranchTransferRoute::find($routeId) : null;

        $result = ['dispatched' => [], 'skipped' => []];
        $manifests = [];
        $dispatchedCount = 0;

        if ($route) {
            // Single route specified - create one manifest for all shipments
            $manifest = $this->createManifestForRoute($route, $shipments, $data, $user);
            $manifests[] = $manifest;
            $dispatchedCount = $manifest->items->count();
        } elseif (!empty($data['next_hop_branch_id'])) {
            // Next-hop hub bagging: one manifest to the shared next hop;
            // each shipment keeps its own matching transfer_route_id.
            $nextHopId = (int) ($this->resolveOperationalBranchId((int) $data['next_hop_branch_id'])
                ?? (int) $data['next_hop_branch_id']);
            $manifest = $this->createManifestForNextHop(
                $shipments,
                $nextHopId,
                $branchId,
                $data,
                $user
            );
            $manifests[] = $manifest;
            $dispatchedCount = $manifest->items->count();
            foreach ($manifest->getAttribute('_skipped_ids') ?? [] as $sid => $reason) {
                $result['skipped'][(int) $sid] = $reason;
            }
        } else {
            // Group shipments by destination branch (and transit path)
            $groups = $this->groupShipmentsByRoute($shipments, $branchId);

            foreach ($groups as $group) {
                if (empty($group['shipments'])) continue;
                
                $groupRoute = $group['route'];
                $groupShipments = collect($group['shipments']);
                
                $manifest = $this->createManifestForRoute($groupRoute, $groupShipments, $data, $user);
                $manifests[] = $manifest;
                $dispatchedCount += $manifest->items->count();
            }
        }

        foreach ($skipped as $id) {
            $result['skipped'][(int) $id] = 'Shipment not ready for dispatch (must be sorted first)';
        }

        $result['manifests'] = $manifests;
        $result['dispatched_count'] = $dispatchedCount;
        foreach ($manifests as $m) {
            foreach (($m->items ?? []) as $item) {
                if (!empty($item->shipment_id)) {
                    $result['dispatched'][] = (int) $item->shipment_id;
                }
            }
        }
        $result['dispatched'] = array_values(array_unique($result['dispatched'] ?? []));

        $ok = count($result['dispatched'] ?? []);
        $fail = count($result['skipped'] ?? []);

        return ApiResponse::success(
            $result,
            $fail === 0 ? "{$ok} shipments dispatched in " . count($manifests) . " manifest(s)." : "{$ok} dispatched, {$fail} skipped."
        );
    }

    /**
     * Group shipments by their matching configured route.
     */
    private function groupShipmentsByRoute($shipments, int $branchId): array
    {
        $groups = [];

        foreach ($shipments as $shipment) {
            $destNode = $shipment->destination_sub_branch_id ?? $shipment->destination_branch_id;
            $serviceType = $shipment->service_type ?? 'standard';

            if (!$destNode) {
                // No destination - put in unmatched
                $key = 'unmatched';
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'route' => null,
                        'shipments' => [],
                    ];
                }
                $groups[$key]['shipments'][] = $shipment;
                continue;
            }

            // Try to find configured route
            $route = $this->findMatchingRoute($branchId, $destNode, $serviceType);

            if ($route) {
                $key = "route_{$route['id']}";
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'route' => (object) $route, // Cast to object for createManifestForRoute
                        'shipments' => [],
                    ];
                }
                $groups[$key]['shipments'][] = $shipment;
            } else {
                // Fallback: group by destination branch
                $key = "fallback_{$destNode}";
                if (!isset($groups[$key])) {
                    $destBranch = \Modules\Branch\Models\Branch::find($destNode);
                    $groups[$key] = [
                        'route' => (object) [
                            'id' => null,
                            'route_code' => 'AUTO',
                            'route_name' => 'Auto: ' . ($destBranch?->name ?? "Branch #{$destNode}"),
                            'service_type' => $serviceType,
                            'origin_branch_id' => $branchId,
                            'destination_branch_id' => $destNode,
                            'transit_branch_ids' => [],
                            'transit_count' => 0,
                            'transfer_count' => 1,
                            'path_text' => ($shipment->originBranch?->name ?? 'Origin') . ' → ' . ($destBranch?->name ?? 'Destination'),
                            'is_configured' => false,
                        ],
                        'shipments' => [],
                    ];
                }
                $groups[$key]['shipments'][] = $shipment;
            }
        }

        return $groups;
    }

    /**
     * Create a DispatchManifest for a route with the given shipments.
     */

    /**
     * Hub bagging: one outbound manifest to a shared next hop.
     * Each shipment keeps its own matching transfer_route_id / path_text.
     */
    private function createManifestForNextHop($shipments, int $nextHopBranchId, int $branchId, array $data, $user): \Modules\Dispatch\Models\DispatchManifest
    {
        $shipments = collect($shipments)->values();
        $matched = [];
        $skipped = [];

        $nextHopBranchId = (int) ($this->resolveOperationalBranchId($nextHopBranchId) ?? $nextHopBranchId);

        foreach ($shipments as $shipment) {
            $destNode = (int) ($shipment->destination_sub_branch_id ?? $shipment->destination_branch_id ?? 0);
            $serviceType = (string) ($shipment->service_type ?? 'standard');
            $originNode = (int) (
                $shipment->current_branch_id
                ?: $shipment->origin_sub_branch_id
                ?: $shipment->origin_branch_id
                ?: $branchId
            );

            if ($destNode <= 0) {
                $skipped[(int) $shipment->id] = 'Shipment has no destination branch';
                continue;
            }

            try {
                $this->progress->assertNotInActiveManifest($shipment);
                $this->progress->assertNextHopMatches($shipment, $nextHopBranchId, $branchId !== 0 ? $branchId : $originNode);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $msgs = collect($e->errors())->flatten()->all();
                $skipped[(int) $shipment->id] = $msgs[0] ?? $e->getMessage();
                continue;
            }

            $progress = $this->progress->resolveForShipment($shipment, $branchId !== 0 ? $branchId : $originNode);
            $route = $this->findMatchingRoute($originNode > 0 ? $originNode : $branchId, $destNode, $serviceType);
            if (!empty($progress['transfer_route_id'])) {
                $assigned = \Modules\Rate\Models\BranchTransferRoute::with(['routeLanes.lane.fromBranch', 'routeLanes.lane.toBranch'])->find($progress['transfer_route_id']);
                if ($assigned) {
                    $fromCov = $this->coverageIdForBranch($originNode > 0 ? $originNode : $branchId);
                    $route = $this->formatRouteForDispatch($assigned, $fromCov, $originNode > 0 ? $originNode : $branchId);
                }
            }
            if (!$route) {
                $skipped[(int) $shipment->id] = 'No configured transfer route for destination/service';
                continue;
            }

            $matched[] = [
                'shipment' => $shipment,
                'route' => $route,
            ];
        }

        if ($matched === []) {
            throw new \InvalidArgumentException('No shipments match the selected next hop.');
        }

        $first = $matched[0];
        $firstRoute = $first['route'];
        $rawOrigin = (int) (
            $first['shipment']->current_branch_id
            ?: $first['shipment']->origin_sub_branch_id
            ?: $first['shipment']->origin_branch_id
            ?: ($firstRoute['origin_branch_id'] ?? $branchId)
            ?: 0
        );
        $originBranchId = (int) ($this->resolveOperationalBranchId($rawOrigin) ?? 0);

        $manifest = \Illuminate\Support\Facades\DB::transaction(function () use (
            $matched, $data, $user, $originBranchId, $nextHopBranchId
        ) {
            $manifestNumber = 'MF-' . now()->format('YmdHis') . '-' . random_int(100, 999);
            $schema = \Illuminate\Support\Facades\Schema::getColumnListing('dispatch_manifests');

            $payload = [
                'manifest_number' => $manifestNumber,
                'from_branch_id' => $originBranchId ?: null,
                'from_sub_branch_id' => null,
                'to_branch_id' => $nextHopBranchId ?: null,
                'to_sub_branch_id' => null,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'seal_number' => $data['seal_number'] ?? null,
                'status' => 'dispatched',
                'created_by' => $user->id,
                'dispatched_at' => now(),
            ];

            $routeCodes = array_values(array_unique(array_filter(array_map(
                static fn ($m) => $m['route']['route_code'] ?? null,
                $matched
            ))));
            $optional = [
                'driver_phone' => $data['driver_phone'] ?? null,
                'notes' => $data['notes'] ?? null,
                'route_id' => null,
                'route_code' => count($routeCodes) === 1 ? $routeCodes[0] : ('HUB-' . implode('+', array_slice($routeCodes, 0, 3))),
                'is_multi_hop' => true,
                'transit_branch_ids' => [],
                'final_destination_branch_id' => null,
            ];
            foreach ($optional as $col => $val) {
                if (in_array($col, $schema, true)) {
                    $payload[$col] = $val;
                }
            }

            $manifest = \Modules\Dispatch\Models\DispatchManifest::create($payload);
            $shipmentColumns = \Illuminate\Support\Facades\Schema::getColumnListing('shipments');
            $tracking = app(\Modules\Tracking\Services\TrackingService::class);

            foreach ($matched as $row) {
                $shipment = $row['shipment'];
                $route = $row['route'];

                \Modules\Dispatch\Models\DispatchManifestItem::create([
                    'dispatch_manifest_id' => $manifest->id,
                    'shipment_id' => $shipment->id,
                    'status' => 'sent',
                ]);

                $updates = [
                    'status' => CourierStatus::IN_TRANSIT,
                    'merchant_status' => CourierStatus::merchantStatus(CourierStatus::IN_TRANSIT),
                    'current_branch_id' => null,
                    'current_sub_branch_id' => null,
                ];

                if (in_array('transfer_status', $shipmentColumns, true)) {
                    $updates['transfer_status'] = CourierStatus::IN_TRANSIT;
                }
                if (in_array('dispatched_at', $shipmentColumns, true)) {
                    $updates['dispatched_at'] = now();
                }
                if (in_array('transfer_route_id', $shipmentColumns, true)) {
                    $updates['transfer_route_id'] = $route['id'] ?? $route['route_id'] ?? null;
                }
                if (in_array('next_hop_branch_id', $shipmentColumns, true)) {
                    $updates['next_hop_branch_id'] = $nextHopBranchId ?: null;
                }
                if (in_array('path_text', $shipmentColumns, true) && !empty($route['path_text'])) {
                    $updates['path_text'] = $route['path_text'];
                }

                $shipment->update($updates);

                $hopName = $route['next_hop_name'] ?? ("branch #{$nextHopBranchId}");
                $tracking->record(
                    $shipment->fresh(),
                    CourierStatus::IN_TRANSIT,
                    "Dispatched on hub manifest {$manifestNumber} toward {$hopName}"
                        . (!empty($route['route_code']) ? " (Route: {$route['route_code']})" : ''),
                    $user->id
                );
            }

            return $manifest->load('items.shipment');
        });

        $manifest->setAttribute('_skipped_ids', $skipped);
        return $manifest;
    }

    private function createManifestForRoute($route, $shipments, array $data, $user): \Modules\Dispatch\Models\DispatchManifest
    {
        $shipments = collect($shipments)->values();
        $firstShipment = $shipments->first();

        $branchModel = \Modules\Branch\Models\Branch::class;
        $routeUsesCoverageIds = $route instanceof \Modules\Rate\Models\BranchTransferRoute
            || isset($route->origin_coverage_id)
            || isset($route->destination_coverage_id);

        // A route model stores coverage IDs; normalize it to the same operational
        // shape used by the dispatch UI before resolving manifest foreign keys.
        if ($route instanceof \Modules\Rate\Models\BranchTransferRoute) {
            $fromBranchHint = (int) ($firstShipment?->current_branch_id
                ?? $firstShipment?->origin_sub_branch_id
                ?? $firstShipment?->origin_branch_id
                ?? 0);
            $fromCoverage = $fromBranchHint > 0
                ? ($this->coverageIdForBranch($fromBranchHint) ?? $fromBranchHint)
                : null;
            $route = (object) $this->formatRouteForDispatch(
                $route,
                $fromCoverage !== null ? (int) $fromCoverage : null,
                $fromBranchHint > 0 ? $fromBranchHint : null,
            );
        }

        $shipmentOriginBranchId = (int) ($firstShipment?->current_branch_id
            ?? $firstShipment?->origin_sub_branch_id
            ?? $firstShipment?->origin_branch_id
            ?? 0);
        $shipmentDestinationBranchId = (int) ($firstShipment?->destination_branch_id ?? 0);

        // Branch-transfer route/lane IDs are coverage_locations IDs. The manifest
        // FK is branches.id, so never write a route path ID directly.
        $originCoverageId = $routeUsesCoverageIds
            ? ($route->origin_coverage_id ?? $route->origin_branch_id ?? null)
            : null;
        $originBranchId = $originCoverageId !== null
            ? (int) ($this->branchIdForCoverage((int) $originCoverageId) ?? 0)
            : (int) ($route->origin_branch_id ?? $shipmentOriginBranchId);
        if (($originBranchId <= 0 || !$branchModel::query()->whereKey($originBranchId)->exists())
            && $shipmentOriginBranchId > 0
            && $branchModel::query()->whereKey($shipmentOriginBranchId)->exists()) {
            $originBranchId = $shipmentOriginBranchId;
        }

        $destinationCoverageId = $routeUsesCoverageIds
            ? ($route->destination_coverage_id ?? $route->destination_branch_id ?? null)
            : null;
        $mappedDestinationBranchId = $destinationCoverageId !== null
            ? (int) ($this->branchIdForCoverage((int) $destinationCoverageId) ?? 0)
            : 0;
        // The shipment's destination branch is authoritative for the final FK.
        // This prevents a coverage ID such as 35 being stored instead of branch 25.
        $destinationBranchId = $shipmentDestinationBranchId > 0
            && $branchModel::query()->whereKey($shipmentDestinationBranchId)->exists()
            ? $shipmentDestinationBranchId
            : ($mappedDestinationBranchId ?: (int) ($route->destination_branch_id ?? 0));

        $rawTransitIds = is_array($route->transit_branch_ids ?? null)
            ? array_values(array_map('intval', $route->transit_branch_ids))
            : [];
        $transitCoverageIds = $routeUsesCoverageIds
            ? (is_array($route->transit_coverage_ids ?? null)
                ? array_values(array_map('intval', $route->transit_coverage_ids))
                : $rawTransitIds)
            : [];
        $transitBranchIds = $routeUsesCoverageIds
            ? array_values(array_filter(array_map(
                fn (int $coverageId): ?int => $this->branchIdForCoverage($coverageId),
                $transitCoverageIds,
            )))
            : array_values(array_filter(
                $rawTransitIds,
                fn (int $branchId): bool => $branchModel::query()->whereKey($branchId)->exists(),
            ));

        // Prefer an explicitly normalized next hop. Otherwise resolve the next
        // coverage node to a real branch ID before writing the FK.
        $firstHopBranchId = 0;
        $explicitNextHop = (int) ($route->next_hop_branch_id ?? 0);
        if ($explicitNextHop > 0 && $branchModel::query()->whereKey($explicitNextHop)->exists()) {
            $firstHopBranchId = $explicitNextHop;
        } elseif ($routeUsesCoverageIds) {
            $nextHopCoverageId = (int) ($route->next_hop_coverage_id
                ?? ($transitCoverageIds[0] ?? $destinationCoverageId ?? 0));
            $firstHopBranchId = (int) ($this->branchIdForCoverage($nextHopCoverageId) ?? 0);
        } else {
            $firstHopBranchId = (int) ($transitBranchIds[0] ?? $destinationBranchId ?? 0);
        }

        if ($originBranchId <= 0 || !$branchModel::query()->whereKey($originBranchId)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'from_branch_id' => ['The transfer origin could not be resolved to a valid branch.'],
            ]);
        }
        if ($firstHopBranchId <= 0 || !$branchModel::query()->whereKey($firstHopBranchId)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'to_branch_id' => ['The transfer next hop could not be resolved to a valid branch.'],
            ]);
        }
        if ($destinationBranchId <= 0 || !$branchModel::query()->whereKey($destinationBranchId)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'final_destination_branch_id' => ['The transfer destination could not be resolved to a valid branch.'],
            ]);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use (
            $route, $shipments, $data, $user, $originBranchId, $firstHopBranchId, $destinationBranchId, $transitBranchIds
        ) {
            $manifestNumber = 'MF-' . now()->format('YmdHis') . '-' . random_int(100, 999);
            $schema = \Illuminate\Support\Facades\Schema::getColumnListing('dispatch_manifests');

            $payload = [
                'manifest_number' => $manifestNumber,
                'from_branch_id' => $originBranchId ?: null,
                'from_sub_branch_id' => null,
                'to_branch_id' => $firstHopBranchId ?: null,
                'to_sub_branch_id' => null,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'seal_number' => $data['seal_number'] ?? null,
                'status' => 'dispatched',
                'created_by' => $user->id,
                'dispatched_at' => now(),
            ];

            // Optional hop / route metadata (only if columns exist on live DB).
            $optional = [
                'driver_phone' => $data['driver_phone'] ?? null,
                'notes' => $data['notes'] ?? null,
                'route_id' => $route->id ?? $route->route_id ?? null,
                'route_code' => $route->route_code ?? 'AUTO',
                'is_multi_hop' => ($route->transit_count ?? count($transitBranchIds)) > 0,
                'transit_branch_ids' => $transitBranchIds,
                'final_destination_branch_id' => $destinationBranchId ?: null,
            ];
            foreach ($optional as $col => $val) {
                if (in_array($col, $schema, true)) {
                    $payload[$col] = $val;
                }
            }

            $manifest = \Modules\Dispatch\Models\DispatchManifest::create($payload);

            $shipmentColumns = \Illuminate\Support\Facades\Schema::getColumnListing('shipments');
            $tracking = app(\Modules\Tracking\Services\TrackingService::class);

            foreach ($shipments as $shipment) {
                $this->progress->assertNotInActiveManifest($shipment);
                $this->progress->assertNextHopMatches(
                    $shipment,
                    (int) $firstHopBranchId,
                    (int) ($shipment->current_branch_id ?: $originBranchId ?: 0) ?: null
                );

                \Modules\Dispatch\Models\DispatchManifestItem::create([
                    'dispatch_manifest_id' => $manifest->id,
                    'shipment_id' => $shipment->id,
                    'status' => 'sent',
                ]);

                $updates = [
                    'status' => CourierStatus::IN_TRANSIT,
                    'merchant_status' => CourierStatus::merchantStatus(CourierStatus::IN_TRANSIT),
                    'current_branch_id' => null,
                    'current_sub_branch_id' => null,
                ];

                if (in_array('transfer_status', $shipmentColumns, true)) {
                    $updates['transfer_status'] = CourierStatus::IN_TRANSIT;
                }
                if (in_array('dispatched_at', $shipmentColumns, true)) {
                    $updates['dispatched_at'] = now();
                }
                if (in_array('transfer_route_id', $shipmentColumns, true)) {
                    $updates['transfer_route_id'] = $route->id ?? $route->route_id ?? $shipment->transfer_route_id ?? null;
                }
                if (in_array('next_hop_branch_id', $shipmentColumns, true)) {
                    $updates['next_hop_branch_id'] = $firstHopBranchId ?: null;
                }
                if (in_array('transfer_leg_index', $shipmentColumns, true) && isset($route->transfer_leg_index)) {
                    $updates['transfer_leg_index'] = (int) $route->transfer_leg_index;
                }
                if (in_array('path_text', $shipmentColumns, true) && !empty($route->path_text)) {
                    $updates['path_text'] = $route->path_text;
                }

                $shipment->update($updates);

                $hopLabel = $firstHopBranchId
                    ? "next hop branch #{$firstHopBranchId}"
                    : 'destination';
                $tracking->record(
                    $shipment->fresh(),
                    CourierStatus::IN_TRANSIT,
                    "Dispatched on manifest {$manifestNumber} toward {$hopLabel}"
                        . (!empty($route->route_code) ? " (Route: {$route->route_code})" : ''),
                    $user->id
                );
            }

            return $manifest->load('items.shipment');
        });
    }

    /**
     * Receive transfer at this branch
    /**
     * Receive transfer at this branch
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
            CourierStatus::RECEIVED_AT_TRANSIT_HUB,
        ], true)) {
            return ApiResponse::error('Only an in-transit transfer can be received.', 403);
        }

        $destinationId = (int) ($shipment->destination_branch_id ?? 0);
        $isFinalDestination = $destinationId === $branchId;
        $isNextHop = $this->isExpectedNextHop($shipment, $branchId);

        if (!$isFinalDestination && !$isNextHop) {
            return ApiResponse::error(
                'This shipment is not expected at your branch (not next hop or final destination).',
                403
            );
        }

        try {
            if ($isFinalDestination) {
                $result = $this->service->receiveAtDestination($shipment, $user->id);
                return ApiResponse::success($result, 'Transfer received and queued for last-mile delivery.');
            }

            $result = $this->receiveAtTransitAndSort($shipment, $branchId, $user->id);
            return ApiResponse::success($result, 'Transfer received at transit hub and sorted for next hop.');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Receive transfer at a transit hub (intermediate branch) and optionally re-dispatch to next hop.
     */
    public function receiveAtTransitHub(Request $request, Shipment $shipment)
    {
        $user = $request->user();
        $branchId = $this->resolveReceivingBranchId($user, $request);

        if ($branchId <= 0) {
            return ApiResponse::error('Branch context is required.', 422);
        }

        if ((int) ($shipment->destination_branch_id ?? 0) === $branchId) {
            // Final destination should use the destination receive path.
            return $this->receive($request, $shipment);
        }

        if (!in_array($shipment->status, [
            CourierStatus::IN_TRANSIT,
            CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
            CourierStatus::RECEIVED_AT_TRANSIT_HUB,
        ], true)) {
            return ApiResponse::error('This shipment is not in transit for transit-hub receive.', 403);
        }

        if (!$this->isExpectedNextHop($shipment, $branchId)
            && (int) ($shipment->current_branch_id ?? 0) !== $branchId) {
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
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'driver_name' => ['nullable', 'string', 'max:100'],
            'driver_phone' => ['nullable', 'string', 'max:20'],
            'seal_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $receivedIds = $data['received_shipment_ids'] ?? [$shipment->id];
        $reDispatch = (bool) ($data['re_dispatch'] ?? false);

        try {
            $result = \Illuminate\Support\Facades\DB::transaction(function () use ($receivedIds, $reDispatch, $data, $user, $branchId) {
                $receivedShipments = [];
                $reDispatchedManifests = [];

                foreach ($receivedIds as $id) {
                    $s = Shipment::query()->lockForUpdate()->findOrFail($id);

                    if ((int) ($s->destination_branch_id ?? 0) === $branchId) {
                        continue;
                    }

                    if (!$this->isExpectedNextHop($s, $branchId)
                        && (int) ($s->current_branch_id ?? 0) !== $branchId
                        && $s->status !== CourierStatus::RECEIVED_AT_TRANSIT_HUB) {
                        continue;
                    }

                    $s->update([
                        'status' => CourierStatus::RECEIVED_AT_TRANSIT_HUB,
                        'merchant_status' => CourierStatus::merchantStatus(CourierStatus::RECEIVED_AT_TRANSIT_HUB),
                        'current_branch_id' => $branchId,
                        'current_sub_branch_id' => null,
                    ]);

                    $this->markManifestItemReceived($s->id, $branchId, $user->id);

                    $this->progress->applyProgressToShipment($s->fresh(), $branchId);

                    app(\Modules\Tracking\Services\TrackingService::class)->record(
                        $s->fresh(),
                        CourierStatus::RECEIVED_AT_TRANSIT_HUB,
                        "Received at transit hub (branch #{$branchId}).",
                        $user->id
                    );

                    // Auto-sort so the parcel appears on this hub's outbound board.
                    $sorted = app(\Modules\Shipment\Services\ShipmentSortingService::class)
                        ->sort($s->fresh(), $user->id);

                    $receivedShipments[] = $sorted;

                    if ($reDispatch && $sorted->status === CourierStatus::SORTED_FOR_TRANSFER) {
                        $nextRoute = null;
                        if (!empty($data['next_hop_route_id'])) {
                            $nextRoute = \Modules\Rate\Models\BranchTransferRoute::find($data['next_hop_route_id']);
                            if ($nextRoute) {
                                $nextRoute = $this->formatRouteForDispatch($nextRoute);
                            }
                        }
                        if (!$nextRoute) {
                            $nextRoute = $this->findNextRouteForShipment($sorted, $branchId);
                        }
                        if ($nextRoute) {
                            $manifest = $this->createManifestForRoute(
                                (object) $nextRoute,
                                collect([$sorted]),
                                $data,
                                $user
                            );
                            $reDispatchedManifests[] = $manifest;
                        }
                    }
                }

                return [
                    'received' => $receivedShipments,
                    're_dispatched_manifests' => $reDispatchedManifests,
                ];
            });

            return ApiResponse::success(
                $result,
                'Transfer received at transit hub' . ($reDispatch ? ' and re-dispatched' : ' and sorted for next hop')
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Receive at transit hub then sort for the next outbound leg.
     */
    private function receiveAtTransitAndSort(Shipment $shipment, int $branchId, int $actorId): Shipment
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($shipment, $branchId, $actorId) {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            $shipment->update([
                'status' => CourierStatus::RECEIVED_AT_TRANSIT_HUB,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::RECEIVED_AT_TRANSIT_HUB),
                'current_branch_id' => $branchId,
                'current_sub_branch_id' => null,
            ]);

            $this->markManifestItemReceived($shipment->id, $branchId, $actorId);

            // Recalculate next hop from assigned route at this transit hub.
            $this->progress->applyProgressToShipment($shipment->fresh(), $branchId);

            app(\Modules\Tracking\Services\TrackingService::class)->record(
                $shipment->fresh(),
                CourierStatus::RECEIVED_AT_TRANSIT_HUB,
                "Received at transit hub (branch #{$branchId}).",
                $actorId
            );

            return app(\Modules\Shipment\Services\ShipmentSortingService::class)
                ->sort($shipment->fresh(), $actorId);
        });
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
                    ->whereIn('status', ['dispatched', 'in_transit']);
            })
            ->exists();
    }

    private function markManifestItemReceived(int $shipmentId, int $branchId, int $actorId): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('dispatch_manifest_items')) {
            return;
        }

        $item = \Modules\Dispatch\Models\DispatchManifestItem::query()
            ->where('shipment_id', $shipmentId)
            ->whereIn('status', ['sent', 'dispatched', 'in_transit'])
            ->whereHas('manifest', function ($q) use ($branchId) {
                $q->where('to_branch_id', $branchId)
                    ->whereIn('status', ['dispatched', 'in_transit']);
            })
            ->latest('id')
            ->first();

        if (!$item) {
            return;
        }

        $item->update(['status' => 'received']);

        $open = \Modules\Dispatch\Models\DispatchManifestItem::query()
            ->where('dispatch_manifest_id', $item->dispatch_manifest_id)
            ->whereIn('status', ['sent', 'dispatched', 'in_transit'])
            ->exists();

        if (!$open) {
            $item->manifest?->update([
                'status' => 'received',
                'received_by' => $actorId,
                'received_at' => now(),
            ]);
        }
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
     * Find the next route for a shipment at a transit hub.

     */
    private function findNextRouteForShipment(Shipment $shipment, int $currentBranchId): ?array
    {
        $destNode = $shipment->destination_sub_branch_id ?? $shipment->destination_branch_id;
        $serviceType = $shipment->service_type ?? 'standard';

        if (!$destNode) {
            return null;
        }

        // If the current branch is the destination, no next route needed
        if ((int) $destNode === $currentBranchId) {
            return null;
        }

        // Try to find configured route from current branch to destination
        return $this->findMatchingRoute($currentBranchId, $destNode, $serviceType);
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
     * Get branch scope for current user
     */
    private function getBranchScope($user): int
    {
        if ($user->isSuperAdmin() || $user->hasRole('main_admin')) {
            return 0; // No scope restriction
        }

        return (int) ($user->branch_id ?? 0);
    }
}
