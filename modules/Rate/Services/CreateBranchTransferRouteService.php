<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Illuminate\Validation\ValidationException;
use Modules\Rate\Models\BranchTransferLane;
use Modules\Rate\Models\BranchTransferRoute;

final class CreateBranchTransferRouteService
{
    private BranchTransferRouteCodeGenerator $codeGenerator;

    public function __construct(BranchTransferRouteCodeGenerator $codeGenerator)
    {
        $this->codeGenerator = $codeGenerator;
    }

    /**
     * Create a transfer route with auto-generated code and name.
     * 
     * @param int $fromBranchId Origin branch ID
     * @param int $toBranchId Destination branch ID
     * @param string $serviceType Service type (standard, express, same_day, flight)
     * @param array $additionalData Additional data (base_rate, priority, notes, etc.)
     * 
     * @return BranchTransferRoute
     * @throws ValidationException
     */
    public function create(
        int $fromBranchId,
        int $toBranchId,
        string $serviceType,
        array $additionalData = []
    ): BranchTransferRoute {
        // Validate lane exists
        if (!$this->codeGenerator->validateLaneExists($fromBranchId, $toBranchId, $serviceType)) {
            throw ValidationException::withMessages([
                'lane' => [
                    sprintf(
                        'No active transfer lane exists from branch %d to branch %d for %s service.',
                        $fromBranchId,
                        $toBranchId,
                        strtoupper($serviceType)
                    ),
                ],
            ]);
        }

        // Get or create lane
        $lane = BranchTransferLane::where('from_branch_id', $fromBranchId)
            ->where('to_branch_id', $toBranchId)
            ->where('service_type', strtolower($serviceType))
            ->firstOrFail();

        // Normalize transit branch IDs (ordered intermediate hubs)
        $transitBranchIds = $this->normalizeTransits(
            $additionalData['transit_branch_ids'] ?? [],
            $fromBranchId,
            $toBranchId
        );

        // Generate code and name (including transits)
        $routeCode = $this->codeGenerator->generate($fromBranchId, $toBranchId, $serviceType, $transitBranchIds);
        $routeName = $this->codeGenerator->generateName($fromBranchId, $toBranchId, $transitBranchIds);

        // Check if route code already exists (prevent duplicates)
        $existingRoute = BranchTransferRoute::where('route_code', $routeCode)->first();
        if ($existingRoute) {
            throw ValidationException::withMessages([
                'route_code' => ['Route code already exists. Try a different service type or branch combination.'],
            ]);
        }

        // Create route
        $route = BranchTransferRoute::create([
            'route_code' => $routeCode,
            'name' => $routeName,
            'branch_transfer_lane_id' => $lane->id,
            'transit_branch_ids' => $transitBranchIds ?: null,
            'service_type' => strtolower($serviceType),
            'base_rate' => $additionalData['base_rate'] ?? 0,
            'currency' => $additionalData['currency'] ?? 'NPR',
            'distance_km' => $lane->distance_km,
            'estimated_hours' => $lane->estimated_hours,
            'priority' => $additionalData['priority'] ?? 100,
            'is_default' => $additionalData['is_default'] ?? false,
            'is_active' => $additionalData['is_active'] ?? true,
            'notes' => $additionalData['notes'] ?? null,
        ]);

        return $route;
    }

    /**
     * Clean transit IDs: unique, integer, and never equal to origin/destination.
     */
    private function normalizeTransits(array $transits, int $fromBranchId, int $toBranchId): array
    {
        $clean = [];
        foreach ($transits as $id) {
            $id = (int) $id;
            if ($id <= 0 || $id === $fromBranchId || $id === $toBranchId) {
                continue;
            }
            if (!in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        return $clean;
    }

    /**
     * Get formatted response for newly created route.
     */
    public function formatResponse(BranchTransferRoute $route): array
    {
        $route->loadMissing('lane.fromBranch', 'lane.toBranch');
        $lane = $route->lane;

        return [
            'id' => $route->id,
            'route_code' => $route->route_code,
            'name' => $route->name,
            'service_type' => $route->service_type,
            'from_branch_id' => $lane?->from_branch_id,
            'from_branch_name' => $lane?->fromBranch?->name,
            'to_branch_id' => $lane?->to_branch_id,
            'to_branch_name' => $lane?->toBranch?->name,
            'transit_branch_ids' => $route->transit_branch_ids ?? [],
            'transit_count' => $route->getTransitCount(),
            'base_rate' => (float) $route->base_rate,
            'currency' => $route->currency,
            'distance_km' => (float) $route->distance_km,
            'estimated_hours' => (int) $route->estimated_hours,
            'priority' => (int) $route->priority,
            'is_active' => (bool) $route->is_active,
            'is_default' => (bool) $route->is_default,
            'created_at' => $route->created_at?->toIso8601String(),
        ];
    }
}
