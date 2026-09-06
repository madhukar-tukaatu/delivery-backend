<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Rate\Models\BranchRouteRate;
use Modules\Rate\Models\BranchTransferRoute;

/**
 * Manages branch pricing with automatic route creation/updates.
 *
 * When creating/editing branch pricing, this service:
 * 1. Creates BranchTransferRoutes for each enabled service type
 * 2. Creates BranchRouteRates with correct associations
 * 3. Handles updates (add/remove service types)
 */
final class BranchPricingRouteService
{
    public function __construct(
        private readonly ConfiguredTransferRouteService $transferRouteService
    ) {}

    /**
     * Create branch pricing with routes for selected service types.
     *
     * @param int $pickupBranchId Origin branch
     * @param int $deliveryBranchId Destination branch
     * @param array $serviceTypes Array of pricing per service:
     *   [
     *     'standard' => ['base_rate' => 500, 'enabled' => true],
     *     'express' => ['base_rate' => 750, 'enabled' => true],
     *     'same_day' => ['base_rate' => 1500, 'enabled' => false],
     *   ]
     * @return BranchRouteRate The primary (standard) pricing record
     */
    public function createPricingWithRoutes(
        int $pickupBranchId,
        int $deliveryBranchId,
        array $serviceTypes
    ): BranchRouteRate {
        return DB::transaction(function () use ($pickupBranchId, $deliveryBranchId, $serviceTypes) {
            // Resolve the base route from configured transfer routes
            $baseRoute = $this->transferRouteService->resolve(
                originBranchId: $pickupBranchId,
                destinationBranchId: $deliveryBranchId,
                serviceType: 'standard'
            );

            $primaryPricing = null;

            // Create pricing record for each enabled service type
            foreach ($serviceTypes as $serviceType => $config) {
                if (!isset($config['enabled']) || !$config['enabled']) {
                    continue;
                }

                $baseRate = (float) ($config['base_rate'] ?? 0);

                if ($baseRate <= 0) {
                    throw ValidationException::withMessages([
                        "{$serviceType}_rate" => ["Base rate must be greater than 0 for {$serviceType}."],
                    ]);
                }

                // Find or create transfer route for this service type
                $route = $this->findOrCreateTransferRoute(
                    pickupBranchId: $pickupBranchId,
                    deliveryBranchId: $deliveryBranchId,
                    serviceType: $serviceType,
                    baseRoute: $baseRoute
                );

                // Create pricing record
                $pricing = BranchRouteRate::create([
                    'pickup_branch_id' => $pickupBranchId,
                    'delivery_branch_id' => $deliveryBranchId,
                    'branch_transfer_route_id' => $route->id,
                    'base_rate' => $baseRate,
                    'is_active' => true,
                    'express_enabled' => $serviceType === 'express',
                    'same_day_enabled' => $serviceType === 'same_day',
                ]);

                // Store the standard pricing as primary
                if ($serviceType === 'standard') {
                    $primaryPricing = $pricing;
                }
            }

            if (!$primaryPricing) {
                throw ValidationException::withMessages([
                    'service_types' => ['Standard service type must be enabled.'],
                ]);
            }

            return $primaryPricing;
        });
    }

    /**
     * Update existing branch pricing with service type changes.
     *
     * @param BranchRouteRate $pricing Existing pricing record
     * @param array $serviceTypes Updated service types configuration
     * @return BranchRouteRate Updated pricing record
     */
    public function updatePricingWithRoutes(
        BranchRouteRate $pricing,
        array $serviceTypes
    ): BranchRouteRate {
        return DB::transaction(function () use ($pricing, $serviceTypes) {
            $pickupBranchId = $pricing->pickup_branch_id;
            $deliveryBranchId = $pricing->delivery_branch_id;

            // Find all existing pricing for this route
            $existingPricings = BranchRouteRate::where('pickup_branch_id', $pickupBranchId)
                ->where('delivery_branch_id', $deliveryBranchId)
                ->get();

            // Map existing by route ID
            $existingByRoute = [];
            foreach ($existingPricings as $p) {
                $existingByRoute[$p->branch_transfer_route_id] = $p;
            }

            $baseRoute = $this->transferRouteService->resolve(
                originBranchId: $pickupBranchId,
                destinationBranchId: $deliveryBranchId,
                serviceType: 'standard'
            );

            $primaryPricing = null;
            $processedRouteIds = [];

            // Process each service type
            foreach ($serviceTypes as $serviceType => $config) {
                $enabled = (bool) ($config['enabled'] ?? false);
                $baseRate = (float) ($config['base_rate'] ?? 0);

                if (!$enabled) {
                    continue;
                }

                if ($baseRate <= 0) {
                    throw ValidationException::withMessages([
                        "{$serviceType}_rate" => ["Base rate must be greater than 0 for {$serviceType}."],
                    ]);
                }

                // Find or create route for this service type
                $route = $this->findOrCreateTransferRoute(
                    pickupBranchId: $pickupBranchId,
                    destinationBranchId: $deliveryBranchId,
                    serviceType: $serviceType,
                    baseRoute: $baseRoute
                );

                $processedRouteIds[] = $route->id;

                // Update or create pricing for this route
                $p = $existingByRoute[$route->id] ?? null;

                if ($p) {
                    $p->update([
                        'base_rate' => $baseRate,
                        'is_active' => true,
                        'express_enabled' => $serviceType === 'express',
                        'same_day_enabled' => $serviceType === 'same_day',
                    ]);
                } else {
                    $p = BranchRouteRate::create([
                        'pickup_branch_id' => $pickupBranchId,
                        'delivery_branch_id' => $deliveryBranchId,
                        'branch_transfer_route_id' => $route->id,
                        'base_rate' => $baseRate,
                        'is_active' => true,
                        'express_enabled' => $serviceType === 'express',
                        'same_day_enabled' => $serviceType === 'same_day',
                    ]);
                }

                if ($serviceType === 'standard') {
                    $primaryPricing = $p;
                }
            }

            // Delete pricing for service types that are no longer enabled
            foreach ($existingByRoute as $routeId => $p) {
                if (!in_array($routeId, $processedRouteIds, true)) {
                    $p->delete();
                }
            }

            if (!$primaryPricing) {
                throw ValidationException::withMessages([
                    'service_types' => ['Standard service type must be enabled.'],
                ]);
            }

            return $primaryPricing;
        });
    }

