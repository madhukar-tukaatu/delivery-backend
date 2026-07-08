<?php

namespace Database\Seeders;

use Database\Seeders\Demo\DemoMerchantSeeder;
use Database\Seeders\Demo\DemoShipmentSeeder;
use Database\Seeders\Demo\DemoUserSeeder;
use Database\Seeders\Nepal\NepalBranchSeeder;
use Database\Seeders\Nepal\NepalDistrictSeeder;
use Database\Seeders\Nepal\NepalProvinceSeeder;
use Database\Seeders\Nepal\NepalSubBranchSeeder;
use Database\Seeders\Performance\PerformanceSeeder;
use Database\Seeders\System\MenuSeeder;
use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Database\Seeders\System\SettingSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
            MenuSeeder::class,
            SettingSeeder::class,
            NepalProvinceSeeder::class,
            NepalDistrictSeeder::class,
            NepalBranchSeeder::class,
            NepalSubBranchSeeder::class,
            DemoUserSeeder::class,
            DemoMerchantSeeder::class,
            DemoShipmentSeeder::class,
        ]);

        if ((bool) env('SEED_PERFORMANCE', false)) {
            $this->call(PerformanceSeeder::class);
        }
    }
}
