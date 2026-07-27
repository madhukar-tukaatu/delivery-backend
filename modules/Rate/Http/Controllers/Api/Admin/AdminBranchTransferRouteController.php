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
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = BranchTransferRoute::query()->with([
            'originBranch:id,name,code',
            'destinationBranch:id,name,code',
            'routeLanes.lane.fromBranch:id,name,code',
            'routeLanes.lane.toBranch:id,name,code',
        ]);

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));

            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('route_code', 'like', "%{$search}%");
            });
        }

        foreach ([
            'origin_branch_id',
            'destination_branch_id',
            'service_type',
        ] as $filter) {
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
            fn (BranchTransferRoute $route): array => $this->formatRoute($route)
        );

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    public function store(
        StoreBranchTransferRouteRequest $request
    ): JsonResponse {
        $route = $this->routeService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Transfer route created successfully.',
            'data' => $this->formatRoute($route),
        ], 201);
    }

    public function show(
        BranchTransferRoute $transferRoute
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $this->resolver->resolve(
                (int) $transferRoute->origin_branch_id,
                (int) $transferRoute->destination_branch_id,
                (string) $transferRoute->service_type
            ),
        ]);
    }

    public function update(
        UpdateBranchTransferRouteRequest $request,
        BranchTransferRoute $transferRoute
    ): JsonResponse {
        $route = $this->routeService->update(
            $transferRoute,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Transfer route updated successfully.',
            'data' => $this->formatRoute($route),
        ]);
    }

    public function destroy(
        BranchTransferRoute $transferRoute
    ): JsonResponse {
        $transferRoute->update([
            'is_active' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Transfer route disabled successfully.',
        ]);
    }

    private function formatRoute(BranchTransferRoute $route): array
    {
        $route->loadMissing([
            'originBranch',
            'destinationBranch',
            'routeLanes.lane.fromBranch',
            'routeLanes.lane.toBranch',
        ]);

        $path = [];
        $lanes = [];

        foreach ($route->routeLanes->sortBy('sequence_number')->values() as $index => $mapping) {
            $lane = $mapping->lane;

            if (!$lane) {
                continue;
            }

            if ($index === 0) {
                $path[] = [
                    'id' => (int) $lane->from_branch_id,
                    'name' => $lane->fromBranch?->name,
                    'code' => $lane->fromBranch?->code,
                ];
            }

            $path[] = [
                'id' => (int) $lane->to_branch_id,
                'name' => $lane->toBranch?->name,
                'code' => $lane->toBranch?->code,
            ];

            $lanes[] = [
                'sequence' => (int) $mapping->sequence_number,
                'lane_id' => (int) $lane->id,
                'from_branch_id' => (int) $lane->from_branch_id,
                'from_branch_name' => $lane->fromBranch?->name,
                'to_branch_id' => (int) $lane->to_branch_id,
                'to_branch_name' => $lane->toBranch?->name,
                'distance_km' => (float) ($lane->distance_km ?? 0),
                'estimated_hours' => (int) ($lane->estimated_hours ?? 0),
                'transport_mode' => $lane->transport_mode,
            ];
        }

        $transits = count($path) > 2
            ? array_slice($path, 1, count($path) - 2)
            : [];

        $transits = array_values(array_map(
            static fn (array $branch, int $index): array => [
                'sequence' => $index + 1,
                ...$branch,
            ],
            $transits,
            array_keys($transits)
        ));

        return [
            'id' => (int) $route->id,
            'route_code' => $route->route_code,
            'name' => $route->name,
            'origin_branch' => [
                'id' => (int) $route->origin_branch_id,
                'name' => $route->originBranch?->name,
                'code' => $route->originBranch?->code,
            ],
            'destination_branch' => [
                'id' => (int) $route->destination_branch_id,
                'name' => $route->destinationBranch?->name,
                'code' => $route->destinationBranch?->code,
            ],
            'service_type' => $route->service_type,
            'base_rate' => (float) $route->base_rate,
            'currency' => $route->currency,
            'transfer_count' => (int) $route->transfer_count,
            'lane_count' => (int) $route->transfer_count,
            'transit_count' => (int) $route->transit_count,
            'total_distance_km' => (float) $route->total_distance_km,
            'total_estimated_hours' => (int) $route->total_estimated_hours,
            'priority' => (int) $route->priority,
            'is_default' => (bool) $route->is_default,
            'is_active' => (bool) $route->is_active,
            'notes' => $route->notes,
            'path' => $path,
            'path_text' => implode(' -> ', array_column($path, 'name')),
            'transit_branches' => $transits,
            'lanes' => $lanes,
        ];
    }
}