    /**
     * Find existing or create new transfer route for a service type.
     *
     * @param int $pickupBranchId
     * @param int $deliveryBranchId
     * @param string $serviceType 'standard' | 'express' | 'same_day'
     * @param array $baseRoute Route details from ConfiguredTransferRouteService
     * @return BranchTransferRoute
     */
    private function findOrCreateTransferRoute(
        int $pickupBranchId,
        int $deliveryBranchId,
        string $serviceType,
        array $baseRoute
    ): BranchTransferRoute {
        $routeCode = $this->generateRouteCode(
            $pickupBranchId,
            $deliveryBranchId,
            $serviceType
        );

        return BranchTransferRoute::firstOrCreate(
            [
                'origin_branch_id' => $pickupBranchId,
                'destination_branch_id' => $deliveryBranchId,
                'service_type' => $serviceType,
            ],
            [
                'route_code' => $routeCode,
                'name' => $this->generateRouteName($pickupBranchId, $deliveryBranchId, $serviceType),
                'service_type' => $serviceType,
                'transfer_count' => (int) ($baseRoute['transfer_count'] ?? 0),
                'transit_count' => (int) ($baseRoute['transit_count'] ?? 0),
                'transit_branch_ids' => json_encode($baseRoute['transit_branches'] ?? []),
                'total_distance_km' => (float) ($baseRoute['total_distance_km'] ?? 0),
                'total_estimated_hours' => $this->getEstimatedHours($serviceType, $baseRoute),
                'priority' => $this->getPriority($serviceType),
                'is_default' => $serviceType === 'standard',
                'is_active' => true,
            ]
        );
    }

    /**
     * Generate unique route code based on branches and service type.
     *
     * @return string e.g., "KTM-PKR-STD", "KTM-PKR-EXP", "KTM-PKR-SAMEDAY"
     */
    private function generateRouteCode(int $pickupBranchId, int $deliveryBranchId, string $serviceType): string
    {
        $pickupBranch = DB::table('branches')->find($pickupBranchId);
        $deliveryBranch = DB::table('branches')->find($deliveryBranchId);

        $pickupCode = strtoupper(substr($pickupBranch?->code ?? 'UNKNOWN', 0, 3));
        $deliveryCode = strtoupper(substr($deliveryBranch?->code ?? 'UNKNOWN', 0, 3));

        $typeCode = match ($serviceType) {
            'standard' => 'STD',
            'express' => 'EXP',
            'same_day' => 'SAMEDAY',
            default => 'STD',
        };

        return "{$pickupCode}-{$deliveryCode}-{$typeCode}";
    }

    /**
     * Generate human-readable route name.
     *
     * @return string e.g., "Kathmandu to Pokhara (Standard)"
     */
    private function generateRouteName(int $pickupBranchId, int $deliveryBranchId, string $serviceType): string
    {
        $pickupBranch = DB::table('branches')->find($pickupBranchId);
        $deliveryBranch = DB::table('branches')->find($deliveryBranchId);

        $serviceLabel = match ($serviceType) {
            'standard' => 'Standard',
            'express' => 'Express',
            'same_day' => 'Same Day',
            default => 'Standard',
        };

        return "{$pickupBranch?->name ?? 'Unknown'} to {$deliveryBranch?->name ?? 'Unknown'} ({$serviceLabel})";
    }

    /**
     * Get estimated delivery hours based on service type.
     *
     * @param string $serviceType
     * @param array $baseRoute Route details with total_estimated_hours
     * @return int Estimated hours
     */
    private function getEstimatedHours(string $serviceType, array $baseRoute): int
    {
        $baseHours = (int) ($baseRoute['total_estimated_hours'] ?? 24);

        return match ($serviceType) {
            'express' => max(1, intval($baseHours / 2)),     // Half the standard time
            'same_day' => 1,                                  // 1 hour for same day
            'standard' => $baseHours,                         // Full standard time
            default => $baseHours,
        };
    }

    /**
     * Get priority level for route (higher = processed first).
     *
     * @return int
     */
    private function getPriority(string $serviceType): int
    {
        return match ($serviceType) {
            'same_day' => 10,    // Highest priority
            'express' => 5,      // Medium priority
            'standard' => 1,     // Lowest priority
            default => 1,
        };
    }
}
