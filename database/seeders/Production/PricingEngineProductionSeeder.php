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

        if (Schema::hasTable('service_types')) {
            $this->command?->info(
                'Service Types: ' . DB::table('service_types')->count()
            );
        }

        if (Schema::hasTable('branch_pricing_rules')) {
            $this->command?->info(
                'Pricing Rules: ' . DB::table('branch_pricing_rules')->count()
            );
        }

        if (Schema::hasTable('branch_transfer_lanes')) {
            $this->command?->info(
                'Transfer Lanes: ' . DB::table('branch_transfer_lanes')->count()
            );
        }
    }

    private function seedServiceTypes(): void
    {
        if (!Schema::hasTable('service_types')) {
            $this->command?->warn(
                'service_types table does not exist. Skipping service types.'
            );

            return;
        }

        $rows = [
            [
                'code' => 'standard',
                'name' => 'Standard',
                'description' => 'Normal delivery service.',
                'price_multiplier' => 1.00,
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
                'description' =>
                    'Same-day delivery for supported service areas.',
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
                [
                    'code' => $row['code'],
                ],
                $this->cols(
                    'service_types',
                    array_merge($row, [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                )
            );
        }
    }

    private function seedBranchPricingRules(): void
    {
        if (!Schema::hasTable('branch_pricing_rules')) {
            $this->command?->warn(
                'branch_pricing_rules table does not exist. Skipping pricing rules.'
            );

            return;
        }

        if (!Schema::hasTable('branches')) {
            $this->command?->warn(
                'branches table does not exist. Skipping pricing rules.'
            );

            return;
        }

        $requiredColumns = [
            'pickup_branch_id',
            'delivery_branch_id',
            'service_type_id',
        ];

        foreach ($requiredColumns as $column) {
            if (!Schema::hasColumn('branch_pricing_rules', $column)) {
                $this->command?->warn(
                    "branch_pricing_rules.{$column} does not exist. " .
                    'The migration is not using the expected route-based schema.'
                );

                return;
            }
        }

        $services = DB::table('service_types')
            ->whereIn('code', ['standard', 'express', 'same_day'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $branches = $this->activeBranches();

        if ($branches->isEmpty()) {
            $this->command?->warn(
                'No eligible branches found. Pricing rules were not created.'
            );

            return;
        }

        foreach ($branches as $pickupBranch) {
            foreach ($branches as $deliveryBranch) {
                foreach ($services as $service) {
                    $isLocal =
                        (int) $pickupBranch->id ===
                        (int) $deliveryBranch->id;

                    $pricing = $this->pricingFor(
                        $service->code,
                        $pickupBranch,
                        $deliveryBranch
                    );

                    DB::table('branch_pricing_rules')->updateOrInsert(
                        [
                            'pickup_branch_id' => $pickupBranch->id,
                            'delivery_branch_id' => $deliveryBranch->id,
                            'service_type_id' => $service->id,
                        ],
                        $this->cols(
                            'branch_pricing_rules',
                            array_merge($pricing, [
                                'route_type' => $isLocal
                                    ? 'local'
                                    : 'transfer',

                                'bidirectional' => !$isLocal,
                                'effective_from' => now(),
                                'effective_to' => null,
                                'is_active' => true,

                                'notes' => $isLocal
                                    ? 'Default local branch pricing rule.'
                                    : 'Default inter-branch pricing rule.',

                                'created_at' => now(),
                                'updated_at' => now(),
                            ])
                        )
                    );
                }
            }
        }
    }

    private function seedBranchTransferLanes(): void
    {
        if (!Schema::hasTable('branch_transfer_lanes')) {
            $this->command?->warn(
                'branch_transfer_lanes table does not exist. Skipping transfer lanes.'
            );

            return;
        }

        if (!Schema::hasTable('branches')) {
            return;
        }

        $services = DB::table('service_types')
            ->whereIn('code', ['standard', 'express', 'same_day'])
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $branches = $this->activeBranches(
            requireCoordinates: !env('PRICE_LANES_ALL_BRANCHES', false)
        );

        foreach ($branches as $fromBranch) {
            foreach ($branches as $toBranch) {
                if (
                    (int) $fromBranch->id ===
                    (int) $toBranch->id
                ) {
                    continue;
                }

                foreach ($services as $service) {
                    DB::table('branch_transfer_lanes')->updateOrInsert(
                        [
                            'from_branch_id' => $fromBranch->id,
                            'to_branch_id' => $toBranch->id,
                            'service_type_id' => $service->id,
                        ],
                        $this->cols(
                            'branch_transfer_lanes',
                            array_merge(
                                $this->transferFor(
                                    $service->code,
                                    $fromBranch,
                                    $toBranch
                                ),
                                [
                                    'is_active' => true,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]
                            )
                        )
                    );
                }
            }
        }
    }

    private function activeBranches(bool $requireCoordinates = false)
    {
        $query = DB::table('branches')
            ->orderBy('id');

        if (Schema::hasColumn('branches', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if (Schema::hasColumn('branches', 'is_active')) {
            $query->where('is_active', true);
        } elseif (Schema::hasColumn('branches', 'status')) {
            $query->where('status', 'active');
        }

        if ($requireCoordinates) {
            if (Schema::hasColumn('branches', 'latitude')) {
                $query->whereNotNull('latitude');
            }

            if (Schema::hasColumn('branches', 'longitude')) {
                $query->whereNotNull('longitude');
            }
        }

        return $query->get();
    }

    private function pricingFor(
        string $serviceCode,
        object $pickupBranch,
        object $deliveryBranch
    ): array {
        $isLocal =
            (int) $pickupBranch->id ===
            (int) $deliveryBranch->id;

        $sameCity = $this->sameCity(
            $pickupBranch,
            $deliveryBranch
        );

        $isSubBranch =
            ($pickupBranch->type ?? null) === 'sub_branch';

        $includedDistance = $isSubBranch ? 4 : 5;
        $includedWeight = 1.5;

        return match ($serviceCode) {
            'express' => [
                'base_price' => $isLocal
                    ? 110
                    : ($sameCity ? 150 : 220),

                'included_weight_kg' => $includedWeight,
                'included_distance_km' => $includedDistance,

                'extra_weight_rate' => $isLocal ? 30 : 40,
                'extra_distance_rate' => $isLocal ? 20 : 25,

                'minimum_charge' => $isLocal
                    ? 110
                    : ($sameCity ? 150 : 220),

                'maximum_charge' => null,
            ],

            'same_day' => [
                'base_price' => $isLocal
                    ? 160
                    : ($sameCity ? 220 : 320),

                'included_weight_kg' => $includedWeight,
                'included_distance_km' => $includedDistance,

                'extra_weight_rate' => $isLocal ? 40 : 50,
                'extra_distance_rate' => $isLocal ? 30 : 40,

                'minimum_charge' => $isLocal
                    ? 160
                    : ($sameCity ? 220 : 320),

                'maximum_charge' => null,
            ],

            default => [
                'base_price' => $isLocal
                    ? 80
                    : ($sameCity ? 120 : 170),

                'included_weight_kg' => $includedWeight,
                'included_distance_km' => $includedDistance,

                'extra_weight_rate' => $isLocal ? 20 : 30,
                'extra_distance_rate' => 6,

                'minimum_charge' => $isLocal
                    ? 80
                    : ($sameCity ? 120 : 170),

                'maximum_charge' => null,
            ],
        };
    }

    private function transferFor(
        string $serviceCode,
        object $fromBranch,
        object $toBranch
    ): array {
        $sameCity = $this->sameCity(
            $fromBranch,
            $toBranch
        );

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

    private function sameCity(
        object $fromBranch,
        object $toBranch
    ): bool {
        $fromCity = trim(
            strtolower((string) ($fromBranch->city ?? ''))
        );

        $toCity = trim(
            strtolower((string) ($toBranch->city ?? ''))
        );

        return $fromCity !== '' &&
            $toCity !== '' &&
            $fromCity === $toCity;
    }

    private function cols(string $table, array $data): array
    {
        return collect($data)
            ->filter(
                fn ($value, $column) =>
                    Schema::hasColumn($table, $column)
            )
            ->toArray();
    }
}