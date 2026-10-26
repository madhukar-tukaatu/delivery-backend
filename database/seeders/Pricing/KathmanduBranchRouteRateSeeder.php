<?php

namespace Database\Seeders\Pricing;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class KathmanduBranchRouteRateSeeder extends Seeder
{
    public function run(): void
    {
        $routes = [

            ['code' => 'NP-KTM-MAIN', 'rate' => 79],

            ['code' => 'NP-BR-IT',  'rate' => 149],
            ['code' => 'NP-BR-BTM', 'rate' => 149],
            ['code' => 'NP-BR-DMK', 'rate' => 169],
            ['code' => 'PKR-MAIN-ZONE', 'rate' => 149],
            ['code' => 'NP-BR-BHW', 'rate' => 149],
            ['code' => 'BRT-MAIN-ZONE', 'rate' => 149],
            ['code' => 'NP-BR-BRG', 'rate' => 149],
            ['code' => 'NP-BR-C', 'rate' => 149],
            ['code' => 'NP-BR-BNP', 'rate' => 149],
            ['code' => 'NP-BR-DHR', 'rate' => 169],
            ['code' => 'NP-BR-JNK', 'rate' => 149],
            ['code' => 'NP-BR-NPJ', 'rate' => 149],
            ['code' => 'NP-BR-K', 'rate' => 149],
            ['code' => 'NP-BR-S', 'rate' => 149],
            ['code' => 'NP-BR-DHG', 'rate' => 149],
            ['code' => 'NP-BR-LHN', 'rate' => 169],
            ['code' => 'NP-BR-HTD', 'rate' => 149],
            ['code' => 'NP-BR-INR', 'rate' => 169],
            ['code' => 'NP-BR-BDB', 'rate' => 169],
            ['code' => 'NP-BR-BTL', 'rate' => 169],
            ['code' => 'NP-BR-DKT', 'rate' => 189],
            ['code' => 'NP-BR-I', 'rate' => 169],
            ['code' => 'NP-BR-BGL', 'rate' => 169],

        ];

        $kathmandu = DB::table('branches')
            ->where('code', 'NP-KTM-MAIN')
            ->first();

        if (!$kathmandu) {
            throw new RuntimeException('Kathmandu Main Branch not found.');
        }

        // Current schema keys route rates by coverage location
        // (branches.coverage_location_id); older databases keyed them by branch.
        $byCoverage = Schema::hasColumn('branch_route_rates', 'pickup_coverage_location_id');
        $pickupKey = $byCoverage ? 'pickup_coverage_location_id' : 'pickup_branch_id';
        $deliveryKey = $byCoverage ? 'delivery_coverage_location_id' : 'delivery_branch_id';
        $keyOf = function (object $branch) use ($byCoverage) {
            if (!$byCoverage) {
                return $branch->id;
            }
            if (!empty($branch->coverage_location_id)) {
                return $branch->coverage_location_id;
            }

            // Fall back to the coverage zone that points at the branch.
            return DB::table('coverage_locations')
                ->where('branch_id', $branch->id)
                ->orderByRaw("type = 'main_branch_zone' DESC")
                ->orderBy('id')
                ->value('id');
        };

        $kathmanduKey = $keyOf($kathmandu);
        if (!$kathmanduKey) {
            $this->command?->warn('Kathmandu Main Branch has no coverage location; skipping route rates.');

            return;
        }

        foreach ($routes as $route) {
            $destination = DB::table('branches')
                ->where('code', $route['code'])
                ->first();

            if (!$destination) {
                $this->command?->warn("Branch {$route['code']} not found.");

                continue;
            }

            $destinationKey = $keyOf($destination);
            if (!$destinationKey) {
                $this->command?->warn("Branch {$route['code']} has no coverage location; skipped.");

                continue;
            }

            $pairs = [[$kathmanduKey, $destinationKey]];
            if ((int) $destinationKey !== (int) $kathmanduKey) {
                $pairs[] = [$destinationKey, $kathmanduKey];
            }

            foreach ($pairs as [$from, $to]) {
                $match = [$pickupKey => $from, $deliveryKey => $to];
                $exists = DB::table('branch_route_rates')->where($match)->exists();

                DB::table('branch_route_rates')->updateOrInsert($match, array_filter([
                    'base_rate' => $route['rate'],
                    'is_active' => true,
                    'created_at' => $exists ? null : now(),
                    'updated_at' => now(),
                ], fn ($v) => $v !== null));
            }

            $this->command?->info("{$kathmandu->code} <-> {$destination->code} = Rs. {$route['rate']}");
        }
    }
}