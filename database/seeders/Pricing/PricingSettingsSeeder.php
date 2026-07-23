<?php

namespace Database\Seeders\Pricing;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PricingSettingsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('pricing_settings')
            ->updateOrInsert(
                ['id' => 1],
                [
                    'included_weight_kg' => 1.5,

                    'same_branch_weight_rate' => 20,
                    'other_branch_weight_rate' => 30,

                    'included_delivery_distance_km' => 5,
                    'extra_distance_rate_per_km' => 6,

                    'fragile_multiplier' => 1.05,

                    'same_branch_sdd_multiplier' => 1.5,
                    'other_branch_sdd_multiplier' => 2,

                    'minimum_pickup_packets' => 3,
                    'low_packet_pickup_charge' => 50,

                    'same_day_cutoff_time' => '12:00:00',

                    'vat_inclusive' => true,
                    'vat_percentage' => 13,

                    'is_active' => true,

                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
    }
}