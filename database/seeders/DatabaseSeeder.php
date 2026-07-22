<?php

namespace Database\Seeders;

use Database\Seeders\Demo\DemoMerchantSeeder;
use Database\Seeders\Demo\DemoShipmentSeeder;
use Database\Seeders\Demo\DemoUserSeeder;
use Database\Seeders\Nepal\NepalDistrictSeeder;
use Database\Seeders\Nepal\NepalProvinceSeeder;
use Database\Seeders\Performance\PerformanceSeeder;
use Database\Seeders\Pricing\BranchRouteRateSeeder;
use Database\Seeders\Pricing\PricingSettingSeeder;
use Database\Seeders\Production\ProductionAdminUserSeeder;
use Database\Seeders\Production\ProductionSeeder;
use Database\Seeders\System\MenuSeeder;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\SettingSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Core system data
        |--------------------------------------------------------------------------
        */
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            MenuSeeder::class,
            SettingSeeder::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Nepal base geography
        |--------------------------------------------------------------------------
        | Provinces and districts should come before branch/sub-branch setup.
        */
        $this->call([
            NepalProvinceSeeder::class,
            NepalDistrictSeeder::class,
        ]);

        $this->call([
            PricingSettingSeeder::class,
            BranchRouteRateSeeder::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Production operational data
        |--------------------------------------------------------------------------
        | This replaces old NepalBranchSeeder and NepalSubBranchSeeder for production.
        | It creates production branches, active/inactive verification status,
        | service types, pricing rules and transfer lanes.
        */
        $this->call([
            ProductionSeeder::class,
            ProductionAdminUserSeeder::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Optional demo data
        |--------------------------------------------------------------------------
        | Keep this OFF in real production.
        | Enable only for demo/staging:
        | SEED_DEMO=true
        */
        if ((bool) env('SEED_DEMO', false)) {
            $this->call([
                DemoUserSeeder::class,
                DemoMerchantSeeder::class,
                DemoShipmentSeeder::class,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Optional performance data
        |--------------------------------------------------------------------------
        | Keep this OFF in real production unless you intentionally want load-test data.
        | Enable only when needed:
        | SEED_PERFORMANCE=true
        */
        if ((bool) env('SEED_PERFORMANCE', false)) {
            $this->call([
                PerformanceSeeder::class,
            ]);
        }
    }
}
