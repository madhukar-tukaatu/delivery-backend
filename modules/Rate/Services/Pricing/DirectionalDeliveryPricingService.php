<?php

namespace Modules\Rate\Services\Pricing;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Rate\Services\ConfiguredTransferRouteService;

final class DirectionalDeliveryPricingService
{
    public function __construct(
        private readonly ConfiguredTransferRouteService $configuredTransferRouteService,
        private readonly PricingRuleCalculator $pricingRuleCalculator
    ) {
    }

    /**
     * Resolve the local or directional transfer base rate and apply
     * route-specific pricing settings, falling back to global settings.
     */
    public function calculate(array $context): array
    {
        $pickupBranchId = (int) (
            $context['pickup_branch_id'] ?? 0
        );

        $deliveryBranchId = (int) (
            $context['delivery_branch_id'] ?? 0
        );

        if (
            $pickupBranchId <= 0 ||
            $deliveryBranchId <= 0
        ) {
            throw ValidationException::withMessages([
                'branch' => [
                    'Valid pickup and delivery branch IDs are required.',
                ],
            ]);
        }

        $serviceType = (string) (
            $context['service_type'] ?? 'standard'
        );

        $routeServiceType = (string) (
            $context['route_service_type'] ?? 'standard'
        );

        $sameBranch = $pickupBranchId === $deliveryBranchId;
        $routeResult = null;
        $branchTransferRouteId = null;

        if ($sameBranch) {
            $localRate = DB::table('branch_route_rates')
                ->where('pickup_branch_id', $pickupBranchId)
                ->where('delivery_branch_id', $deliveryBranchId)
                ->where('is_active', true)
                ->first();

            if (!$localRate) {
                throw ValidationException::withMessages([
                    'base_rate' => [
                        'No active same-branch base rate is configured.',
                    ],
                ]);
            }

            $baseRate = (float) $localRate->base_rate;
        } else {
            $routeResult = $this
                ->configuredTransferRouteService
                ->resolve(
                    originBranchId: $pickupBranchId,
                    destinationBranchId: $deliveryBranchId,
                    serviceType: $routeServiceType
                );

            $branchTransferRouteId = (int) (
                $routeResult['id']
                ?? $routeResult['route_id']
                ?? 0
            );

            if ($branchTransferRouteId <= 0) {
                throw ValidationException::withMessages([
                    'route' => [
                        'The configured transfer route response does not contain a valid route ID.',
                    ],
                ]);
            }

            $baseRate = max(
                0,
                (float) ($routeResult['base_rate'] ?? 0)
            );
        }

        $pricingResult = $this
            ->pricingRuleCalculator
            ->calculateDelivery([
                'branch_transfer_route_id' =>
                    $branchTransferRouteId,
                'pickup_branch_id' => $pickupBranchId,
                'delivery_branch_id' => $deliveryBranchId,
                'base_rate' => $baseRate,
                'parcel_weight' => (float) (
                    $context['parcel_weight'] ?? 0
                ),
                'delivery_distance_km' => (float) (
                    $context['delivery_distance_km'] ?? 0
                ),
                'packet_count' => (int) (
                    $context['packet_count'] ?? 1
                ),
                'parcel_type' => (string) (
                    $context['parcel_type'] ?? 'non_fragile'
                ),
                'service_type' => $serviceType,
                'requested_at' =>
                    $context['requested_at'] ?? now(),
            ]);

        return [
            'route_type' => $sameBranch ? 'local' : 'transfer',
            'same_branch' => $sameBranch,
            'route' => $routeResult,
            'branch_transfer_route_id' => $branchTransferRouteId,
            'pricing_settings_id' => (int) (
                $pricingResult['pricing_settings_id']
                ?? $pricingResult['settings_id']
            ),
            'pricing_settings_source' =>
                $pricingResult['pricing_settings_source'],
            'pricing_settings_fallback' => (bool) (
                $pricingResult['pricing_settings_fallback']
            ),
            'base_rate' => $pricingResult['base_rate'],
            'breakdown' => [
                'weight' => $pricingResult['weight'],
                'fragile' => $pricingResult['fragile'],
                'distance' => $pricingResult['distance'],
                'same_day' => $pricingResult['same_day'],
                'pickup_minimum' =>
                    $pricingResult['pickup_minimum'],
                'vat' => $pricingResult['vat'],
            ],
            'subtotal_before_vat' =>
                $pricingResult['subtotal_before_vat'],
            'final_price' => $pricingResult['final_price'],
            'currency' => $pricingResult['currency'],
        ];
    }
}
