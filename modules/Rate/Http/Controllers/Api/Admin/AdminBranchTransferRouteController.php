<?php

declare(strict_types=1);

namespace Modules\Rate\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function destroy(BranchTransferRoute $transferRoute): JsonResponse
    {
        $transferRoute->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'Transfer route disabled successfully.',
        ]);
    }
}
