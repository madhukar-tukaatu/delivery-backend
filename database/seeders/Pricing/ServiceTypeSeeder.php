<?php

declare(strict_types=1);

namespace Database\Seeders\Pricing;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class ServiceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $serviceTypes = [
            [
                'code' => 'standard',
                'name' => 'Standard',
                'description' => 'Normal delivery service',
                'category' => 'standard',
                'price_multiplier' => 1.0000,
                'estimated_hours' => 72,
                'cutoff_time' => null,
                'same_day' => false,
                'requires_branch_transfer' => false,
                'available_for_local' => true,
                'available_for_transfer' => true,
                'available_for_public_api' => true,
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'express',
                'name' => 'Express',
                'description' => 'Priority delivery service',
                'category' => 'express',
                'price_multiplier' => 1.3500,
                'estimated_hours' => 24,
                'cutoff_time' => null,
                'same_day' => false,
                'requires_branch_transfer' => false,
                'available_for_local' => true,
                'available_for_transfer' => true,
                'available_for_public_api' => true,
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'same_day',
                'name' => 'Same Day',
                'description' => 'Same-day delivery service',
                'category' => 'same_day',
                'price_multiplier' => 1.8000,
                'estimated_hours' => 12,
                'cutoff_time' => '14:00:00',
                'same_day' => true,
                'requires_branch_transfer' => false,
                'available_for_local' => true,
                'available_for_transfer' => true,
                'available_for_public_api' => true,
                'is_default' => false,
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($serviceTypes as $serviceType) {
            DB::table('service_types')->updateOrInsert(
                ['code' => $serviceType['code']],
                $serviceType
            );
        }
    }
}