<?php

namespace Database\Seeders\Pricing;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BranchRouteRateSeeder extends Seeder
{
    public function run(): void
    {
        $branches = DB::table('branches')
            ->where('status', 'active')
            ->get()
            ->keyBy('code');

        $routes = [
            ['NP-KTM-MAIN', 'NP-KTM-MAIN', 79],
            ['NP-KTM-MAIN', 'NP-PKR-MAIN', 149],
            ['NP-PKR-MAIN', 'NP-KTM-MAIN', 149],
            ['NP-KTM-MAIN', 'NP-BRT-MAIN', 149],
            ['NP-BRT-MAIN', 'NP-KTM-MAIN', 149],
        ];

        foreach ($routes as [$originCode, $destinationCode, $baseRate]) {
            $origin = $branches->get($originCode);
            $destination = $branches->get($destinationCode);

            if (!$origin || !$destination) {
                continue;
            }

            DB::table('branch_route_rates')->updateOrInsert(
                [
                    'origin_branch_id' => $origin->id,
                    'destination_branch_id' => $destination->id,
                ],
                [
                    'base_rate' => $baseRate,
                    'effective_from' => now()->toDateString(),
                    'effective_to' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}