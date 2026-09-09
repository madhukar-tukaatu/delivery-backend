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

        // Filter by transit branches if provided
        if ($request->filled('has_transit')) {
            $hasTransit = $request->boolean('has_transit');
            if ($hasTransit) {
                // Only routes with transits (non-empty array)
                $query->whereRaw('JSON_LENGTH(transit_branch_ids) > 0');
            } else {
                // Only direct routes (empty array)
                $query->whereRaw('JSON_LENGTH(transit_branch_ids) = 0');
            }
        }

        // Filter by specific transit branch
        if ($request->filled('transit_branch_id')) {
            $transitId = (int) $request->input('transit_branch_id');
            $query->whereRaw("JSON_CONTAINS(transit_branch_ids, ?, '$[*]')", [$transitId]);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $paginator = $query
            ->orderBy('priority')
            ->orderBy('created_at', 'desc')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        // Load relationships after pagination (include coordinates for the map)
        $paginator->getCollection()->each(function ($route) {
            $route->load(
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
