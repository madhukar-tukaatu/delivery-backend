<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KathmanduBranchRouteRateSeeder extends Seeder
{
    public function run(): void
    {
        $routes = [
            'Kathmandu Branch' => 79,
            'Itahari Branch' => 149,
            'Birtamode Branch' => 149,
            'Damak Branch' => 169,
            'Pokhara Branch' => 149,
            'Bhairahawa Branch' => 149,
            'Biratnagar Branch' => 149,
            'Birgunj Branch' => 149,
            'Chitwan-Bharatpur Branch' => 149,
            'Banepa Branch' => 149,
            'Dharan Branch' => 169,
            'Janakpur Branch' => 149,
            'Nepalgunj Branch' => 149,
            'Mahendranagar Branch' => 149,
            'Birendranagar Branch' => 149,
            'Dhangadhi Branch' => 149,
            'Lahan Branch' => 169,
            'Hetauda Branch' => 149,
            'Inaruwa Branch' => 169,
            'Bardibas Branch' => 169,
            'Butwol Branch' => 169,
            'Dhankuta Branch' => 189,
            'Ilam Branch' => 169,
            'Baglung Branch' => 169,
        ];

        $kathmandu = DB::table('branches')
            ->where('name', 'Kathmandu Branch')
            ->first();

        if (!$kathmandu) {
            throw ValidationException::withMessages([
                'branches' => [
                    'Kathmandu Branch was not found.',
                ],
            ]);
        }

        foreach ($routes as $destinationName => $rate) {
            $destination = DB::table('branches')
                ->where('name', $destinationName)
                ->first();

            if (!$destination) {
                $this->command?->warn(
                    "Skipped missing branch: {$destinationName}"
                );

                continue;
            }

            DB::table('branch_route_rates')
                ->updateOrInsert(
                    [
                        'pickup_branch_id' =>
                            $kathmandu->id,

                        'delivery_branch_id' =>
                            $destination->id,
                    ],
                    [
                        'base_rate' => $rate,
                        'is_active' => true,

                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

            /*
             * Add reverse route as well.
             *
             * Remove this block if reverse route prices
             * are managed separately.
             */
            DB::table('branch_route_rates')
                ->updateOrInsert(
                    [
                        'pickup_branch_id' =>
                            $destination->id,

                        'delivery_branch_id' =>
                            $kathmandu->id,
                    ],
                    [
                        'base_rate' => $rate,
                        'is_active' => true,

                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
        }
    }
}