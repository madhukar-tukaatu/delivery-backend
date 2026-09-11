<?php

declare(strict_types=1);

namespace Modules\Rate\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Rate\Models\BranchTransferLane;

/**
 * CRUD for branch transfer lanes (direct physical connections between branches).
 *
 * Lanes reference coverage_locations (operational hubs) via from_branch_id /
 * to_branch_id. A lane carries distance/ETA/transport_mode for one direct hop.
 */
final class AdminBranchTransferLaneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BranchTransferLane::query()
            ->with([
                'fromBranch:id,name,code,latitude,longitude',
                'toBranch:id,name,code,latitude,longitude',
            ]);

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($q) use ($search): void {
                $q->whereHas('fromBranch', function ($b) use ($search): void {
                    $b->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                })->orWhereHas('toBranch', function ($b) use ($search): void {
                    $b->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%");
                });
            });
        }

        if ($request->filled('from_branch_id')) {
            $query->where('from_branch_id', $request->integer('from_branch_id'));
        }

        if ($request->filled('to_branch_id')) {
            $query->where('to_branch_id', $request->integer('to_branch_id'));
        }

        if ($request->filled('service_type')) {
            $query->where('service_type', $request->input('service_type'));
        }

        if ($request->has('is_active')
            && $request->input('is_active') !== null
            && $request->input('is_active') !== '') {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $paginator = $query
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 500));

        return response()->json(['success' => true, 'data' => $paginator]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);

        $lane = BranchTransferLane::query()->create($data);

        // Auto-create a direct route ONLY for direct lanes (single-hop, no transits)
        // Routes with transits must be created manually in the routes page.
        try {
            $this->createDirectRoute($lane);
        } catch (\Throwable $e) {
            // Log but don't fail the lane creation if route auto-creation fails
            \Log::warning('Failed to auto-create route for lane ' . $lane->id . ': ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Transfer lane created.',
            'data'    => $this->present($lane),
        ], 201);
    }

    public function show(BranchTransferLane $transferLane): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->present($transferLane),
        ]);
    }

    public function update(Request $request, BranchTransferLane $transferLane): JsonResponse
    {
        $data = $this->validatePayload($request, $transferLane);

        $transferLane->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Transfer lane updated.',
            'data'    => $this->present($transferLane->fresh()),
        ]);
    }

    public function updateStatus(Request $request, BranchTransferLane $transferLane): JsonResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        $transferLane->update(['is_active' => (bool) $validated['is_active']]);

        return response()->json([
            'success' => true,
            'message' => $transferLane->is_active
                ? 'Transfer lane activated.'
                : 'Transfer lane disabled.',
            'data' => [
                'id'        => (int) $transferLane->id,
                'is_active' => (bool) $transferLane->is_active,
            ],
        ]);
    }

    public function destroy(BranchTransferLane $transferLane): JsonResponse
    {
        // Guard: a lane used by a route should not silently break it.
        $usedByRoutes = $transferLane->routes()->exists();

        if ($usedByRoutes) {
            return response()->json([
                'success' => false,
                'message' => 'This lane is used by one or more routes. Remove it from those routes first.',
            ], 422);
        }

        $transferLane->delete();

        return response()->json([
            'success' => true,
            'message' => 'Transfer lane deleted.',
        ]);
    }

    /**
     * Manually create a direct route for a lane if one doesn't already exist.
     * Useful for lanes where auto-creation may have failed or for manual trigger.
     */
    public function createRoute(BranchTransferLane $transferLane): JsonResponse
    {
        if ($transferLane->hasDirectRoute()) {
            return response()->json([
                'success' => false,
                'message' => 'A route already exists for this lane.',
            ], 422);
        }

        try {
            $route = $this->createDirectRoute($transferLane);

            return response()->json([
                'success' => true,
                'message' => 'Route created successfully for this lane.',
                'data'    => [
                    'id'   => (int) $route->id,
                    'name' => $route->name,
                    'code' => $route->route_code,
                ],
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create route: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Helper: create a direct route for a lane.
     * Used by both auto-creation in store() and manual creation via createRoute().
     */
    private function createDirectRoute(BranchTransferLane $lane): BranchTransferRoute
    {
        // Check if route already exists
        if ($lane->hasDirectRoute()) {
            return $lane->getDirectRoute();
        }

        // Use the BranchTransferRouteService to create the route properly
        $service = app(\Modules\Rate\Services\BranchTransferRouteService::class);

        $route = $service->create([
            'lane_ids'      => [$lane->id],
            'service_type'  => $lane->service_type,
            'is_active'     => $lane->is_active,
            'is_default'    => true, // First route for this path is default
            'priority'      => $lane->priority,
        ]);

        return $route;
    }

    /**
     * Validate and normalize the create/update payload. On update, the
     * from/to/service uniqueness ignores the current lane.
     */
    private function validatePayload(Request $request, ?BranchTransferLane $current = null): array
    {
        $uniqueRule = Rule::unique('branch_transfer_lanes')
            ->where(fn ($q) => $q
                ->where('from_branch_id', $request->integer('from_branch_id'))
                ->where('to_branch_id', $request->integer('to_branch_id'))
                ->where('service_type', $request->input('service_type')));

        if ($current !== null) {
            $uniqueRule = $uniqueRule->ignore($current->id);
        }

        $validated = $request->validate([
            'from_branch_id'  => ['required', 'integer', 'different:to_branch_id', 'exists:coverage_locations,id'],
            'to_branch_id'    => ['required', 'integer', 'exists:coverage_locations,id', $uniqueRule],
            'service_type'    => ['required', Rule::in(['standard', 'express', 'same_day', 'flight'])],
            'transport_mode'  => ['nullable', 'string', 'max:50'],
            'distance_km'     => ['nullable', 'numeric', 'min:0'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0'],
            'priority'        => ['nullable', 'integer', 'min:1'],
            'is_active'       => ['nullable', 'boolean'],
        ], [
            'to_branch_id.unique'       => 'A lane for this from/to branch and service already exists.',
            'from_branch_id.different'  => 'From and To branches must be different.',
        ]);

        return [
            'from_branch_id'  => (int) $validated['from_branch_id'],
            'to_branch_id'    => (int) $validated['to_branch_id'],
            'service_type'    => strtolower($validated['service_type']),
            'transport_mode'  => $validated['transport_mode'] ?? 'road',
            'distance_km'     => $validated['distance_km'] ?? 0,
            'estimated_hours' => $validated['estimated_hours'] ?? 1,
            'priority'        => $validated['priority'] ?? 100,
            'is_active'       => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : true,
        ];
    }

    private function present(BranchTransferLane $lane): array
    {
        $lane->loadMissing([
            'fromBranch:id,name,code,latitude,longitude',
            'toBranch:id,name,code,latitude,longitude',
        ]);

        return [
            'id'              => (int) $lane->id,
            'from_branch_id'  => (int) $lane->from_branch_id,
            'to_branch_id'    => (int) $lane->to_branch_id,
            'from_branch'     => $lane->fromBranch,
            'to_branch'       => $lane->toBranch,
            'service_type'    => $lane->service_type,
            'transport_mode'  => $lane->transport_mode,
            'distance_km'     => (float) $lane->distance_km,
            'estimated_hours' => (float) $lane->estimated_hours,
            'priority'        => (int) $lane->priority,
            'is_active'       => (bool) $lane->is_active,
            'variant_name'    => $lane->variant_name,
            'checkpoints'     => $lane->getCheckpoints(),
            'route_exists'    => $lane->hasDirectRoute(),
            'route'           => $lane->hasDirectRoute() ? [
                'id'   => (int) $lane->getDirectRoute()->id,
                'name' => $lane->getDirectRoute()->name,
                'code' => $lane->getDirectRoute()->route_code,
            ] : null,
        ];
    }
}
