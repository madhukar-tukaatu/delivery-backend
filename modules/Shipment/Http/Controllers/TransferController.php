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
            // INBOUND: transfers coming TO this branch
            // Status: in_transit (on the way) or received_at_destination_branch (arrived)
            $query->whereIn('status', [
                CourierStatus::IN_TRANSIT,
                CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
                CourierStatus::RECEIVED_AT_TRANSIT_HUB,
                CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
            ]);

            // Cross-branch transfers only (never same-branch local deliveries).
            $query->whereColumn('origin_branch_id', '!=', 'destination_branch_id');

            // Only filter by branch if branch scope is not admin
            if ($branchId !== 0) {
                $query->where(function ($q) use ($branchId) {
                    // Received at destination OR currently at this branch as intermediate hub
                    $q->where('destination_branch_id', $branchId)
                        ->orWhere('current_branch_id', $branchId);
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
     * Find a configured transfer route matching origin->destination.
     */
    private function findMatchingRoute(int $originBranchId, int $destinationBranchId, string $serviceType): ?array
    {
        $routes = \Modules\Rate\Models\BranchTransferRoute::query()
            ->where('service_type', $serviceType)
            ->where('is_active', true)
            ->with(['routeLanes.lane.fromBranch', 'routeLanes.lane.toBranch'])
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($routes as $route) {
            $path = $route->getPathBranchIds();
            if (empty($path)) continue;

            if ((int) $path[0] === $originBranchId && (int) end($path) === $destinationBranchId) {
                return $this->formatRouteForDispatch($route);
            }
        }

        return null;
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
                    ->orWhere('current_branch_id', $branchId);
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
                    ->orWhere('current_branch_id', $branchId);
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
        $serviceType = $request->string('service_type')->toString() ?: 'standard';

        // For admin users, allow specifying branch_id via query parameter
        if ($branchId === 0) {
            $branchId = $request->integer('branch_id');
        }

        // Get all active routes for the service type
        $routesQuery = \Modules\Rate\Models\BranchTransferRoute::query()
            ->where('service_type', $serviceType)
            ->where('is_active', true)
            ->with(['routeLanes.lane.fromBranch', 'routeLanes.lane.toBranch'])
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->orderBy('id');

        // If branch_id is specified, filter routes originating from that branch
        if ($branchId !== 0) {
            $routes = $routesQuery->get();
            
            $formattedRoutes = [];
            $routesByDestination = [];

            foreach ($routes as $route) {
                $path = $route->getPathBranchIds();
                if (empty($path)) {
                    continue;
                }

                // Only include routes that originate from the current branch
                if ((int) $path[0] !== $branchId) {
                    continue;
                }

                $destinationBranchId = (int) end($path);
                $destinationBranch = \Modules\Branch\Models\Branch::find($destinationBranchId);

                $formatted = $this->formatRouteForDispatch($route);
                $formattedRoutes[] = $formatted;

                // Group by destination for easy UI consumption
                $destKey = $destinationBranchId;
                if (!isset($routesByDestination[$destKey])) {
                    $routesByDestination[$destKey] = [
                        'destination_branch_id' => $destinationBranchId,
                        'destination_branch_name' => $destinationBranch?->name ?? 'Unknown',
                        'destination_branch_code' => $destinationBranch?->code ?? '',
                        'routes' => [],
                    ];
                }
                $routesByDestination[$destKey]['routes'][] = $formatted;
            }

            return ApiResponse::success([
                'routes' => array_values($formattedRoutes),
                'routes_by_destination' => array_values($routesByDestination),
                'branch_id' => $branchId,
                'service_type' => $serviceType,
            ]);
        }

        // For admin without branch_id specified, return all routes grouped by origin branch
        $routes = $routesQuery->get();
        
        $routesByOrigin = [];
        
        foreach ($routes as $route) {
            $path = $route->getPathBranchIds();
            if (empty($path)) {
                continue;
            }

            $originBranchId = (int) $path[0];
            $originBranch = \Modules\Branch\Models\Branch::find($originBranchId);

            $formatted = $this->formatRouteForDispatch($route);

            $originKey = $originBranchId;
            if (!isset($routesByOrigin[$originKey])) {
                $routesByOrigin[$originKey] = [
                    'origin_branch_id' => $originBranchId,
                    'origin_branch_name' => $originBranch?->name ?? 'Unknown',
                    'origin_branch_code' => $originBranch?->code ?? '',
                    'routes' => [],
                ];
            }
            $routesByOrigin[$originKey]['routes'][] = $formatted;
        }

        return ApiResponse::success([
            'routes' => array_values($routesByOrigin), // Flattened for backward compatibility
            'routes_by_origin' => array_values($routesByOrigin),
            'branch_id' => null,
            'service_type' => $serviceType,
        ]);
    }

    /**
     * Format a route for the dispatch UI.
     */
    private function formatRouteForDispatch(\Modules\Rate\Models\BranchTransferRoute $route): array
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

        return [
            'id' => (int) $route->id,
            'route_id' => (int) $route->id,
            'route_code' => (string) $route->route_code,
            'route_name' => (string) $route->name,
            'service_type' => (string) $route->service_type,
            'origin_branch_id' => $originBranchId,
            'destination_branch_id' => $destinationBranchId,
            'transit_branch_ids' => array_column($transitBranches, 'branch_id'),
            'transit_branches' => $transitBranches,
            'transit_count' => count($transitBranches),
            'transfer_count' => max(1, $lanes->count()),
            'total_distance_km' => (float) $route->getTotalDistanceKm(),
            'total_estimated_hours' => (int) round($route->getTotalEstimatedHours()),
            'base_rate' => (float) ($route->base_rate ?? 0),
            'currency' => (string) ($route->currency ?? 'NPR'),
            'path' => $path,
            'path_text' => implode(' → ', array_column($path, 'branch_name')),
            'is_default' => (bool) $route->is_default,
            'priority' => (int) $route->priority,
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

        $manifests = [];
        $dispatchedCount = 0;

        if ($route) {
            // Single route specified - create one manifest for all shipments
            $manifest = $this->createManifestForRoute($route, $shipments, $data, $user);
            $manifests[] = $manifest;
            $dispatchedCount = $manifest->items->count();
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
    private function createManifestForRoute($route, $shipments, array $data, $user): \Modules\Dispatch\Models\DispatchManifest
    {
        $originBranchId = $route->origin_branch_id ?? $branchId;
        $destinationBranchId = $route->destination_branch_id ?? 0;
        $transitBranchIds = $route->transit_branch_ids ?? [];

        // For multi-hop routes, the first leg goes to the first transit branch
        // or directly to destination if no transit
        $firstHopBranchId = $transitBranchIds[0] ?? $destinationBranchId;

        return \Illuminate\Support\Facades\DB::transaction(function () use ($route, $shipments, $data, $user, $originBranchId, $firstHopBranchId, $destinationBranchId, $transitBranchIds) {
            $manifestNumber = 'MF-' . now()->format('YmdHis') . '-' . random_int(100, 999);

            $manifest = \Modules\Dispatch\Models\DispatchManifest::create([
                'manifest_number' => $manifestNumber,
                'from_branch_id' => $originBranchId,
                'from_sub_branch_id' => null,
                'to_branch_id' => $firstHopBranchId,
                'to_sub_branch_id' => null,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'driver_phone' => $data['driver_phone'] ?? null,
                'seal_number' => $data['seal_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'dispatched',
                'created_by' => $user->id,
                'dispatched_at' => now(),
                // Store route info in notes or add columns later
                'route_id' => $route->id ?? null,
                'route_code' => $route->route_code ?? 'AUTO',
                'is_multi_hop' => ($route->transit_count ?? 0) > 0,
                'transit_branch_ids' => $transitBranchIds,
                'final_destination_branch_id' => $destinationBranchId,
            ]);

            foreach ($shipments as $shipment) {
                \Modules\Dispatch\Models\DispatchManifestItem::create([
                    'dispatch_manifest_id' => $manifest->id,
                    'shipment_id' => $shipment->id,
                    'status' => 'sent',
                ]);

                // Update shipment status to IN_TRANSIT
                $shipment->update([
                    'status' => \App\Support\CourierStatus::IN_TRANSIT,
                    'merchant_status' => \App\Support\CourierStatus::merchantStatus(\App\Support\CourierStatus::IN_TRANSIT),
                    'current_branch_id' => null, // Between branches
                    'current_sub_branch_id' => null,
                ]);

                // Record tracking event
                \Modules\Tracking\Services\TrackingService::record(
                    $shipment->fresh(),
                    \App\Support\CourierStatus::IN_TRANSIT,
                    "Dispatched on manifest {$manifestNumber}" . ($route->route_code ? " (Route: {$route->route_code})" : ''),
                    $user->id
                );
            }

            return $manifest->load('items.shipment');
        });
    }

    /**
     * Receive transfer at this branch
     */
    public function receive(Request $request, Shipment $shipment)
    {
        $user = $request->user();
        $branchId = $this->getBranchScope($user);

        // Validate: this must be a transfer destined for this branch and in transit
        if (
            (int) ($shipment->destination_branch_id ?? 0) !== $branchId ||
            !in_array($shipment->status, [
                CourierStatus::IN_TRANSIT,
                CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
            ])
        ) {
            return ApiResponse::error(
                'This shipment is not destined for your branch or not in transit.',
                403
            );
        }

        try {
            $result = $this->service->receiveAtDestination($shipment, $user->id);
            return ApiResponse::success($result, 'Transfer received and queued for last-mile delivery.');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Receive transfer at a transit hub (intermediate branch) and optionally re-dispatch to next hop.
     * 
     * This handles multi-hop routes where a shipment arrives at an intermediate hub
     * and needs to be forwarded to the next leg of the journey.
     */
    public function receiveAtTransitHub(Request $request, Shipment $shipment)
    {
        $user = $request->user();
        $branchId = $this->getBranchScope($user);

        // Validate: this must be a transfer currently at this branch as a transit hub
        if (
            (int) ($shipment->current_branch_id ?? 0) !== $branchId ||
            !in_array($shipment->status, [
                CourierStatus::IN_TRANSIT,
                CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
                CourierStatus::RECEIVED_AT_TRANSIT_HUB,
            ])
        ) {
            return ApiResponse::error(
                'This shipment is not at your branch as a transit hub or not in transit.',
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
        $reDispatch = $data['re_dispatch'] ?? false;

        try {
            $result = \Illuminate\Support\Facades\DB::transaction(function () use ($shipment, $receivedIds, $reDispatch, $data, $user, $branchId) {
                $receivedShipments = [];
                $reDispatchedManifests = [];

                foreach ($receivedIds as $id) {
                    $s = Shipment::query()->lockForUpdate()->findOrFail($id);
                    
                    // Verify this shipment is at this branch as transit
                    if ((int) ($s->current_branch_id ?? 0) !== $branchId) {
                        continue;
                    }

                    // Mark as received at transit hub
                    $oldStatus = $s->status;
                    $s->update([
                        'status' => CourierStatus::RECEIVED_AT_TRANSIT_HUB,
                        'merchant_status' => CourierStatus::merchantStatus(CourierStatus::RECEIVED_AT_TRANSIT_HUB),
                        'current_branch_id' => $branchId,
                        'current_sub_branch_id' => null,
                    ]);

                    \Modules\Tracking\Services\TrackingService::record(
                        $s->fresh(),
                        CourierStatus::RECEIVED_AT_TRANSIT_HUB,
                        "Received at transit hub: " . ($s->currentBranch?->name ?? "Branch #{$branchId}"),
                        $user->id
                    );

                    $receivedShipments[] = $s->fresh();

                    // If re-dispatch is requested, create manifest for next hop
                    if ($reDispatch) {
                        $nextRouteId = $data['next_hop_route_id'];
                        $nextRoute = $nextRouteId ? \Modules\Rate\Models\BranchTransferRoute::find($nextRouteId) : null;

                        if (!$nextRoute) {
                            // Try to auto-find the next route based on the shipment's route steps
                            $nextRoute = $this->findNextRouteForShipment($s, $branchId);
                        }

                        if ($nextRoute) {
                            $manifest = $this->createManifestForRoute(
                                (object) $nextRoute,
                                collect([$s]),
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

            return ApiResponse::success($result, 'Transfer received at transit hub' . ($reDispatch ? ' and re-dispatched' : ''));
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
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
        $query->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
            ->where(function ($q) {
                $q->whereIn('status', [
                    CourierStatus::SORTED_FOR_TRANSFER,
                    CourierStatus::RECEIVED_AT_ORIGIN_BRANCH,
                    CourierStatus::PICKED_UP,
                ])->orWhere(function ($q2) {
                    // Mis-sorted cross-branch parcel still at its origin.
                    $q2->where('status', CourierStatus::SORTED_FOR_DELIVERY)
                        ->where(function ($q3) {
                            $q3->whereNull('current_branch_id')
                                ->orWhereColumn('current_branch_id', '=', 'origin_branch_id');
                        });
                });
            });

        if ($branchId !== 0) {
            $query->where(function ($q) use ($branchId) {
                $q->where('origin_branch_id', $branchId)
                    ->orWhere('current_branch_id', $branchId);
            });
        }
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