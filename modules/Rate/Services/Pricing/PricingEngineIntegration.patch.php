<?php

/*
|--------------------------------------------------------------------------
| PricingEngineService integration
|--------------------------------------------------------------------------
|
| 1. Inject PricingRuleCalculator into your existing PricingEngineService.
|
| use Modules\Rate\Services\Pricing\PricingRuleCalculator;
|
| public function __construct(
|     ...existing dependencies...,
|     private readonly PricingRuleCalculator $pricingRuleCalculator,
| ) {
| }
|
| 2. Keep the existing branch and configured-transfer-route resolution.
|    After the route base rate has been resolved, replace manual surcharge
|    calculations with the following call.
*/

$ruleResult = $this->pricingRuleCalculator->calculateDelivery([
    'pickup_branch_id' => $pickupBranch->id,
    'delivery_branch_id' => $deliveryBranch->id,

    /*
     * For configured_transfer_route this is:
     * branch_transfer_routes.base_rate
     *
     * For same-branch delivery this may still come from:
     * branch_route_rates.base_rate
     */
    'base_rate' => $baseRate,

    'parcel_weight' => (float) ($payload['parcel_weight'] ?? 0),

    /*
     * This must be the final delivery distance from the resolved destination
     * branch or zone to the customer address, not total transfer-route distance.
     */
    'delivery_distance_km' => (float) (
        $deliveryCoverageResult['distance_km']
        ?? $payload['delivery_distance_km']
        ?? 0
    ),

    'packet_count' => (int) ($payload['packet_count'] ?? 1),
    'parcel_type' => (string) ($payload['parcel_type'] ?? 'non_fragile'),
    'service_type' => (string) ($payload['service_type'] ?? 'standard'),
    'requested_at' => now(),
]);

/*
 * Use the calculated result in the quote response.
 */
return [
    ...$existingQuoteData,

    'pricing_settings_id' => $ruleResult['settings_id'],

    'breakdown' => [
        'base_rate' => $ruleResult['base_rate'],
        'weight' => $ruleResult['weight'],
        'fragile' => $ruleResult['fragile'],
        'distance' => $ruleResult['distance'],
        'same_day' => $ruleResult['same_day'],
        'pickup_minimum' => $ruleResult['pickup_minimum'],
        'vat' => $ruleResult['vat'],
    ],

    'final_price' => $ruleResult['final_price'],
    'currency' => $ruleResult['currency'],
];

/*
|--------------------------------------------------------------------------
| Return/cancellation integration
|--------------------------------------------------------------------------
|
| In the return or cancellation workflow:
|
| $returnCharge = $this->pricingRuleCalculator->calculateReturnCharge(
|     scenarioCode: 'transfer_branch_assigned_delivery',
|     baseRate: (float) $shipment->base_rate,
|     distanceTravelledKm: (float) $distanceTravelledKm,
| );
*/
