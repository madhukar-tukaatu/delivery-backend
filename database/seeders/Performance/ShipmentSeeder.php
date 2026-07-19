<?php

namespace Database\Seeders\Performance;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Services\ShipmentService;

class ShipmentSeeder extends Seeder
{
    public function run(): void
    {
        $count = (int) env('PERFORMANCE_SHIPMENTS', 12000);

        $merchants = Merchant::query()
            ->where('status', 'active')
            ->pluck('id')
            ->values()
            ->all();

        if (empty($merchants)) {
            $this->command?->warn('No merchants found. Run MerchantSeeder first.');
            return;
        }

        $routes = $this->nepalRouteCoordinatePairs();

        if (empty($routes)) {
            $this->command?->warn('No route coordinate pairs configured.');
            return;
        }

        $service = app(ShipmentService::class);

        $this->command?->info("Creating {$count} routed shipments...");

        DB::disableQueryLog();

        for ($i = 1; $i <= $count; $i++) {
            $merchantId = $merchants[array_rand($merchants)];
            $route = $routes[array_rand($routes)];

            $codAmount = $this->randomCodAmount();
            $weight = $this->randomWeight();

            try {
                $service->create([
                    'merchant_order_id' => 'PERF-ORD-' . str_pad((string) $i, 8, '0', STR_PAD_LEFT),

                    /*
                    |--------------------------------------------------------------------------
                    | Pickup details
                    |--------------------------------------------------------------------------
                    */
                    'sender_name' => $route['pickup_name'],
                    'sender_phone' => $this->randomNepalPhone(),
                    'sender_address' => $route['pickup_address'],
                    'sender_city' => $route['pickup_city'],
                    'sender_area' => $route['pickup_area'],

                    'pickup_lat' => $route['pickup_lat'],
                    'pickup_lng' => $route['pickup_lng'],

                    /*
                    |--------------------------------------------------------------------------
                    | Receiver details
                    |--------------------------------------------------------------------------
                    */
                    'receiver_name' => $this->randomCustomerName(),
                    'receiver_phone' => $this->randomNepalPhone(),
                    'receiver_email' => null,
                    'receiver_address' => $route['delivery_address'],
                    'receiver_city' => $route['delivery_city'],
                    'receiver_area' => $route['delivery_area'],

                    'delivery_lat' => $route['delivery_lat'],
                    'delivery_lng' => $route['delivery_lng'],

                    /*
                    |--------------------------------------------------------------------------
                    | Parcel/payment
                    |--------------------------------------------------------------------------
                    */
                    'parcel_type' => 'product',
                    'description' => $this->randomProduct(),
                    'quantity' => random_int(1, 3),
                    'weight' => $weight,
                    'declared_value' => $codAmount,
                    'fragile' => random_int(1, 100) <= 12,

                    'payment_type' => $codAmount > 0 ? 'pod' : 'prepaid',
                    'pod_amount' => $codAmount,
                    'delivery_charge_paid_by' => random_int(1, 100) <= 70 ? 'customer' : 'merchant',

                    /*
                    |--------------------------------------------------------------------------
                    | Important: false means use automatic routing
                    |--------------------------------------------------------------------------
                    */
                    'manual_branch_override' => false,
                    'remarks' => 'Performance seeded shipment with map routing.',
                ], null, $merchantId, 'performance_seed');

            } catch (\Throwable $e) {
                $this->command?->error("Shipment {$i} failed: " . $e->getMessage());
            }

            if ($i % 500 === 0) {
                $this->command?->info("Created {$i} / {$count} shipments...");
            }
        }

        $this->command?->info("Performance shipment seeding completed.");
    }

