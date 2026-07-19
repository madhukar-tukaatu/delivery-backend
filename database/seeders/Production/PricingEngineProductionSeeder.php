<?php

namespace Database\Seeders\Production;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PricingEngineProductionSeeder extends Seeder
{
    public function run(): void
    {
        DB::disableQueryLog();

        $this->seedServiceTypes();
        $this->seedBranchPricingRules();
        $this->seedBranchTransferLanes();

        $this->command?->info('Production pricing engine seeded successfully.');
        $this->command?->info('Service Types: ' . DB::table('service_types')->count());
        $this->command?->info('Pricing Rules: ' . DB::table('branch_pricing_rules')->count());
        $this->command?->info('Transfer Lanes: ' . DB::table('branch_transfer_lanes')->count());
    }

    private function seedServiceTypes(): void
    {
        $rows = [
            [
                'code' => 'standard',
                'name' => 'Standard',
                'description' => 'Normal delivery service.',
                'price_multiplier' => 1,
                'fixed_addon_fee' => 0,
                'estimated_min_hours' => 48,
                'estimated_max_hours' => 72,
                'pickup_cutoff_time' => null,
                'same_day_only' => false,
                'is_active' => true,
            ],
            [
                'code' => 'express',
                'name' => 'Express',
                'description' => 'Priority delivery service.',
                'price_multiplier' => 1.35,
                'fixed_addon_fee' => 30,
                'estimated_min_hours' => 24,
                'estimated_max_hours' => 48,
                'pickup_cutoff_time' => null,
                'same_day_only' => false,
                'is_active' => true,
            ],
            [
                'code' => 'same_day',
                'name' => 'Same Day',
                'description' => 'Same day delivery service for active service areas only.',
                'price_multiplier' => 1.80,
                'fixed_addon_fee' => 80,
                'estimated_min_hours' => 4,
                'estimated_max_hours' => 12,
                'pickup_cutoff_time' => '14:00:00',
                'same_day_only' => true,
                'is_active' => true,
            ],
        ];

        foreach ($rows as $row) {
            DB::table('service_types')->updateOrInsert(
                ['code' => $row['code']],
                $this->cols('service_types', array_merge($row, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]))
            );
        }
    }

    private function seedBranchPricingRules(): void
    {
        $services = DB::table('service_types')
            ->whereIn('code', ['standard', 'express', 'same_day'])
            ->get()
            ->keyBy('code');

        $branches = DB::table('branches')
            ->orderBy('id')
            ->get();

        foreach ($branches as $branch) {
            foreach ($services as $service) {
                DB::table('branch_pricing_rules')->updateOrInsert(
                    [
                        'branch_id' => $branch->id,
                        'service_type_id' => $service->id,
                    ],
                    $this->cols('branch_pricing_rules', array_merge(
                        $this->pricingFor($service->code, $branch->type ?? null),
                        [
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    ))
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

        /*
         * Production default:
         * Create transfer lanes only for active branches with coordinates.
         *
         * To create lanes for every branch in DB, set:
         * PRICE_LANES_ALL_BRANCHES=true
         */
        $query = DB::table('branches')->orderBy('id');

        if (!env('PRICE_LANES_ALL_BRANCHES', false)) {
            if (Schema::hasColumn('branches', 'is_active')) {
                $query->where('is_active', true);
            }

            if (Schema::hasColumn('branches', 'status')) {
                $query->where('status', 'active');
            }

            if (Schema::hasColumn('branches', 'latitude')) {
                $query->whereNotNull('latitude');
            }

            if (Schema::hasColumn('branches', 'longitude')) {
                $query->whereNotNull('longitude');
            }
        }

        $branches = $query->get();

        foreach ($branches as $from) {
            foreach ($branches as $to) {
                if ((int) $from->id === (int) $to->id) {
                    continue;
                }

                foreach ($services as $service) {
                    DB::table('branch_transfer_lanes')->updateOrInsert(
                        [
                            'from_branch_id' => $from->id,
                            'to_branch_id' => $to->id,
                            'service_type_id' => $service->id,
                        ],
                        $this->cols('branch_transfer_lanes', array_merge(
                            $this->transferFor($service->code, $from, $to),
                            [
                                'is_active' => true,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        ))
                    );
                }
            }
        }
    }

    private function pricingFor(string $serviceCode, ?string $branchType = null): array
    {
        $isSubBranch = $branchType === 'sub_branch';

        return match ($serviceCode) {
            'express' => [
                'base_radius_km' => $isSubBranch ? 4 : 5,
                'base_pickup_fee' => 40,
                'base_delivery_fee' => 70,
                'pickup_extra_per_km' => 20,
                'delivery_extra_per_km' => 25,
                'max_pickup_distance_km' => 20,
                'max_delivery_distance_km' => 25,
                'base_weight_kg' => 1,
                'extra_weight_per_kg' => 30,
                'pod_fee_fixed' => 25,
                'pod_fee_percentage' => 0,
            ],
            'same_day' => [
                'base_radius_km' => $isSubBranch ? 4 : 5,
                'base_pickup_fee' => 60,
                'base_delivery_fee' => 100,
                'pickup_extra_per_km' => 30,
                'delivery_extra_per_km' => 40,
                'max_pickup_distance_km' => 15,
                'max_delivery_distance_km' => 15,
                'base_weight_kg' => 1,
                'extra_weight_per_kg' => 40,
                'pod_fee_fixed' => 30,
                'pod_fee_percentage' => 0,
            ],
            default => [
                'base_radius_km' => $isSubBranch ? 4 : 5,
                'base_pickup_fee' => 30,
                'base_delivery_fee' => 50,
                'pickup_extra_per_km' => 15,
                'delivery_extra_per_km' => 20,
                'max_pickup_distance_km' => 20,
                'max_delivery_distance_km' => 25,
                'base_weight_kg' => 1,
                'extra_weight_per_kg' => 25,
                'pod_fee_fixed' => 20,
                'pod_fee_percentage' => 0,
            ],
        };
    }

    private function transferFor(string $serviceCode, object $from, object $to): array
    {
        $sameCity = ($from->city ?? null) && ($to->city ?? null) && $from->city === $to->city;

        return match ($serviceCode) {
            'express' => [
                'base_transfer_fee' => $sameCity ? 60 : 120,
                'per_kg_fee' => $sameCity ? 8 : 15,
                'estimated_hours' => $sameCity ? 12 : 24,
            ],
            'same_day' => [
                'base_transfer_fee' => $sameCity ? 100 : 180,
                'per_kg_fee' => $sameCity ? 15 : 25,
                'estimated_hours' => $sameCity ? 6 : 12,
            ],
            default => [
                'base_transfer_fee' => $sameCity ? 40 : 80,
                'per_kg_fee' => $sameCity ? 5 : 10,
                'estimated_hours' => $sameCity ? 24 : 48,
            ],
        };
    }

    private function cols(string $table, array $data): array
    {
        return collect($data)
            ->filter(fn ($value, $column) => Schema::hasColumn($table, $column))
            ->toArray();
    }
}