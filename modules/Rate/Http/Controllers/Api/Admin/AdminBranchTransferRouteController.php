<?php

declare(strict_types=1);

namespace Modules\Rate\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Rate\Http\Requests\StoreBranchTransferRouteRequest;
use Modules\Rate\Http\Requests\UpdateBranchTransferRouteRequest;
use Modules\Rate\Models\BranchTransferRoute;
use Modules\Rate\Services\BranchTransferRouteService;
use Modules\Rate\Services\ConfiguredTransferRouteService;

final class AdminBranchTransferRouteController extends Controller
{
    public function __construct(
        private readonly BranchTransferRouteService $routeService,
        private readonly ConfiguredTransferRouteService $resolver
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = BranchTransferRoute::query();

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('route_code', 'like', "%{$search}%");
            });
        }

        // Filter by service type if provided
        if ($request->filled('service_type')) {
            $query->where('service_type', $request->input('service_type'));
        }

        // Filter by whether the route has transit branches (2+ lanes) or is direct
        // (1 lane). A route with more than one ordered lane has transit branches.
        if ($request->filled('has_transit')) {
            $hasTransit = $request->boolean('has_transit');
            $subQuery = \Modules\Rate\Models\BranchTransferRouteLane::query()
                ->select('branch_transfer_route_id')
                ->groupBy('branch_transfer_route_id')
                ->havingRaw('COUNT(*) > 1');

            if ($hasTransit) {
                $query->whereIn('id', $subQuery);
            } else {
                $query->whereNotIn('id', $subQuery);
            }
        }

        // Filter by a specific transit branch: a route passes through it if any of
        // its lanes ends there but it is not the final destination.
        if ($request->filled('transit_branch_id')) {
            $transitId = (int) $request->input('transit_branch_id');
            $query->whereHas('routeLanes.lane', function ($q) use ($transitId): void {
                $q->where('from_branch_id', $transitId)
                  ->orWhere('to_branch_id', $transitId);
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $paginator = $query
            ->orderBy('priority')
            ->orderBy('created_at', 'desc')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        // Load ordered lanes with branch coordinates for the map + legacy anchor lane.
        $paginator->getCollection()->each(function ($route) {
            $route->load(
                'routeLanes.lane.fromBranch:id,name,code,latitude,longitude',
                'routeLanes.lane.toBranch:id,name,code,latitude,longitude',
                'lane.fromBranch:id,name,code,latitude,longitude',
                'lane.toBranch:id,name,code,latitude,longitude'
            );
        });

        $paginator->getCollection()->transform(
            fn (BranchTransferRoute $route): array => $this->resolver->formatRoute($route)
        );

        return response()->json(['success' => true, 'data' => $paginator]);
    }

    public function store(StoreBranchTransferRouteRequest $request): JsonResponse
    {
        $route = $this->routeService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Transfer route created successfully.',
            'data'    => $this->resolver->formatRoute($route),
        ], 201);
    }

    /**
     * Preview a route (ordered lanes + checkpoints) without persisting it.
     * Validates connectivity and returns the derived path/distance/ETA.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lane_ids'   => ['required', 'array', 'min:1'],
            'lane_ids.*' => ['integer', 'exists:branch_transfer_lanes,id'],
            'service_type' => ['nullable', \Illuminate\Validation\Rule::in(['standard', 'express', 'same_day', 'flight'])],
        ]);

        $serviceType = strtolower((string) ($validated['service_type'] ?? 'standard'));

        $lanes = \Modules\Rate\Models\BranchTransferLane::query()
            ->with(['fromBranch', 'toBranch'])
            ->whereIn('id', $validated['lane_ids'])
            ->get()
            ->keyBy('id');

        $ordered = [];
        foreach ($validated['lane_ids'] as $id) {
            $lane = $lanes->get($id);
            if (!$lane) {
                continue;
            }
            $ordered[] = $lane;
        }

        // Validate connectivity.
        $errors = [];
        for ($i = 0; $i < count($ordered); $i++) {
            $lane = $ordered[$i];
            if (strtolower((string) $lane->service_type) !== $serviceType) {
                $errors[] = sprintf(
                    'Lane %s → %s is %s, not %s.',
                    $lane->fromBranch?->name ?? $lane->from_branch_id,
                    $lane->toBranch?->name ?? $lane->to_branch_id,
                    strtoupper((string) $lane->service_type),
                    strtoupper($serviceType)
                );
            }
            if ($i > 0 && (int) $ordered[$i - 1]->to_branch_id !== (int) $lane->from_branch_id) {
                $errors[] = 'Lanes are not connected end-to-end.';
            }
        }

        $path = [];
        if ($ordered !== []) {
            $first = $ordered[0];
            $path[] = [
                'id'        => (int) $first->from_branch_id,
                'name'      => $first->fromBranch?->name,
                'latitude'  => $first->fromBranch?->latitude,
                'longitude' => $first->fromBranch?->longitude,
            ];
            foreach ($ordered as $lane) {
                $path[] = [
                    'id'        => (int) $lane->to_branch_id,
                    'name'      => $lane->toBranch?->name,
                    'latitude'  => $lane->toBranch?->latitude,
                    'longitude' => $lane->toBranch?->longitude,
                ];
            }
        }

        return response()->json([
            'success' => $errors === [],
            'data'    => [
                'valid'                 => $errors === [],
                'errors'                => $errors,
                'origin_branch_id'      => $ordered ? (int) $ordered[0]->from_branch_id : null,
                'destination_branch_id' => $ordered ? (int) $ordered[count($ordered) - 1]->to_branch_id : null,
                'transfer_count'        => count($ordered),
                'transit_count'         => max(0, count($ordered) - 1),
                'total_distance_km'     => array_sum(array_map(static fn ($l) => (float) $l->distance_km, $ordered)),
                'total_estimated_hours' => (int) round(array_sum(array_map(static fn ($l) => (float) $l->estimated_hours, $ordered))),
                'path'                  => $path,
                'path_text'             => implode(' → ', array_filter(array_column($path, 'name'))),
            ],
        ]);
    }

    public function show(BranchTransferRoute $transferRoute): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->resolver->formatRoute($transferRoute),
        ]);
    }

    public function update(
        UpdateBranchTransferRouteRequest $request,
        BranchTransferRoute $transferRoute
    ): JsonResponse {
        $route = $this->routeService->update($transferRoute, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Transfer route updated successfully.',
            'data'    => $this->resolver->formatRoute($route),
        ]);
    }

    public function updateStatus(
        Request $request,
        BranchTransferRoute $transferRoute
    ): JsonResponse {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        $transferRoute->update(['is_active' => (bool) $validated['is_active']]);

        return response()->json([
            'success' => true,
            'message' => $transferRoute->is_active
                ? 'Transfer route activated successfully.'
                : 'Transfer route disabled successfully.',
            'data' => [
                'id'        => (int) $transferRoute->id,
                'is_active' => (bool) $transferRoute->is_active,
            ],
        ]);
    }

    /**
     * Hard delete a transfer route (and its ordered lane rows) when nothing
     * references it. Routes used by shipments, TRs/manifests, transfer
     * batches/trackers or pricing rates are kept: 422 + usage counts so the
     * admin can disable instead (PATCH /{id}/status still toggles).
     */
    public function destroy(BranchTransferRoute $transferRoute): JsonResponse
    {
        $routeId = (int) $transferRoute->id;
        $usage = $this->routeUsage($routeId);

        if (array_sum($usage) > 0) {
            $parts = [];
            $labels = [
                'shipments'        => ['shipment', 'shipments'],
                'transfers'        => ['TR', 'TRs'],
                'transfer_batches' => ['transfer batch', 'transfer batches'],
                'trackers'         => ['transfer tracker', 'transfer trackers'],
                'pricing_rates'    => ['pricing rate', 'pricing rates'],
            ];
            foreach ($labels as $key => [$one, $many]) {
                $n = (int) ($usage[$key] ?? 0);
                if ($n > 0) {
                    $parts[] = $n . ' ' . ($n === 1 ? $one : $many);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Route ' . ($transferRoute->route_code ?: "#{$routeId}")
                    . ' cannot be deleted. Used by ' . implode(' / ', $parts)
                    . ' - disable it instead.',
                'data'    => [
                    'id'          => $routeId,
                    'route_code'  => $transferRoute->route_code,
                    'is_active'   => (bool) $transferRoute->is_active,
                    'usage'       => $usage,
                    'can_disable' => (bool) $transferRoute->is_active,
                ],
            ], 422);
        }

        $code = $transferRoute->route_code;
        $wasDefault = (bool) $transferRoute->is_default;
        $origin = (int) $transferRoute->origin_branch_id;
        $destination = (int) $transferRoute->destination_branch_id;
        $service = (string) $transferRoute->service_type;

        DB::transaction(function () use ($transferRoute, $routeId, $wasDefault, $origin, $destination, $service): void {
            DB::table('branch_transfer_route_lanes')
                ->where('branch_transfer_route_id', $routeId)
                ->delete();

            // forceDelete if the model ever gains SoftDeletes, so the
            // route_code is really freed for reuse.
            if (method_exists($transferRoute, 'forceDelete')) {
                $transferRoute->forceDelete();
            } else {
                $transferRoute->delete();
            }

            // Keep one default for the same origin/destination/service.
            if ($wasDefault) {
                $next = BranchTransferRoute::query()
                    ->where('origin_branch_id', $origin)
                    ->where('destination_branch_id', $destination)
                    ->where('service_type', $service)
                    ->where('is_active', true)
                    ->orderBy('priority')
                    ->orderBy('id')
                    ->first();
                $next?->update(['is_default' => true]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Transfer route ' . ($code ?: "#{$routeId}") . ' deleted.',
            'data'    => ['id' => $routeId, 'deleted' => true],
        ]);
    }

    /**
     * Count every place that references a transfer route id.
     *
     * @return array<string, int>
     */
    private function routeUsage(int $routeId): array
    {
        $count = function (string $table, array $columns) use ($routeId): int {
            $columns = array_values(array_filter(
                $columns,
                fn (string $col): bool => Schema::hasColumn($table, $col),
            ));
            if (! $columns || ! Schema::hasTable($table)) {
                return 0;
            }

            return (int) DB::table($table)
                ->where(function ($q) use ($columns, $routeId): void {
                    foreach ($columns as $col) {
                        $q->orWhere($col, $routeId);
                    }
                })
                ->count();
        };

        return [
            'shipments'        => $count('shipments', ['transfer_route_id', 'route_id']),
            'transfers'        => $count('dispatch_manifests', ['route_id']),
            'transfer_batches' => $count('transfer_batches', ['branch_transfer_route_id'])
                + $count('shipment_transfer_batches', ['transfer_route_id']),
            'trackers'         => $count('transfer_shipment_trackers', ['branch_transfer_route_id']),
            'pricing_rates'    => $count('branch_route_rates', ['branch_transfer_route_id']),
        ];
    }
}