    private function nepalRouteCoordinatePairs(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Pokhara to Kathmandu/Bhaktapur/Lalitpur
            |--------------------------------------------------------------------------
            */
            [
                'pickup_name' => 'Pokhara Lakeside Merchant',
                'pickup_address' => 'Lakeside, Pokhara',
                'pickup_city' => 'Pokhara',
                'pickup_area' => 'Lakeside',
                'pickup_lat' => 28.2099,
                'pickup_lng' => 83.9593,

                'delivery_address' => 'Bhaktapur Durbar Square, Bhaktapur',
                'delivery_city' => 'Bhaktapur',
                'delivery_area' => 'Durbar Square',
                'delivery_lat' => 27.6720,
                'delivery_lng' => 85.4280,
            ],
            [
                'pickup_name' => 'Pokhara Mahendrapul Merchant',
                'pickup_address' => 'Mahendrapul, Pokhara',
                'pickup_city' => 'Pokhara',
                'pickup_area' => 'Mahendrapul',
                'pickup_lat' => 28.2137,
                'pickup_lng' => 83.9891,

                'delivery_address' => 'New Baneshwor, Kathmandu',
                'delivery_city' => 'Kathmandu',
                'delivery_area' => 'New Baneshwor',
                'delivery_lat' => 27.6885,
                'delivery_lng' => 85.3420,
            ],

            /*
            |--------------------------------------------------------------------------
            | Kathmandu valley internal
            |--------------------------------------------------------------------------
            */
            [
                'pickup_name' => 'New Road Fashion Store',
                'pickup_address' => 'New Road, Kathmandu',
                'pickup_city' => 'Kathmandu',
                'pickup_area' => 'New Road',
                'pickup_lat' => 27.7046,
                'pickup_lng' => 85.3106,

                'delivery_address' => 'Jhamsikhel, Lalitpur',
                'delivery_city' => 'Lalitpur',
                'delivery_area' => 'Jhamsikhel',
                'delivery_lat' => 27.6765,
                'delivery_lng' => 85.3142,
            ],
            [
                'pickup_name' => 'Koteshwor Electronics',
                'pickup_address' => 'Koteshwor, Kathmandu',
                'pickup_city' => 'Kathmandu',
                'pickup_area' => 'Koteshwor',
                'pickup_lat' => 27.6773,
                'pickup_lng' => 85.3490,

                'delivery_address' => 'Suryabinayak, Bhaktapur',
                'delivery_city' => 'Bhaktapur',
                'delivery_area' => 'Suryabinayak',
                'delivery_lat' => 27.6667,
                'delivery_lng' => 85.4333,
            ],

            /*
            |--------------------------------------------------------------------------
            | Biratnagar / Itahari / Dharan
            |--------------------------------------------------------------------------
            */
            [
                'pickup_name' => 'Biratnagar Store',
                'pickup_address' => 'Traffic Chowk, Biratnagar',
                'pickup_city' => 'Biratnagar',
                'pickup_area' => 'Traffic Chowk',
                'pickup_lat' => 26.4525,
                'pickup_lng' => 87.2718,

                'delivery_address' => 'Itahari Chowk, Itahari',
                'delivery_city' => 'Itahari',
                'delivery_area' => 'Itahari Chowk',
                'delivery_lat' => 26.6667,
                'delivery_lng' => 87.2833,
            ],
            [
                'pickup_name' => 'Dharan Fashion Hub',
                'pickup_address' => 'Bhanuchowk, Dharan',
                'pickup_city' => 'Dharan',
                'pickup_area' => 'Bhanuchowk',
                'pickup_lat' => 26.8125,
                'pickup_lng' => 87.2833,

                'delivery_address' => 'Baneshwor, Kathmandu',
                'delivery_city' => 'Kathmandu',
                'delivery_area' => 'Baneshwor',
                'delivery_lat' => 27.6885,
                'delivery_lng' => 85.3420,
            ],

            /*
            |--------------------------------------------------------------------------
            | Butwal / Bhairahawa / Chitwan
            |--------------------------------------------------------------------------
            */
            [
                'pickup_name' => 'Butwal Merchant',
                'pickup_address' => 'Traffic Chowk, Butwal',
                'pickup_city' => 'Butwal',
                'pickup_area' => 'Traffic Chowk',
                'pickup_lat' => 27.7006,
                'pickup_lng' => 83.4484,

                'delivery_address' => 'Narayanghat, Chitwan',
                'delivery_city' => 'Chitwan',
                'delivery_area' => 'Narayanghat',
                'delivery_lat' => 27.6950,
                'delivery_lng' => 84.4300,
            ],
            [
                'pickup_name' => 'Bhairahawa Warehouse',
                'pickup_address' => 'Bhairahawa, Rupandehi',
                'pickup_city' => 'Bhairahawa',
                'pickup_area' => 'Bhairahawa',
                'pickup_lat' => 27.5065,
                'pickup_lng' => 83.4377,

                'delivery_address' => 'Lakeside, Pokhara',
                'delivery_city' => 'Pokhara',
                'delivery_area' => 'Lakeside',
                'delivery_lat' => 28.2099,
                'delivery_lng' => 83.9593,
            ],

            /*
            |--------------------------------------------------------------------------
            | Nepalgunj / Dhangadhi / Surkhet
            |--------------------------------------------------------------------------
            */
            [
                'pickup_name' => 'Nepalgunj Store',
                'pickup_address' => 'Dhamboji, Nepalgunj',
                'pickup_city' => 'Nepalgunj',
                'pickup_area' => 'Dhamboji',
                'pickup_lat' => 28.0500,
                'pickup_lng' => 81.6167,

                'delivery_address' => 'Dhangadhi Main Road',
                'delivery_city' => 'Dhangadhi',
                'delivery_area' => 'Main Road',
                'delivery_lat' => 28.7056,
                'delivery_lng' => 80.5750,
            ],
            [
                'pickup_name' => 'Surkhet Merchant',
                'pickup_address' => 'Birendranagar, Surkhet',
                'pickup_city' => 'Surkhet',
                'pickup_area' => 'Birendranagar',
                'pickup_lat' => 28.6019,
                'pickup_lng' => 81.6339,

                'delivery_address' => 'Kalanki, Kathmandu',
                'delivery_city' => 'Kathmandu',
                'delivery_area' => 'Kalanki',
                'delivery_lat' => 27.6933,
                'delivery_lng' => 85.2817,
            ],
        ];
    }

    private function randomNepalPhone(): string
    {
        return '98' . random_int(10000000, 99999999);
    }

    private function randomCustomerName(): string
    {
        $first = ['Ram', 'Sita', 'Hari', 'Gita', 'Bikash', 'Ramesh', 'Sabina', 'Anita', 'Nirajan', 'Prakash', 'Sunita', 'Rojina', 'Suman', 'Binod', 'Nisha'];
        $last = ['Sharma', 'Rai', 'Thapa', 'Gurung', 'Tamang', 'Maharjan', 'Shrestha', 'Karki', 'Basnet', 'Adhikari', 'KC', 'Bhandari'];

        return $first[array_rand($first)] . ' ' . $last[array_rand($last)];
    }

    private function randomProduct(): string
    {
        $products = [
            'Shoes',
            'T-shirt',
            'Mobile Cover',
            'Cosmetics',
            'Ladies Bag',
            'Watch',
            'Headphone',
            'Book Set',
            'Kitchen Item',
            'Baby Clothes',
            'Herbal Product',
            'Electronic Accessory',
        ];

        return $products[array_rand($products)];
    }

    private function randomWeight(): float
    {
        return round(random_int(5, 80) / 10, 1); // 0.5kg to 8kg
    }

    private function randomCodAmount(): float
    {
        // 80% POD, 20% prepaid
        if (random_int(1, 100) <= 20) {
            return 0;
        }

        return (float) random_int(500, 12000);
    }
}