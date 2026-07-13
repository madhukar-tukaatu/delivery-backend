<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PricingEngineDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedServiceTypes();
        $this->seedBranches();
        $this->seedBranchPricingRules();
        $this->seedBranchTransferLanes();

        echo "Pricing engine demo data seeded successfully.\n";
        echo "Branches: " . DB::table('branches')->count() . "\n";
        echo "Service Types: " . DB::table('service_types')->count() . "\n";
        echo "Pricing Rules: " . DB::table('branch_pricing_rules')->count() . "\n";
        echo "Transfer Lanes: " . DB::table('branch_transfer_lanes')->count() . "\n";
    }

    private function seedServiceTypes(): void
    {
        DB::table('service_types')->updateOrInsert(
            ['code' => 'standard'],
            $this->onlyExistingColumns('service_types', [
                'name' => 'Standard',
                'description' => 'Normal delivery service',
                'price_multiplier' => 1,
                'fixed_addon_fee' => 0,
                'estimated_min_hours' => 48,
                'estimated_max_hours' => 72,
                'pickup_cutoff_time' => null,
                'same_day_only' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );

        DB::table('service_types')->updateOrInsert(
            ['code' => 'express'],
            $this->onlyExistingColumns('service_types', [
                'name' => 'Express',
                'description' => 'Priority delivery service',
                'price_multiplier' => 1.35,
                'fixed_addon_fee' => 30,
                'estimated_min_hours' => 24,
                'estimated_max_hours' => 48,
                'pickup_cutoff_time' => null,
                'same_day_only' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );

        DB::table('service_types')->updateOrInsert(
            ['code' => 'same_day'],
            $this->onlyExistingColumns('service_types', [
                'name' => 'Same Day',
                'description' => 'Same day delivery service',
                'price_multiplier' => 1.80,
                'fixed_addon_fee' => 80,
                'estimated_min_hours' => 4,
                'estimated_max_hours' => 12,
                'pickup_cutoff_time' => '14:00:00',
                'same_day_only' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ])
        );
    }

    private function seedBranches(): void
    {
         DB::table('branches')->updateOrInsert(
        ['code' => 'KTM-MAIN'],
        $this->onlyExistingColumns('branches', [
            'code' => 'KTM-MAIN',
            'name' => 'Kathmandu Main Branch',
            'type' => 'branch',
            'address' => 'Kathmandu, Nepal',
            'city' => 'Kathmandu',
            'district' => 'Kathmandu',
            'province' => 'Bagmati',
            'phone' => '9800000000',
            'latitude' => 27.7172,
            'longitude' => 85.3240,
            'is_active' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ])
    );

    DB::table('branches')->updateOrInsert(
        ['code' => 'BKT-MAIN'],
        $this->onlyExistingColumns('branches', [
            'code' => 'BKT-MAIN',
            'name' => 'Bhaktapur Branch',
            'type' => 'branch',
            'address' => 'Bhaktapur, Nepal',
            'city' => 'Bhaktapur',
            'district' => 'Bhaktapur',
            'province' => 'Bagmati',
            'phone' => '9800000001',
            'latitude' => 27.6710,
            'longitude' => 85.4298,
            'is_active' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ])
    );
    }

    private function seedBranchPricingRules(): void
    {
        $services = DB::table('service_types')
            ->whereIn('code', ['standard', 'express', 'same_day'])
            ->get()
            ->keyBy('code');

        $branches = DB::table('branches')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        foreach ($branches as $branch) {
            foreach ($services as $service) {
                $pricing = match ($service->code) {
                    'express' => [
                        'base_radius_km' => 5,
                        'base_pickup_fee' => 40,
                        'base_delivery_fee' => 70,
                        'pickup_extra_per_km' => 20,
                        'delivery_extra_per_km' => 25,
                        'max_pickup_distance_km' => 20,
                        'max_delivery_distance_km' => 25,
                        'base_weight_kg' => 1,
                        'extra_weight_per_kg' => 30,
                        'cod_fee_fixed' => 25,
                        'cod_fee_percentage' => 0,
                    ],
                    'same_day' => [
                        'base_radius_km' => 5,
                        'base_pickup_fee' => 60,
                        'base_delivery_fee' => 100,
                        'pickup_extra_per_km' => 30,
                        'delivery_extra_per_km' => 40,
                        'max_pickup_distance_km' => 15,
                        'max_delivery_distance_km' => 15,
                        'base_weight_kg' => 1,
                        'extra_weight_per_kg' => 40,
                        'cod_fee_fixed' => 30,
                        'cod_fee_percentage' => 0,
                    ],
                    default => [
                        'base_radius_km' => 5,
                        'base_pickup_fee' => 30,
                        'base_delivery_fee' => 50,
                        'pickup_extra_per_km' => 15,
                        'delivery_extra_per_km' => 20,
                        'max_pickup_distance_km' => 20,
                        'max_delivery_distance_km' => 25,
                        'base_weight_kg' => 1,
                        'extra_weight_per_kg' => 25,
                        'cod_fee_fixed' => 20,
                        'cod_fee_percentage' => 0,
                    ],
                };

                DB::table('branch_pricing_rules')->updateOrInsert(
                    [
                        'branch_id' => $branch->id,
                        'service_type_id' => $service->id,
                    ],
                    $this->onlyExistingColumns('branch_pricing_rules', array_merge($pricing, [
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]))
                );
            }
        }
    }

    private function seedBranchTransferLanes(): void
    {
        $services = DB::table('service_types')
            ->whereIn('code', ['standard', 'express', 'same_day'])
            ->get()
            ->keyBy('code');

        $branches = DB::table('branches')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        foreach ($branches as $fromBranch) {
            foreach ($branches as $toBranch) {
                if ((int) $fromBranch->id === (int) $toBranch->id) {
                    continue;
                }

                foreach ($services as $service) {
                    $transfer = match ($service->code) {
                        'express' => [
                            'base_transfer_fee' => 120,
                            'per_kg_fee' => 15,
                            'estimated_hours' => 24,
                        ],
                        'same_day' => [
                            'base_transfer_fee' => 180,
                            'per_kg_fee' => 25,
                            'estimated_hours' => 12,
                        ],
                        default => [
                            'base_transfer_fee' => 80,
                            'per_kg_fee' => 10,
                            'estimated_hours' => 48,
                        ],
                    };

                    DB::table('branch_transfer_lanes')->updateOrInsert(
                        [
                            'from_branch_id' => $fromBranch->id,
                            'to_branch_id' => $toBranch->id,
                            'service_type_id' => $service->id,
                        ],
                        $this->onlyExistingColumns('branch_transfer_lanes', array_merge($transfer, [
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]))
                    );
                }
            }
        }
    }

    private function onlyExistingColumns(string $table, array $data): array
    {
        return collect($data)
            ->filter(fn ($value, $column) => Schema::hasColumn($table, $column))
            ->toArray();
    }
}