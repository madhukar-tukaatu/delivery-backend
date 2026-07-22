<?php

namespace Modules\Rate\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Modules\Rate\Models\PricingQuote;

class PricingQuoteService
{
    public function __construct(
        private readonly PricingCalculatorService $calculator
    ) {
    }

    public function create(array $input): PricingQuote
    {
        return DB::transaction(function () use ($input) {
            $result = $this->calculator->calculate($input);

            $breakdown = $result['breakdown'];
            $shipment = $result['shipment'];

            return PricingQuote::query()->create([
                'quote_uuid' => (string) Str::uuid(),

                'pricing_setting_id' =>
                    $result['setting_id'],

                'branch_route_rate_id' =>
                    $result['branch_route_rate_id'],

                'origin_branch_id' =>
                    $result['origin_branch']['id'],

                'destination_branch_id' =>
                    $result['destination_branch']['id'],

                'weight_kg' =>
                    $shipment['weight_kg'],

                'distance_km' =>
                    $shipment['distance_km'],

                'packet_count' =>
                    $shipment['packet_count'],

                'is_fragile' =>
                    $shipment['is_fragile'],

                'is_same_day' =>
                    $shipment['is_same_day'],

                'is_branch_transfer' =>
                    $shipment['is_branch_transfer'],

                'base_rate' =>
                    $breakdown['base_rate'],

                'excess_weight_kg' =>
                    $breakdown['excess_weight_kg'],

                'weight_rate' =>
                    $breakdown['weight_rate'],

                'weight_charge' =>
                    $breakdown['weight_charge'],

                'excess_distance_km' =>
                    $breakdown['excess_distance_km'],

                'distance_rate' =>
                    $breakdown['distance_rate'],

                'distance_charge' =>
                    $breakdown['distance_charge'],

                'fragile_multiplier' =>
                    $breakdown['fragile_multiplier'],

                'fragile_charge' =>
                    $breakdown['fragile_charge'],

                'same_day_multiplier' =>
                    $breakdown['same_day_multiplier'],

                'same_day_charge' =>
                    $breakdown['same_day_charge'],

                'pickup_charge' =>
                    $breakdown['pickup_charge'],

                'subtotal' =>
                    $breakdown['subtotal'],

                'vat_percentage' =>
                    $breakdown['vat_percentage'],

                'vat_amount' =>
                    $breakdown['vat_amount'],

                'total_amount' =>
                    $breakdown['total_amount'],

                'currency' =>
                    $breakdown['currency'],

                'pricing_snapshot' => $result,

                'expires_at' => now()->addMinutes(30),

                'created_by' => auth()->id(),
            ]);
        });
    }
}