<?php

declare(strict_types=1);

namespace Modules\Rate\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Rate\Http\Requests\StoreBranchTransferRouteRequest;
use Modules\Rate\Http\Requests\UpdateBranchTransferRouteRequest;
use Modules\Rate\Models\BranchTransferLane;
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
            fn (BranchTransferRoute $route): array => $this->formatRoute($route)
        );

        return response()->json([
            'success' => true,
            'data'    => $paginator,
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origin_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'destination_branch_id' => ['required', 'integer', 'different:origin_branch_id', 'exists:branches,id'],
            'transit_branch_ids' => ['nullable', 'array', 'max:3'],
            'transit_branch_ids.*' => ['required', 'integer', 'distinct', 'exists:branches,id'],
            'service_type' => ['required', 'string', 'in:standard,express,same_day'],
        ]);

        $originBranchId      = (int) $validated['origin_branch_id'];
        $destinationBranchId = (int) $validated['destination_branch_id'];
        $serviceType         = strtolower(trim((string) $validated['service_type']));
        $transitBranchIds    = array_values(array_map('intval', $validated['transit_branch_ids'] ?? []));

        if (in_array($originBranchId, $transitBranchIds, true)) {
            throw ValidationException::withMessages([
                'transit_branch_ids' => ['Origin branch cannot be used as a transit branch.'],
            ]);
        }

        if (in_array($destinationBranchId, $transitBranchIds, true)) {
            throw ValidationException::withMessages([
                'transit_branch_ids' => ['Destination branch cannot be used as a transit branch.'],
            ]);
        }

        $branchPath = array_values([$originBranchId, ...$transitBranchIds, $destinationBranchId]);

        if (count($branchPath) !== count(array_unique($branchPath))) {
            throw ValidationException::withMessages([
                'transit_branch_ids' => ['A transfer route cannot contain the same branch more than once.'],
            ]);
        }

        $laneMappings = [];

        for ($index = 0; $index < count($branchPath) - 1; $index++) {
            $lane = BranchTransferLane::query()
                ->with(['fromBranch:id,name,code', 'toBranch:id,name,code'])
                ->where('from_branch_id', $branchPath[$index])
                ->where('to_branch_id', $branchPath[$index + 1])
                ->where('service_type', $serviceType)
                ->where('is_active', true)
                ->orderBy('priority')
                ->orderBy('id')
                ->first();

            if (!$lane) {
                throw ValidationException::withMessages([
                    'transfer_route' => [
                        sprintf(
                            'No active %s transfer lane exists from branch %d to branch %d.',
                            $serviceType,
                            $branchPath[$index],
                            $branchPath[$index + 1]
                        ),
                    ],
                ]);
            }

            $laneMappings[] = $lane;
        }

        $calculation = $this->resolver->validateAndCalculateLanes(
            $laneMappings,
            $originBranchId,
            $destinationBranchId,
            $serviceType
        );

        return response()->json([
            'success' => true,
            'message' => 'Transfer route validated successfully.',
            'data'    => [
                'origin_branch_id'      => $originBranchId,
                'destination_branch_id' => $destinationBranchId,
                'transit_branch_ids'    => $transitBranchIds,
                'service_type'          => $serviceType,
                'lane_count'            => $calculation['lane_count'],
                'transfer_count'        => $calculation['transfer_count'],
                'transit_count'         => $calculation['transit_count'],
                'total_distance_km'     => $calculation['total_distance_km'],
                'total_estimated_hours' => $calculation['total_estimated_hours'],
                'path'                  => $calculation['path'],
                'path_text'             => $calculation['path_text'],
                'transit_branches'      => $calculation['transit_branches'],
                'lanes'                 => $calculation['lanes'],
            ],
        ]);
    }

    public function store(StoreBranchTransferRouteRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $route = $this->routeService->create($validated);

        $route->load([
            'originBranch',
            'destinationBranch',
            'routeLanes.lane.fromBranch',
            'routeLanes.lane.toBranch',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Transfer route created successfully.',
            'data'    => $this->formatRoute($route),
        ], 201);
    }

    public function show(BranchTransferRoute $transferRoute): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->formatRoute($transferRoute),
        ]);
    }

    public function update(
        UpdateBranchTransferRouteRequest $request,
        BranchTransferRoute $transferRoute
    ): JsonResponse {
        $validated = $request->validated();

        $route = $this->routeService->update($transferRoute, $validated);

        $route->load([
            'originBranch',
            'destinationBranch',
            'routeLanes.lane.fromBranch',
            'routeLanes.lane.toBranch',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Transfer route updated successfully.',
            'data'    => $this->formatRoute($route),
        ]);
    }

    public function updateStatus(
        Request $request,
        BranchTransferRoute $transferRoute
    ): JsonResponse {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

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

    private function formatRoute(BranchTransferRoute $route): array
    {
        $route->loadMissing([
            'originBranch',
            'destinationBranch',
            'routeLanes.lane.fromBranch',
            'routeLanes.lane.toBranch',
        ]);

        $routeLanes = $route->routeLanes->sortBy('sequence_number')->values();

        $path  = [];
        $lanes = [];

        foreach ($routeLanes as $index => $mapping) {
            $lane = $mapping->lane;

            if (!$lane) {
                continue;
            }

            if ($index === 0) {
                $path[] = [
                    'id'   => (int) $lane->from_branch_id,
                    'name' => $lane->fromBranch?->name,
                    'code' => $lane->fromBranch?->code,
                ];
            }

            $path[] = [
                'id'   => (int) $lane->to_branch_id,
                'name' => $lane->toBranch?->name,
                'code' => $lane->toBranch?->code,
            ];

            $lanes[] = [
                'sequence'        => (int) $mapping->sequence_number,
                'lane_id'         => (int) $lane->id,
                'from_branch_id'  => (int) $lane->from_branch_id,
                'from_branch_name' => $lane->fromBranch?->name,
                'to_branch_id'    => (int) $lane->to_branch_id,
                'to_branch_name'  => $lane->toBranch?->name,
                'service_type'    => (string) $lane->service_type,
                'distance_km'     => (float) ($lane->distance_km ?? 0),
                'estimated_hours' => (int) ($lane->estimated_hours ?? 0),
                'transport_mode'  => $lane->transport_mode,
            ];
        }

        $transits = count($path) > 2
            ? array_values(array_map(
                static fn (array $branch, int $i): array => ['sequence' => $i + 1, ...$branch],
                array_slice($path, 1, count($path) - 2),
                array_keys(array_slice($path, 1, count($path) - 2))
            ))
            : [];

        return [
            'id'             => (int) $route->id,
            'route_code'     => $route->route_code,
            'name'           => $route->name,
            'origin_branch'  => [
                'id'   => (int) $route->origin_branch_id,
                'name' => $route->originBranch?->name,
                'code' => $route->originBranch?->code,
            ],
            'destination_branch' => [
                'id'   => (int) $route->destination_branch_id,
                'name' => $route->destinationBranch?->name,
                'code' => $route->destinationBranch?->code,
            ],
            'service_type'          => $route->service_type,
            'transfer_count'        => count($lanes),
            'lane_count'            => count($lanes),
            'transit_count'         => count($transits),
            'total_distance_km'     => (float) ($route->total_distance_km ?? 0),
            'total_estimated_hours' => (int) ($route->total_estimated_hours ?? 0),
            'priority'              => (int) $route->priority,
            'is_default'            => (bool) $route->is_default,
            'is_active'             => (bool) $route->is_active,
            'notes'                 => $route->notes,
            'path'                  => $path,
            'path_text'             => implode(' -> ', array_values(array_filter(array_column($path, 'name')))),
            'transit_branches'      => $transits,
            'lanes'                 => $lanes,
        ];
    }
}
