<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class PricingEngineDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->ensureRequiredTablesExist();

        $this->seedServiceTypes();
        $this->seedBranches();
        $this->seedBranchPricingRules();
        $this->seedInterBranchTransferCounts();
        $this->seedTransferCountRates();
        $this->seedWeightRateRules();
        $this->seedParcelHandlingRates();
        $this->seedCodRateRules();

        $this->command?->info(
            'Pricing engine demo data seeded successfully.'
        );

        $this->showCounts();
    }

    private function ensureRequiredTablesExist(): void
    {
        $requiredTables = [
            'service_types',
            'branches',
            'branch_pricing_rules',
            'inter_branch_transfer_counts',
            'transfer_count_rates',
            'weight_rate_rules',
            'parcel_handling_rates',
            'pod_rate_rules',
        ];

        foreach ($requiredTables as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException(
                    "Required pricing table [{$table}] does not exist. Run migrations first."
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Service Types
    |--------------------------------------------------------------------------
    */

    private function seedServiceTypes(): void
    {
        $serviceTypes = [
            [
                'code' => 'standard',
                'name' => 'Standard',
                'description' => 'Normal delivery service',
                'price_multiplier' => 1.00,
                'fixed_addon_fee' => 0,
                'estimated_hours' => 48,
                'estimated_min_hours' => 48,
                'estimated_max_hours' => 72,
                'pickup_cutoff_time' => null,
                'same_day_only' => false,
                'is_active' => true,
            ],
            [
                'code' => 'express',
                'name' => 'Express',
                'description' => 'Priority delivery service',
                'price_multiplier' => 1.35,
                'fixed_addon_fee' => 30,
                'estimated_hours' => 24,
                'estimated_min_hours' => 24,
                'estimated_max_hours' => 48,
                'pickup_cutoff_time' => null,
                'same_day_only' => false,
                'is_active' => true,
            ],
            [
                'code' => 'same_day',
                'name' => 'Same Day',
                'description' => 'Same-day delivery service',
                'price_multiplier' => 1.80,
                'fixed_addon_fee' => 80,
                'estimated_hours' => 8,
                'estimated_min_hours' => 4,
                'estimated_max_hours' => 12,
                'pickup_cutoff_time' => '14:00:00',
                'same_day_only' => true,
                'is_active' => true,
            ],
        ];

        foreach ($serviceTypes as $serviceType) {
            DB::table('service_types')->updateOrInsert(
                [
                    'code' => $serviceType['code'],
                ],
                $this->onlyExistingColumns(
                    'service_types',
                    [
                        ...$serviceType,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                )
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Main Branches
    |--------------------------------------------------------------------------
    */

    private function seedBranches(): void
    {
        $branches = [
            [
                'code' => 'KTM-MAIN',
                'name' => 'Kathmandu Main Branch',
                'type' => 'branch',
                'branch_type' => 'main',
                'is_main' => true,
                'parent_id' => null,
                'address' => 'Kathmandu, Nepal',
                'city' => 'Kathmandu',
                'district' => 'Kathmandu',
                'province' => 'Bagmati',
                'phone' => '9800000000',
                'latitude' => 27.7172,
                'longitude' => 85.3240,
                'is_active' => true,
                'status' => 'active',
            ],
            [
                'code' => 'BKT-MAIN',
                'name' => 'Bhaktapur Main Branch',
                'type' => 'branch',
                'branch_type' => 'main',
                'is_main' => true,
                'parent_id' => null,
                'address' => 'Bhaktapur, Nepal',
                'city' => 'Bhaktapur',
                'district' => 'Bhaktapur',
                'province' => 'Bagmati',
                'phone' => '9800000001',
                'latitude' => 27.6710,
                'longitude' => 85.4298,
                'is_active' => true,
                'status' => 'active',
            ],
            [
                'code' => 'PKR-MAIN',
                'name' => 'Pokhara Main Branch',
                'type' => 'branch',
                'branch_type' => 'main',
                'is_main' => true,
                'parent_id' => null,
                'address' => 'Pokhara, Nepal',
                'city' => 'Pokhara',
                'district' => 'Kaski',
                'province' => 'Gandaki',
                'phone' => '9800000002',
                'latitude' => 28.2096,
                'longitude' => 83.9856,
                'is_active' => true,
                'status' => 'active',
            ],
            [
                'code' => 'BRT-MAIN',
                'name' => 'Biratnagar Main Branch',
                'type' => 'branch',
                'branch_type' => 'main',
                'is_main' => true,
                'parent_id' => null,
                'address' => 'Biratnagar, Nepal',
                'city' => 'Biratnagar',
                'district' => 'Morang',
                'province' => 'Koshi',
                'phone' => '9800000003',
                'latitude' => 26.4525,
                'longitude' => 87.2718,
                'is_active' => true,
                'status' => 'active',
            ],
            [
                'code' => 'ITH-MAIN',
                'name' => 'Itahari Main Branch',
                'type' => 'branch',
                'branch_type' => 'main',
                'is_main' => true,
                'parent_id' => null,
                'address' => 'Itahari, Nepal',
                'city' => 'Itahari',
                'district' => 'Sunsari',
                'province' => 'Koshi',
                'phone' => '9800000004',
                'latitude' => 26.6636,
                'longitude' => 87.2747,
                'is_active' => true,
                'status' => 'active',
            ],
            [
                'code' => 'DMK-MAIN',
                'name' => 'Damak Main Branch',
                'type' => 'branch',
                'branch_type' => 'main',
                'is_main' => true,
                'parent_id' => null,
                'address' => 'Damak, Nepal',
                'city' => 'Damak',
                'district' => 'Jhapa',
                'province' => 'Koshi',
                'phone' => '9800000005',
                'latitude' => 26.6588,
                'longitude' => 87.7006,
                'is_active' => true,
                'status' => 'active',
            ],
        ];

        foreach ($branches as $branch) {
            DB::table('branches')->updateOrInsert(
                [
                    'code' => $branch['code'],
                ],
                $this->onlyExistingColumns(
                    'branches',
                    [
                        ...$branch,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                )
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Pickup and Delivery Pricing
    |--------------------------------------------------------------------------
    */

    private function seedBranchPricingRules(): void
    {
        $services = DB::table('service_types')
            ->whereIn(
                'code',
                ['standard', 'express', 'same_day']
            )
            ->get();

        $branches = DB::table('branches')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        foreach ($branches as $branch) {
            foreach ($services as $service) {
                $pricing = $this->branchPricingForService(
                    $service->code
                );

                $this->saveBranchLocalPricingRule(
                    branchId: (int) $branch->id,
                    serviceTypeId: (int) $service->id,
                    chargeType: 'pickup',
                    baseRadiusKm: $pricing['base_radius_km'],
                    baseFee: $pricing['base_pickup_fee'],
                    additionalDistanceFee: $pricing['pickup_extra_per_km'],
                    maximumRadiusKm: $pricing['max_pickup_distance_km']
                );

                $this->saveBranchLocalPricingRule(
                    branchId: (int) $branch->id,
                    serviceTypeId: (int) $service->id,
                    chargeType: 'delivery',
                    baseRadiusKm: $pricing['base_radius_km'],
                    baseFee: $pricing['base_delivery_fee'],
                    additionalDistanceFee: $pricing['delivery_extra_per_km'],
                    maximumRadiusKm: $pricing['max_delivery_distance_km']
                );
            }
        }
    }

    // private function saveBranchLocalPricingRule(
    //     int $branchId,
    //     int $serviceTypeId,
    //     string $chargeType,
    //     float $baseRadiusKm,
    //     float $baseFee,
    //     float $additionalDistanceFee,
    //     float $maximumRadiusKm
    // ): void {
    //     DB::table('branch_pricing_rules')->updateOrInsert(
    //         [
    //             'branch_id' => $branchId,
    //             'service_type_id' => $serviceTypeId,
    //             'merchant_id' => null,
    //             'charge_type' => $chargeType,
    //         ],
    //         $this->onlyExistingColumns(
    //             'branch_pricing_rules',
    //             [
    //                 'branch_id' => $branchId,
    //                 'service_type_id' => $serviceTypeId,
    //                 'merchant_id' => null,
    //                 'charge_type' => $chargeType,

    //                 'base_radius_km' => $baseRadiusKm,
    //                 'base_fee' => $baseFee,

    //                 'additional_distance_unit_km' => 1,
    //                 'additional_distance_fee' =>
    //                     $additionalDistanceFee,

    //                 'maximum_radius_km' =>
    //                     $maximumRadiusKm,

    //                 'is_active' => true,
    //                 'created_at' => now(),
    //                 'updated_at' => now(),
    //             ]
    //         )
    //     );
    // }

    private function saveBranchLocalPricingRule(
        int $branchId,
        int $serviceTypeId,
        string $chargeType,
        float $baseRadiusKm,
        float $baseFee,
        float $additionalDistanceFee,
        float $maximumRadiusKm
    ): void {
        $match = [
            'branch_id' => $branchId,
            'service_type_id' => $serviceTypeId,
        ];

        if (
            Schema::hasColumn(
                'branch_pricing_rules',
                'merchant_id'
            )
        ) {
            $match['merchant_id'] = null;
        }

        if (
            Schema::hasColumn(
                'branch_pricing_rules',
                'charge_type'
            )
        ) {
            $match['charge_type'] = $chargeType;
        }

        $values = [
            'branch_id' => $branchId,
            'service_type_id' => $serviceTypeId,
            'merchant_id' => null,
            'charge_type' => $chargeType,

            'base_radius_km' => $baseRadiusKm,
            'base_fee' => $baseFee,

            'additional_distance_unit_km' => 1,
            'additional_distance_fee' =>
            $additionalDistanceFee,

            'maximum_radius_km' =>
            $maximumRadiusKm,

            /*
         * Old-schema compatibility fields.
         */
            'base_pickup_fee' =>
            $chargeType === 'pickup'
                ? $baseFee
                : 0,

            'base_delivery_fee' =>
            $chargeType === 'delivery'
                ? $baseFee
                : 0,

            'pickup_extra_per_km' =>
            $chargeType === 'pickup'
                ? $additionalDistanceFee
                : 0,

            'delivery_extra_per_km' =>
            $chargeType === 'delivery'
                ? $additionalDistanceFee
                : 0,

            'max_pickup_distance_km' =>
            $chargeType === 'pickup'
                ? $maximumRadiusKm
                : null,

            'max_delivery_distance_km' =>
            $chargeType === 'delivery'
                ? $maximumRadiusKm
                : null,

            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('branch_pricing_rules')->updateOrInsert(
            $match,
            $this->onlyExistingColumns(
                'branch_pricing_rules',
                $values
            )
        );
    }

    private function branchPricingForService(
        string $serviceCode
    ): array {
        return match ($serviceCode) {
            'express' => [
                'base_radius_km' => 5,
                'base_pickup_fee' => 40,
                'base_delivery_fee' => 70,
                'pickup_extra_per_km' => 20,
                'delivery_extra_per_km' => 25,
                'max_pickup_distance_km' => 20,
                'max_delivery_distance_km' => 25,
            ],

            'same_day' => [
                'base_radius_km' => 5,
                'base_pickup_fee' => 60,
                'base_delivery_fee' => 100,
                'pickup_extra_per_km' => 30,
                'delivery_extra_per_km' => 40,
                'max_pickup_distance_km' => 15,
                'max_delivery_distance_km' => 15,
            ],

            default => [
                'base_radius_km' => 5,
                'base_pickup_fee' => 30,
                'base_delivery_fee' => 50,
                'pickup_extra_per_km' => 15,
                'delivery_extra_per_km' => 20,
                'max_pickup_distance_km' => 20,
                'max_delivery_distance_km' => 25,
            ],
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Inter-Branch Transfer Counts
    |--------------------------------------------------------------------------
    |
    | This replaces branch_transfer_lanes.
    |
    | Branch pair -> transfer count -> transfer-count rate.
    |
    */

    private function seedInterBranchTransferCounts(): void
    {
        $branchIds = DB::table('branches')
            ->whereIn('code', [
                'KTM-MAIN',
                'BKT-MAIN',
                'PKR-MAIN',
                'BRT-MAIN',
                'ITH-MAIN',
                'DMK-MAIN',
            ])
            ->pluck('id', 'code');

        $pairs = [
            [
                'from' => 'KTM-MAIN',
                'to' => 'BKT-MAIN',
                'transfer_count' => 0,
            ],
            [
                'from' => 'KTM-MAIN',
                'to' => 'PKR-MAIN',
                'transfer_count' => 1,
            ],
            [
                'from' => 'KTM-MAIN',
                'to' => 'ITH-MAIN',
                'transfer_count' => 1,
            ],
            [
                'from' => 'KTM-MAIN',
                'to' => 'BRT-MAIN',
                'transfer_count' => 1,
            ],
            [
                'from' => 'KTM-MAIN',
                'to' => 'DMK-MAIN',
                'transfer_count' => 2,
            ],
            [
                'from' => 'PKR-MAIN',
                'to' => 'BRT-MAIN',
                'transfer_count' => 2,
            ],
            [
                'from' => 'PKR-MAIN',
                'to' => 'ITH-MAIN',
                'transfer_count' => 2,
            ],
            [
                'from' => 'PKR-MAIN',
                'to' => 'DMK-MAIN',
                'transfer_count' => 3,
            ],
            [
                'from' => 'ITH-MAIN',
                'to' => 'BRT-MAIN',
                'transfer_count' => 0,
            ],
            [
                'from' => 'ITH-MAIN',
                'to' => 'DMK-MAIN',
                'transfer_count' => 1,
            ],
            [
                'from' => 'BRT-MAIN',
                'to' => 'DMK-MAIN',
                'transfer_count' => 1,
            ],
        ];

        foreach ($pairs as $pair) {
            $fromBranchId = $branchIds[$pair['from']] ?? null;
            $toBranchId = $branchIds[$pair['to']] ?? null;

            if (!$fromBranchId || !$toBranchId) {
                continue;
            }

            DB::table(
                'inter_branch_transfer_counts'
            )->updateOrInsert(
                [
                    'from_branch_id' => $fromBranchId,
                    'to_branch_id' => $toBranchId,
                ],
                $this->onlyExistingColumns(
                    'inter_branch_transfer_counts',
                    [
                        'from_branch_id' => $fromBranchId,
                        'to_branch_id' => $toBranchId,
                        'transfer_count' =>
                        $pair['transfer_count'],

                        'is_bidirectional' => true,
                        'is_active' => true,

                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                )
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Transfer Count Rates
    |--------------------------------------------------------------------------
    */

    private function seedTransferCountRates(): void
    {
        $rates = [
            0 => 79,
            1 => 149,
            2 => 169,
            3 => 189,
            4 => 209,
            5 => 229,
        ];

        foreach ($rates as $transferCount => $rate) {
            DB::table('transfer_count_rates')
                ->updateOrInsert(
                    [
                        'transfer_count' =>
                        $transferCount,

                        'service_type_id' => null,
                        'merchant_id' => null,
                    ],
                    $this->onlyExistingColumns(
                        'transfer_count_rates',
                        [
                            'transfer_count' =>
                            $transferCount,

                            'rate' => $rate,

                            'service_type_id' => null,
                            'merchant_id' => null,

                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    )
                );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Weight Rates
    |--------------------------------------------------------------------------
    */

    private function seedWeightRateRules(): void
    {
        $services = DB::table('service_types')
            ->whereIn(
                'code',
                ['standard', 'express', 'same_day']
            )
            ->get();

        foreach ($services as $service) {
            $weightRule = match ($service->code) {
                'express' => [
                    'base_weight_kg' => 1,
                    'base_weight_fee' => 30,
                    'additional_weight_unit_kg' => 1,
                    'additional_weight_fee' => 30,
                    'maximum_weight_kg' => 50,
                ],

                'same_day' => [
                    'base_weight_kg' => 1,
                    'base_weight_fee' => 40,
                    'additional_weight_unit_kg' => 1,
                    'additional_weight_fee' => 40,
                    'maximum_weight_kg' => 20,
                ],

                default => [
                    'base_weight_kg' => 1,
                    'base_weight_fee' => 25,
                    'additional_weight_unit_kg' => 1,
                    'additional_weight_fee' => 25,
                    'maximum_weight_kg' => 100,
                ],
            };

            DB::table('weight_rate_rules')
                ->updateOrInsert(
                    [
                        'service_type_id' =>
                        $service->id,

                        'merchant_id' => null,
                    ],
                    $this->onlyExistingColumns(
                        'weight_rate_rules',
                        [
                            'service_type_id' =>
                            $service->id,

                            'merchant_id' => null,

                            ...$weightRule,

                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    )
                );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Fragile / Non-Fragile Rates
    |--------------------------------------------------------------------------
    */

    private function seedParcelHandlingRates(): void
    {
        DB::table('parcel_handling_rates')
            ->updateOrInsert(
                [
                    'handling_type' => 'fragile',
                    'service_type_id' => null,
                    'merchant_id' => null,
                ],
                $this->onlyExistingColumns(
                    'parcel_handling_rates',
                    [
                        'handling_type' => 'fragile',

                        'calculation_type' =>
                        'percentage_with_minimum',

                        'service_type_id' => null,
                        'merchant_id' => null,

                        'fixed_fee' => null,
                        'percentage' => 10,
                        'minimum_fee' => 50,
                        'per_kg_fee' => null,

                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                )
            );

        DB::table('parcel_handling_rates')
            ->updateOrInsert(
                [
                    'handling_type' => 'non_fragile',
                    'service_type_id' => null,
                    'merchant_id' => null,
                ],
                $this->onlyExistingColumns(
                    'parcel_handling_rates',
                    [
                        'handling_type' => 'non_fragile',
                        'calculation_type' => 'fixed',

                        'service_type_id' => null,
                        'merchant_id' => null,

                        'fixed_fee' => 0,
                        'percentage' => 0,
                        'minimum_fee' => 0,
                        'per_kg_fee' => 0,

                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                )
            );
    }

    /*
    |--------------------------------------------------------------------------
    | POD Rates
    |--------------------------------------------------------------------------
    */

    private function seedCodRateRules(): void
    {
        DB::table('pod_rate_rules')->updateOrInsert(
            [
                'service_type_id' => null,
                'merchant_id' => null,
            ],
            $this->onlyExistingColumns(
                'pod_rate_rules',
                [
                    'service_type_id' => null,
                    'merchant_id' => null,

                    'calculation_type' =>
                    'percentage_with_minimum',

                    'fixed_fee' => null,
                    'percentage' => 1,
                    'minimum_fee' => 20,
                    'maximum_fee' => 500,

                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function onlyExistingColumns(
        string $table,
        array $data
    ): array {
        return collect($data)
            ->filter(
                fn(
                    mixed $value,
                    string $column
                ): bool => Schema::hasColumn(
                    $table,
                    $column
                )
            )
            ->toArray();
    }

    private function showCounts(): void
    {
        $tables = [
            'branches' =>
            'Branches',

            'service_types' =>
            'Service types',

            'branch_pricing_rules' =>
            'Branch pricing rules',

            'inter_branch_transfer_counts' =>
            'Transfer-count pairs',

            'transfer_count_rates' =>
            'Transfer-count rates',

            'weight_rate_rules' =>
            'Weight rules',

            'parcel_handling_rates' =>
            'Handling rules',

            'pod_rate_rules' =>
            'POD rules',
        ];

        foreach ($tables as $table => $label) {
            $this->command?->line(
                "{$label}: " .
                    DB::table($table)->count()
            );
        }
    }
}
