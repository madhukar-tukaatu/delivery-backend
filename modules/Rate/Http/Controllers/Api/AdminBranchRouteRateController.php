<?php

namespace Modules\Rate\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Rate\Http\Requests\StoreBranchRouteRateRequest;
use Modules\Rate\Models\BranchRouteRate;

class AdminBranchRouteRateController
{
    public function index(Request $request): JsonResponse
    {
        $rates = BranchRouteRate::query()
            ->with([
                'originBranch:id,name,code,type,status',
                'destinationBranch:id,name,code,type,status',
            ])
            ->when(
                $request->filled('origin_branch_id'),
                fn ($query) => $query->where(
                    'origin_branch_id',
                    $request->integer('origin_branch_id')
                )
            )
            ->when(
                $request->filled('destination_branch_id'),
                fn ($query) => $query->where(
                    'destination_branch_id',
                    $request->integer('destination_branch_id')
                )
            )
            ->when(
                $request->filled('is_active'),
                fn ($query) => $query->where(
                    'is_active',
                    $request->boolean('is_active')
                )
            )
            ->latest('id')
            ->paginate(
                min(
                    max($request->integer('per_page', 20), 1),
                    100
                )
            );

        return response()->json([
            'success' => true,
            'data' => $rates,
        ]);
    }

    public function store(
        StoreBranchRouteRateRequest $request
    ): JsonResponse {
        $data = $request->validated();

        unset($data['route_unique_check']);

        $data['created_by'] = auth()->id();
        $data['updated_by'] = auth()->id();

        $rate = BranchRouteRate::query()->create($data);

        return response()->json([
            'success' => true,
            'message' => 'Branch route rate created.',
            'data' => $rate->load([
                'originBranch:id,name,code',
                'destinationBranch:id,name,code',
            ]),
        ], 201);
    }

    public function show(
        BranchRouteRate $routeRate
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => $routeRate->load([
                'originBranch:id,name,code',
                'destinationBranch:id,name,code',
            ]),
        ]);
    }

    public function update(
        StoreBranchRouteRateRequest $request,
        BranchRouteRate $routeRate
    ): JsonResponse {
        $data = $request->validated();

        unset($data['route_unique_check']);

        $data['updated_by'] = auth()->id();

        $routeRate->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Branch route rate updated.',
            'data' => $routeRate->refresh()->load([
                'originBranch:id,name,code',
                'destinationBranch:id,name,code',
            ]),
        ]);
    }

    public function destroy(
        BranchRouteRate $routeRate
    ): JsonResponse {
        $routeRate->delete();

        return response()->json([
            'success' => true,
            'message' => 'Branch route rate deleted.',
        ]);
    }
}