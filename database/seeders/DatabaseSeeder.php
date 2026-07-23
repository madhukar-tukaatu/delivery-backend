<?php

namespace Database\Seeders;

use Database\Seeders\Demo\DemoMerchantSeeder;
use Database\Seeders\Demo\DemoShipmentSeeder;
use Database\Seeders\Demo\DemoUserSeeder;
use Database\Seeders\Pricing\KathmanduBranchRouteRateSeeder;
use Database\Seeders\Nepal\NepalDistrictSeeder;
use Database\Seeders\Nepal\NepalProvinceSeeder;
use Database\Seeders\Performance\PerformanceSeeder;
// use Database\Seeders\Pricing\KathmanduBranchRouteRateSeeder;
use Database\Seeders\Pricing\PricingSettingsSeeder;
use Database\Seeders\Pricing\ServiceTypeSeeder;
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
        | Nepal geography
        |--------------------------------------------------------------------------
        */
        $this->call([
            NepalProvinceSeeder::class,
            NepalDistrictSeeder::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Production branches and operational structure
        |--------------------------------------------------------------------------
        | Branches must exist before branch route rates are inserted.
        */
        $this->call([
            ProductionSeeder::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Pricing configuration
        |--------------------------------------------------------------------------
        | Service types and pricing settings must exist before route pricing.
        */
        $this->call([
            ServiceTypeSeeder::class,
            PricingSettingsSeeder::class,
            KathmanduBranchRouteRateSeeder::class,
            PricingEngineDemoSeeder::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Production admin user
        |--------------------------------------------------------------------------
        */
        $this->call([
            ProductionAdminUserSeeder::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Optional demo data
        |--------------------------------------------------------------------------
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
        */
        if ((bool) env('SEED_PERFORMANCE', false)) {
            $this->call([
                PerformanceSeeder::class,
            ]);
        }
    }
}