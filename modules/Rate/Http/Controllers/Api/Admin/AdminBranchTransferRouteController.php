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
        $query = BranchTransferRoute::query()
            ->with(['originBranch:id,name,code', 'destinationBranch:id,name,code']);

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('route_code', 'like', "%{$search}%");
            });
        }

        foreach (['origin_branch_id', 'destination_branch_id', 'service_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $paginator = $query
            ->orderBy('origin_branch_id')
            ->orderBy('priority')
            ->orderBy('destination_branch_id')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

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
