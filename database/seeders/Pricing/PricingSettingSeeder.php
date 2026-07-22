<?php

namespace Database\Seeders\Pricing;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PricingSettingSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('pricing_settings')->updateOrInsert(
            ['id' => 1],
            [
                'base_weight_kg' => 1.5,
                'base_distance_km' => 5,

                'same_branch_extra_weight_rate' => 20,
                'transfer_extra_weight_rate' => 30,

                'extra_distance_rate' => 6,

                'fragile_multiplier' => 1.05,

                'same_branch_same_day_multiplier' => 1.5,
                'transfer_same_day_multiplier' => 2,

                'same_day_cutoff_time' => '12:00:00',

                'minimum_pickup_packets' => 3,
                'small_pickup_charge' => 50,

                'vat_percentage' => 13,
                'vat_inclusive' => true,

                'rounding_method' => 'ceil',

                'is_active' => true,

                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}