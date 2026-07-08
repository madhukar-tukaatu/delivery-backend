<?php

namespace Database\Seeders\Performance;

use Database\Seeders\Routing\NepalRoutingSeeder;
use Illuminate\Database\Seeder;

class PerformanceSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(20260704);

        /*
        |--------------------------------------------------------------------------
        | Routing data must exist before shipments
        |--------------------------------------------------------------------------
        | Branch coordinates, delivery route segments, coverage radius, etc.
        */
        $this->call([
            NepalRoutingSeeder::class,

            MerchantSeeder::class,
            CustomerSeeder::class,
            StaffSeeder::class,
            RiderSeeder::class,

            ShipmentSeeder::class,
            PickupSeeder::class,
            DispatchSeeder::class,
            DeliverySeeder::class,
            CODSeeder::class,
            SettlementSeeder::class,
            InvoiceSeeder::class,
            NotificationSeeder::class,
        ]);
    }
}