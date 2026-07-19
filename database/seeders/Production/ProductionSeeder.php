<?php

namespace Database\Seeders\Production;

use Illuminate\Database\Seeder;

class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            NepalBranchProductionSeeder::class,
            CoverageLocationSeeder::class,
            PricingEngineProductionSeeder::class,
        ]);
    }
}