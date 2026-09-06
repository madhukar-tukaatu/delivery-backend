<?php

declare(strict_types=1);

namespace Modules\Rate\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Rate\Http\Requests\StoreBranchPricingRouteRequest;
use Modules\Rate\Http\Requests\UpdateBranchPricingRouteRequest;
use Modules\Rate\Models\BranchRouteRate;
use Modules\Rate\Services\BranchPricingRouteService;
use Modules\Rate\Services\ConfiguredTransferRouteService;
use Modules\Branch\Models\Branch;

final class BranchPricingRouteController extends Controller
{
    public function __construct(
        private readonly BranchPricingRouteService $pricingRouteService,
        private readonly ConfiguredTransferRouteService $transferRouteService
    ) {}

    /**
     * GET /admin/branch-pricing-routes
     *
     * List all branch pricing rules with their associated routes.
     */
    public function index(Request $request): JsonResponse
    {
        $query = BranchRouteRate::with([
            'pickupBranch',
            'deliveryBranch',
            'transferRoute',
        ])->active();

        // Filter by pickup branch
        if ($request->has('pickup_branch_id')) {
            $query->where('pickup_branch_id', (int) $request->input('pickup_branch_id'));
        }

        // Filter by delivery branch
        if ($request->has('delivery_branch_id')) {
            $query->where('delivery_branch_id', (int) $request->input('delivery_branch_id'));
        }

        $pricings = $query->get();

        // Group by origin/destination, include all service types
        $grouped = $pricings->groupBy(fn ($p) => "{$p->pickup_branch_id}_{$p->delivery_branch_id}")
            ->map(fn ($group) => $this->formatPricingGroup($group))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $grouped,
            'total' => $grouped->count(),
        ]);
    }

    /**
     * GET /admin/branch-pricing-routes/{id}
     *
     * Show a single branch pricing rule with route details.
     */
    public function show(int $id): JsonResponse
    {
        $pricing = BranchRouteRate::with([
            'pickupBranch',
            'deliveryBranch',
            'transferRoute',
        ])->findOrFail($id);

        // Get all pricing for this route pair
        $allPricings = BranchRouteRate::where('pickup_branch_id', $pricing->pickup_branch_id)
            ->where('delivery_branch_id', $pricing->delivery_branch_id)
            ->with(['transferRoute'])
            ->get();

        // Get route details
        try {
            $routeDetails = $this->transferRouteService->resolve(
                originBranchId: (int) $pricing->pickup_branch_id,
                destinationBranchId: (int) $pricing->delivery_branch_id,
                serviceType: 'standard'
            );
        } catch (\Exception $e) {
            $routeDetails = null;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'primary_pricing' => $this->formatPricingResponse($pricing),
                'all_pricings' => $allPricings->map(fn ($p) => $this->formatPricingResponse($p)),
                'route_details' => $routeDetails,
            ],
        ]);
    }

    /**
     * POST /admin/branch-pricing-routes
     *
     * Create new branch pricing with routes for selected service types.
     *
     * Request body:
     * {
     *   "pickup_branch_id": 1,
     *   "delivery_branch_id": 2,
     *   "service_types": {
     *     "standard": {
     *       "enabled": true,
     *       "base_rate": 500
     *     },
     *     "express": {
     *       "enabled": true,
     *       "base_rate": 750
     *     },
     *     "same_day": {
     *       "enabled": false,
     *       "base_rate": 1500
     *     }
     *   }
     * }
     */
    public function store(StoreBranchPricingRouteRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $pricing = $this->pricingRouteService->createPricingWithRoutes(
            pickupBranchId: (int) $validated['pickup_branch_id'],
            deliveryBranchId: (int) $validated['delivery_branch_id'],
            serviceTypes: $validated['service_types'],
        );

        // Get all created pricings for this route pair
        $allPricings = BranchRouteRate::where('pickup_branch_id', $pricing->pickup_branch_id)
            ->where('delivery_branch_id', $pricing->delivery_branch_id)
            ->with(['transferRoute'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Branch pricing created successfully',
            'data' => [
                'primary_pricing' => $this->formatPricingResponse($pricing),
                'all_pricings' => $allPricings->map(fn ($p) => $this->formatPricingResponse($p)),
            ],
        ], 201);
    }

    /**
     * PUT /admin/branch-pricing-routes/{id}
     *
     * Update branch pricing and routes.
     */
    public function update(int $id, UpdateBranchPricingRouteRequest $request): JsonResponse
    {
        $pricing = BranchRouteRate::findOrFail($id);
        $validated = $request->validated();

        $updated = $this->pricingRouteService->updatePricingWithRoutes(
            pricing: $pricing,
            serviceTypes: $validated['service_types'],
        );

        // Get all pricings for this route pair
        $allPricings = BranchRouteRate::where('pickup_branch_id', $updated->pickup_branch_id)
            ->where('delivery_branch_id', $updated->delivery_branch_id)
            ->with(['transferRoute'])
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Branch pricing updated successfully',
            'data' => [
                'primary_pricing' => $this->formatPricingResponse($updated),
                'all_pricings' => $allPricings->map(fn ($p) => $this->formatPricingResponse($p)),
            ],
        ]);
    }

    /**
     * DELETE /admin/branch-pricing-routes/{id}
     *
     * Delete branch pricing (deletes all related pricing for this route pair).
     */
    public function destroy(int $id): JsonResponse
    {
        $pricing = BranchRouteRate::findOrFail($id);

        $pickupBranchId = $pricing->pickup_branch_id;
        $deliveryBranchId = $pricing->delivery_branch_id;

        // Delete all pricing for this route pair
        BranchRouteRate::where('pickup_branch_id', $pickupBranchId)
            ->where('delivery_branch_id', $deliveryBranchId)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Branch pricing deleted successfully',
        ]);
    }

    /**
     * GET /admin/branch-pricing-routes/preview
     *
     * Preview route details before creating pricing.
     * Useful for showing distance/hours to admin.
     */
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'pickup_branch_id' => 'required|integer|exists:branches,id',
            'delivery_branch_id' => 'required|integer|exists:branches,id',
        ]);

        try {
            $routeDetails = $this->transferRouteService->resolve(
                originBranchId: (int) $request->input('pickup_branch_id'),
                destinationBranchId: (int) $request->input('delivery_branch_id'),
                serviceType: 'standard'
            );

            $pickupBranch = Branch::find($request->input('pickup_branch_id'));
            $deliveryBranch = Branch::find($request->input('delivery_branch_id'));

            return response()->json([
                'success' => true,
                'data' => [
                    'pickup_branch' => [
                        'id' => $pickupBranch->id,
                        'name' => $pickupBranch->name,
                        'code' => $pickupBranch->code,
                    ],
                    'delivery_branch' => [
                        'id' => $deliveryBranch->id,
                        'name' => $deliveryBranch->name,
                        'code' => $deliveryBranch->code,
                    ],
                    'route_details' => [
                        'total_distance_km' => $routeDetails['total_distance_km'],
                        'total_estimated_hours' => $routeDetails['total_estimated_hours'],
                        'transfer_count' => $routeDetails['transfer_count'],
                        'transit_count' => $routeDetails['transit_count'],
                        'path_text' => $routeDetails['path_text'],
                        'path' => $routeDetails['path'],
                    ],
                    'service_type_estimates' => [
                        'standard' => [
                            'estimated_hours' => $routeDetails['total_estimated_hours'],
                            'label' => 'Standard Delivery',
                        ],
                        'express' => [
                            'estimated_hours' => max(1, intval($routeDetails['total_estimated_hours'] / 2)),
                            'label' => 'Express Delivery',
                        ],
                        'same_day' => [
                            'estimated_hours' => 1,
                            'label' => 'Same Day Delivery',
                        ],
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Format pricing response with service type info.
     */
    private function formatPricingResponse(BranchRouteRate $pricing): array
    {
        $route = $pricing->transferRoute;

        return [
            'id' => $pricing->id,
            'pickup_branch_id' => $pricing->pickup_branch_id,
            'delivery_branch_id' => $pricing->delivery_branch_id,
            'pickup_branch' => [
                'id' => $pricing->pickupBranch?->id,
                'name' => $pricing->pickupBranch?->name,
                'code' => $pricing->pickupBranch?->code,
            ],
            'delivery_branch' => [
                'id' => $pricing->deliveryBranch?->id,
                'name' => $pricing->deliveryBranch?->name,
                'code' => $pricing->deliveryBranch?->code,
            ],
            'base_rate' => (float) $pricing->base_rate,
            'is_active' => (bool) $pricing->is_active,
            'service_type' => $route?->service_type,
            'route' => $route ? [
                'id' => $route->id,
                'code' => $route->route_code,
                'name' => $route->name,
                'total_distance_km' => (float) $route->total_distance_km,
                'total_estimated_hours' => (int) $route->total_estimated_hours,
                'transfer_count' => (int) $route->transfer_count,
                'transit_count' => (int) $route->transit_count,
            ] : null,
        ];
    }

    /**
     * Format a group of pricing (all service types for one route pair).
     */
    private function formatPricingGroup($pricings): array
    {
        $first = $pricings->first();

        return [
            'pickup_branch_id' => $first->pickup_branch_id,
            'delivery_branch_id' => $first->delivery_branch_id,
            'pickup_branch' => [
                'id' => $first->pickupBranch?->id,
                'name' => $first->pickupBranch?->name,
                'code' => $first->pickupBranch?->code,
            ],
            'delivery_branch' => [
                'id' => $first->deliveryBranch?->id,
                'name' => $first->deliveryBranch?->name,
                'code' => $first->deliveryBranch?->code,
            ],
            'service_types' => $pricings->map(fn ($p) => [
                'id' => $p->id,
                'service_type' => $p->transferRoute?->service_type,
                'base_rate' => (float) $p->base_rate,
                'route' => [
                    'code' => $p->transferRoute?->route_code,
                    'name' => $p->transferRoute?->name,
                    'total_distance_km' => (float) $p->transferRoute?->total_distance_km,
                    'total_estimated_hours' => (int) $p->transferRoute?->total_estimated_hours,
                ],
            ])->values(),
        ];
    }
}
