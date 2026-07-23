<?php

namespace Database\Seeders\Pricing;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
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

        foreach ($routes as $route) {

            $destination = DB::table('branches')
                ->where('code', $route['code'])
                ->first();

            if (!$destination) {

                $this->command->warn(
                    "Branch {$route['code']} not found."
                );

                continue;
            }

            DB::table('branch_route_rates')->updateOrInsert(

                [
                    'pickup_branch_id'   => $kathmandu->id,
                    'delivery_branch_id' => $destination->id,
                ],

                [
                    'base_rate'  => $route['rate'],
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            if ($destination->id != $kathmandu->id) {

                DB::table('branch_route_rates')->updateOrInsert(

                    [
                        'pickup_branch_id'   => $destination->id,
                        'delivery_branch_id' => $kathmandu->id,
                    ],

                    [
                        'base_rate'  => $route['rate'],
                        'is_active'  => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            $this->command->info(
                "{$kathmandu->code} ↔ {$destination->code} = Rs. {$route['rate']}"
            );
        }
    }
}