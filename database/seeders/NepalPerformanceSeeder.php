<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class NepalPerformanceSeeder extends Seeder
{
    /**
     * Nepal performance/load-test data for Courier DMS.
     *
     * Default volume creates 10k+ shipments plus related pickups, dispatches,
     * deliveries, POD, settlements, invoices, API logs and notifications.
     *
     * You can override counts from .env:
     * PERFORMANCE_MERCHANTS=500
     * PERFORMANCE_CUSTOMERS=5000
     * PERFORMANCE_SHIPMENTS=12000
     */
    private int $merchantCount;
    private int $customerCount;
    private int $shipmentCount;
    private array $branchIds = [];
    private array $subBranchIds = [];
    private array $districtBranchIds = [];
    private array $riderIds = [];
    private array $pickupStaffIds = [];
    private array $dispatchStaffIds = [];
    private array $bookingStaffIds = [];
    private array $accountsStaffIds = [];
    private array $merchantIds = [];
    private array $merchantApiKeyIds = [];
    private array $customerIds = [];
    private array $shipmentIds = [];
    private array $deliveredShipmentIds = [];
    private int $mainBranchId;
    private int $defaultRateCardId;

    public function run(): void
    {
        DB::disableQueryLog();

        $this->merchantCount = (int) env('PERFORMANCE_MERCHANTS', 500);
        $this->customerCount = (int) env('PERFORMANCE_CUSTOMERS', 5000);
        $this->shipmentCount = (int) env('PERFORMANCE_SHIPMENTS', 12000);

        $this->command?->info('Nepal performance seed started...');

        DB::transaction(function () {
            $this->ensureRoles();
            $this->seedNepalBranches();
            $this->seedUsers();
            $this->seedRateCards();
            $this->seedMerchants();
            $this->seedCustomers();
        });

        $this->seedShipmentsAndFlow();
        $this->seedDispatchManifests();
        $this->seedSettlementsAndInvoices();
        $this->seedApiWebhookNotifications();
        $this->seedSupportAndAudit();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Nepal performance seed complete.');
        $this->command?->info('Created/loaded approx: '.$this->shipmentCount.' shipments, '.$this->merchantCount.' merchants, '.$this->customerCount.' customers.');
    }

    private function ensureRoles(): void
    {
        foreach ([
            'super_admin', 'main_admin', 'branch_manager', 'sub_branch_manager',
            'booking_staff', 'pickup_staff', 'dispatch_staff', 'rider',
            'accounts_staff', 'support_staff', 'merchant',
        ] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    private function seedNepalBranches(): void
    {
        $now = now();
        $main = DB::table('branches')->where('code', 'NP-KTM-MAIN')->first();
        if (!$main) {
            $this->mainBranchId = DB::table('branches')->insertGetId([
                'name' => 'Kathmandu Main Branch',
                'code' => 'NP-KTM-MAIN',
                'type' => 'main_branch',
                'phone' => '015970001',
                'email' => 'main.kathmandu@courier.test',
                'city' => 'Kathmandu',
                'area' => 'Tripureshwor',
                'address' => 'Tripureshwor, Kathmandu, Bagmati Province',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $this->mainBranchId = $main->id;
        }

        $districts = $this->districtsWithCities();
        $branchRows = [];
        foreach ($districts as $district => $cities) {
            $branchRows[] = [
                'parent_id' => $this->mainBranchId,
                'name' => $district.' Branch',
                'code' => $this->branchCode($district, 'BR'),
                'type' => 'branch',
                'phone' => $this->nepaliPhone('01'),
                'email' => Str::slug($district).'.branch@courier.test',
                'city' => $district,
                'area' => $cities[0] ?? $district,
                'address' => ($cities[0] ?? $district).', '.$district.', Nepal',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertIgnore('branches', $branchRows);

        $branches = DB::table('branches')->where('parent_id', $this->mainBranchId)->where('type', 'branch')->get(['id', 'name', 'city']);
        foreach ($branches as $branch) {
            $district = str_replace(' Branch', '', $branch->name);
            $this->districtBranchIds[$district] = $branch->id;
            $this->branchIds[] = $branch->id;
        }

        $subRows = [];
        $areaRows = [];
        foreach ($districts as $district => $cities) {
            $parentId = $this->districtBranchIds[$district] ?? null;
            if (!$parentId) {
                continue;
            }
            $cities = array_values(array_unique(array_merge($cities, [$district.' Bazaar'])));
            foreach ($cities as $city) {
                $subRows[] = [
                    'parent_id' => $parentId,
                    'name' => $city.' Sub-Branch',
                    'code' => $this->branchCode($district.' '.$city, 'SB'),
                    'type' => 'sub_branch',
                    'phone' => $this->nepaliPhone('98'),
                    'email' => Str::slug($district.'-'.$city).'.sub@courier.test',
                    'city' => $district,
                    'area' => $city,
                    'address' => $city.', '.$district.', Nepal',
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $areaRows[] = [
                    'branch_id' => $parentId,
                    'city' => $district,
                    'area' => $city,
                    'postal_code' => (string) random_int(10000, 99999),
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        $this->insertIgnore('branches', $subRows);
        $this->insertIgnore('branch_service_areas', $areaRows);

        $this->subBranchIds = DB::table('branches')->where('type', 'sub_branch')->pluck('id')->values()->all();
    }

    private function seedUsers(): void
    {
        $this->command?->info('Seeding branch users/staff/riders...');
        $password = Hash::make('password');
        $now = now();

        $mainAdmin = User::firstOrCreate(
            ['email' => 'performance.main.admin@courier.test'],
            [
                'name' => 'Performance Main Admin',
                'phone' => '9800000000',
                'role' => 'main_admin',
                'branch_id' => $this->mainBranchId,
                'password' => $password,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
        $mainAdmin->syncRoles(['main_admin']);

        foreach ($this->branchIds as $branchId) {
            $branch = DB::table('branches')->find($branchId);
            $slug = Str::slug($branch->city ?: $branch->name);

            $manager = User::firstOrCreate(
                ['email' => 'manager.'.$slug.'@courier.test'],
                ['name' => $branch->name.' Manager', 'phone' => $this->nepaliMobile(), 'role' => 'branch_manager', 'branch_id' => $branchId, 'password' => $password, 'is_active' => true]
            );
            $manager->syncRoles(['branch_manager']);

            $booking = User::firstOrCreate(
                ['email' => 'booking.'.$slug.'@courier.test'],
                ['name' => $branch->name.' Booking Staff', 'phone' => $this->nepaliMobile(), 'role' => 'booking_staff', 'branch_id' => $branchId, 'password' => $password, 'is_active' => true]
            );
            $booking->syncRoles(['booking_staff']);
            $this->bookingStaffIds[] = $booking->id;

            $dispatch = User::firstOrCreate(
                ['email' => 'dispatch.'.$slug.'@courier.test'],
                ['name' => $branch->name.' Dispatch Staff', 'phone' => $this->nepaliMobile(), 'role' => 'dispatch_staff', 'branch_id' => $branchId, 'password' => $password, 'is_active' => true]
            );
            $dispatch->syncRoles(['dispatch_staff']);
            $this->dispatchStaffIds[] = $dispatch->id;

            $accounts = User::firstOrCreate(
                ['email' => 'accounts.'.$slug.'@courier.test'],
                ['name' => $branch->name.' Accounts Staff', 'phone' => $this->nepaliMobile(), 'role' => 'accounts_staff', 'branch_id' => $branchId, 'password' => $password, 'is_active' => true]
            );
            $accounts->syncRoles(['accounts_staff']);
            $this->accountsStaffIds[] = $accounts->id;
        }

        foreach ($this->subBranchIds as $subId) {
            $sub = DB::table('branches')->find($subId);
            $slug = Str::slug(($sub->city ?: 'nepal').'-'.($sub->area ?: $sub->name));

            $subManager = User::firstOrCreate(
                ['email' => 'submanager.'.$slug.'@courier.test'],
                ['name' => $sub->name.' Manager', 'phone' => $this->nepaliMobile(), 'role' => 'sub_branch_manager', 'branch_id' => $subId, 'password' => $password, 'is_active' => true]
            );
            $subManager->syncRoles(['sub_branch_manager']);

            for ($i = 1; $i <= 2; $i++) {
                $rider = User::firstOrCreate(
                    ['email' => 'rider'.$i.'.'.$slug.'@courier.test'],
                    ['name' => $sub->area.' Rider '.$i, 'phone' => $this->nepaliMobile(), 'role' => 'rider', 'branch_id' => $subId, 'password' => $password, 'is_active' => true]
                );
                $rider->syncRoles(['rider']);
                $this->riderIds[] = $rider->id;
            }

            $pickup = User::firstOrCreate(
                ['email' => 'pickup.'.$slug.'@courier.test'],
                ['name' => $sub->area.' Pickup Staff', 'phone' => $this->nepaliMobile(), 'role' => 'pickup_staff', 'branch_id' => $subId, 'password' => $password, 'is_active' => true]
            );
            $pickup->syncRoles(['pickup_staff']);
            $this->pickupStaffIds[] = $pickup->id;
        }
    }

    private function seedRateCards(): void
    {
        $now = now();
        $this->defaultRateCardId = DB::table('rate_cards')->where('code', 'NEPAL-STANDARD')->value('id');
        if (!$this->defaultRateCardId) {
            $this->defaultRateCardId = DB::table('rate_cards')->insertGetId([
                'name' => 'Nepal Standard Courier Rate',
                'code' => 'NEPAL-STANDARD',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $rows = [];
        $branches = DB::table('branches')->where('type', 'branch')->get(['id', 'city']);
        foreach ($branches as $origin) {
            foreach ($branches as $dest) {
                if ($origin->id === $dest->id) {
                    $base = 80;
                    $eta = 'Same day';
                } else {
                    $base = random_int(130, 280);
                    $eta = random_int(1, 4).'-'.random_int(2, 5).' days';
                }
                $rows[] = [
                    'rate_card_id' => $this->defaultRateCardId,
                    'origin_branch_id' => $origin->id,
                    'destination_branch_id' => $dest->id,
                    'origin_city' => $origin->city,
                    'destination_city' => $dest->city,
                    'min_weight' => 0,
                    'max_weight' => 2,
                    'base_charge' => $base,
                    'extra_per_kg' => random_int(25, 60),
                    'pod_percent' => 1.00,
                    'pod_fixed' => 10,
                    'return_charge' => max(60, (int) round($base * 0.65)),
                    'estimated_delivery_time' => $eta,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('rate_rules')->insertOrIgnore($chunk);
        }
    }

    private function seedMerchants(): void
    {
        $this->command?->info('Seeding merchants...');
        $now = now();
        $businessTypes = ['fashion', 'electronics', 'grocery', 'cosmetics', 'pharmacy', 'book_store', 'home_decor', 'sports', 'mobile_store'];
        $prefixes = ['Himalayan', 'Everest', 'Sagarmatha', 'New Road', 'Nepal', 'Gorkha', 'Patan', 'Kantipur', 'Annapurna', 'Namaste'];
        $suffixes = ['Store', 'Fashion', 'Mart', 'Suppliers', 'Traders', 'Online', 'Collection', 'Bazaar', 'Hub'];

        $rows = [];
        for ($i = 1; $i <= $this->merchantCount; $i++) {
            $branchId = $this->random($this->branchIds);
            $subId = $this->randomSubForBranch($branchId);
            $branch = DB::table('branches')->find($branchId);
            $name = $prefixes[array_rand($prefixes)].' '.$suffixes[array_rand($suffixes)].' '.$i;
            $rows[] = [
                'default_branch_id' => $branchId,
                'default_sub_branch_id' => $subId,
                'name' => $name,
                'code' => 'MERF'.str_pad((string)$i, 5, '0', STR_PAD_LEFT),
                'owner_name' => $this->personName(),
                'contact_person' => $this->personName(),
                'phone' => $this->nepaliMobile(),
                'email' => 'merchant'.$i.'@nepalstore.test',
                'website_url' => 'https://merchant'.$i.'.example.test',
                'business_type' => $businessTypes[array_rand($businessTypes)],
                'pan_vat_number' => (string) random_int(100000000, 999999999),
                'address' => ($branch->city ?? 'Kathmandu').', Nepal',
                'bank_name' => $this->random(['Nabil Bank', 'Global IME Bank', 'NIC Asia Bank', 'NMB Bank', 'Everest Bank', 'Kumari Bank']),
                'bank_account_name' => $name,
                'bank_account_number' => (string) random_int(100000000000, 999999999999),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('merchants')->insertOrIgnore($chunk);
        }
        $this->merchantIds = DB::table('merchants')->where('code', 'like', 'MERF%')->pluck('id')->values()->all();

        $pickupRows = [];
        $apiRows = [];
        $webhookRows = [];
        foreach (DB::table('merchants')->whereIn('id', $this->merchantIds)->get() as $merchant) {
            $sub = DB::table('branches')->find($merchant->default_sub_branch_id);
            $pickupRows[] = [
                'merchant_id' => $merchant->id,
                'branch_id' => $merchant->default_branch_id,
                'sub_branch_id' => $merchant->default_sub_branch_id,
                'name' => $merchant->name.' Warehouse',
                'contact_person' => $merchant->contact_person,
                'phone' => $merchant->phone,
                'city' => $sub->city ?? null,
                'area' => $sub->area ?? null,
                'address' => ($sub->area ?? 'Warehouse').', '.($sub->city ?? 'Nepal'),
                'is_default' => true,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $apiRows[] = [
                'merchant_id' => $merchant->id,
                'name' => 'Live API Key',
                'api_key' => 'live_'.Str::random(32),
                'api_secret_hash' => Hash::make('secret-'.$merchant->code),
                'environment' => 'live',
                'permissions' => json_encode(['shipments.create', 'shipments.track', 'rates.calculate', 'pickups.create']),
                'last_used_at' => now()->subMinutes(random_int(1, 10000)),
                'expires_at' => null,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $webhookRows[] = [
                'merchant_id' => $merchant->id,
                'url' => 'https://merchant'.$merchant->id.'.example.test/webhooks/courier',
                'secret' => Str::random(40),
                'events' => json_encode(['shipment.created', 'delivery.out_for_delivery', 'delivery.delivered', 'delivery.failed', 'pod.collected']),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertChunks('merchant_pickup_locations', $pickupRows);
        $this->insertChunks('merchant_api_keys', $apiRows);
        $this->insertChunks('merchant_webhooks', $webhookRows);
        $this->merchantApiKeyIds = DB::table('merchant_api_keys')->whereIn('merchant_id', $this->merchantIds)->pluck('id')->values()->all();

        $rateRows = [];
        foreach ($this->merchantIds as $merchantId) {
            $rateRows[] = ['merchant_id' => $merchantId, 'rate_card_id' => $this->defaultRateCardId, 'is_default' => true, 'created_at' => $now, 'updated_at' => $now];
        }
        $this->insertIgnore('merchant_rate_cards', $rateRows);
    }

    private function seedCustomers(): void
    {
        $this->command?->info('Seeding customers...');
        $now = now();
        $rows = [];
        for ($i = 1; $i <= $this->customerCount; $i++) {
            $merchantId = $this->random($this->merchantIds);
            $sub = DB::table('branches')->find($this->random($this->subBranchIds));
            $rows[] = [
                'merchant_id' => $merchantId,
                'name' => $this->personName(),
                'phone' => $this->nepaliMobile(),
                'email' => 'customer'.$i.'@example.test',
                'city' => $sub->city,
                'area' => $sub->area,
                'address' => $this->houseAddress($sub->area, $sub->city),
                'type' => $this->random(['individual', 'individual', 'business']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $this->insertChunks('customers', $rows);
        $this->customerIds = DB::table('customers')->where('email', 'like', 'customer%@example.test')->pluck('id')->values()->all();
    }

    private function seedShipmentsAndFlow(): void
    {
        $this->command?->info('Seeding shipments, tracking, pickups, deliveries and POD...');
        $now = now();
        $statuses = ['booked', 'pickup_assigned', 'picked_up', 'received_at_origin', 'dispatched', 'in_transit', 'received_at_destination', 'out_for_delivery', 'delivered', 'delivery_failed', 'returned'];
        $products = ['Kurta set', 'Mobile cover', 'Smart watch', 'Shoes', 'T-shirt', 'Rice cooker', 'Cosmetics kit', 'Book set', 'Medicine pack', 'Bluetooth speaker', 'Baby clothes', 'Laptop charger'];
        $paymentTypes = ['pod', 'pod', 'pod', 'prepaid'];

        $shipmentRows = [];
        $itemRows = [];
        $trackingRows = [];
        $pickupRows = [];
        $codRows = [];
        $deliveryAssignmentRows = [];
        $deliveryAttemptRows = [];

        for ($i = 1; $i <= $this->shipmentCount; $i++) {
            $merchantId = $this->random($this->merchantIds);
            $merchant = DB::table('merchants')->find($merchantId);
            $originBranchId = $merchant->default_branch_id;
            $originSubId = $merchant->default_sub_branch_id;
            $destSub = DB::table('branches')->find($this->random($this->subBranchIds));
            $destBranchId = $destSub->parent_id;
            $customer = DB::table('customers')->find($this->random($this->customerIds));

            $status = $this->weightedStatus($i);
            $merchantStatus = $this->merchantStatus($status);
            $paymentType = $this->random($paymentTypes);
            $weight = random_int(1, 800) / 100;
            $declared = random_int(400, 25000);
            $deliveryCharge = $this->deliveryCharge($originBranchId, $destBranchId, $weight);
            $codAmount = $paymentType === 'pod' ? $declared : 0;
            $codCharge = $paymentType === 'pod' ? round(max(10, $codAmount * 0.01), 2) : 0;
            $totalCollect = $paymentType === 'pod' ? $codAmount + $deliveryCharge : 0;
            $createdAt = Carbon::now()->subDays(random_int(0, 120))->subMinutes(random_int(0, 1440));
            $tracking = 'NP'.now()->format('ym').str_pad((string)$i, 8, '0', STR_PAD_LEFT);

            $currentBranch = in_array($status, ['booked', 'pickup_assigned', 'picked_up', 'received_at_origin'], true) ? $originBranchId : $destBranchId;
            $currentSub = in_array($status, ['booked', 'pickup_assigned', 'picked_up', 'received_at_origin'], true) ? $originSubId : $destSub->id;

            $shipmentRows[] = [
                'tracking_number' => $tracking,
                'merchant_id' => $merchantId,
                'merchant_order_id' => 'ORD-'.$merchant->code.'-'.str_pad((string)$i, 8, '0', STR_PAD_LEFT),
                'source' => $this->random(['merchant_api', 'merchant_api', 'merchant_dashboard', 'manual', 'bulk_upload']),
                'origin_branch_id' => $originBranchId,
                'origin_sub_branch_id' => $originSubId,
                'destination_branch_id' => $destBranchId,
                'destination_sub_branch_id' => $destSub->id,
                'current_branch_id' => $currentBranch,
                'current_sub_branch_id' => $currentSub,
                'created_by' => $this->random($this->bookingStaffIds),
                'sender_name' => $merchant->name,
                'sender_phone' => $merchant->phone,
                'sender_address' => $merchant->address,
                'sender_city' => DB::table('branches')->where('id', $originBranchId)->value('city'),
                'sender_area' => DB::table('branches')->where('id', $originSubId)->value('area'),
                'receiver_name' => $customer->name,
                'receiver_phone' => $customer->phone,
                'receiver_email' => $customer->email,
                'receiver_address' => $customer->address,
                'receiver_city' => $destSub->city,
                'receiver_area' => $destSub->area,
                'parcel_type' => 'product',
                'description' => $this->random($products),
                'quantity' => random_int(1, 4),
                'weight' => $weight,
                'declared_value' => $declared,
                'fragile' => random_int(1, 100) <= 12,
                'payment_type' => $paymentType,
                'pod_amount' => $codAmount,
                'delivery_charge' => $deliveryCharge,
                'pod_charge' => $codCharge,
                'return_charge' => in_array($status, ['returned'], true) ? round($deliveryCharge * 0.65, 2) : 0,
                'total_collectable_amount' => $totalCollect,
                'delivery_charge_paid_by' => $this->random(['customer', 'merchant']),
                'status' => $status,
                'merchant_status' => $merchantStatus,
                'pod_status' => $this->codStatus($status, $paymentType),
                'settlement_status' => $status === 'delivered' && $paymentType === 'pod' ? $this->random(['ready', 'pending', 'settled']) : 'not_ready',
                'delivered_at' => $status === 'delivered' ? $createdAt->copy()->addDays(random_int(1, 4))->toDateTimeString() : null,
                'cancelled_at' => null,
                'remarks' => random_int(1, 100) <= 10 ? 'Customer requested careful handling.' : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt->copy()->addDays(random_int(0, 5)),
            ];

            $itemRows[] = [
                'shipment_id' => null, // patched after insert using tracking map
                'tracking_number_tmp' => $tracking,
                'name' => $this->random($products),
                'quantity' => random_int(1, 3),
                'weight' => $weight,
                'value' => $declared,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            $timeline = $this->timelineFor($status);
            foreach ($timeline as $idx => $eventStatus) {
                $locationBranch = in_array($eventStatus, ['booked', 'pickup_assigned', 'picked_up', 'received_at_origin'], true) ? $originBranchId : $destBranchId;
                $locationSub = in_array($eventStatus, ['booked', 'pickup_assigned', 'picked_up', 'received_at_origin'], true) ? $originSubId : $destSub->id;
                $location = DB::table('branches')->where('id', $locationSub)->value('name');
                $trackingRows[] = [
                    'shipment_id' => null,
                    'tracking_number_tmp' => $tracking,
                    'tracking_number' => $tracking,
                    'status' => $eventStatus,
                    'merchant_status' => $this->merchantStatus($eventStatus),
                    'branch_id' => $locationBranch,
                    'sub_branch_id' => $locationSub,
                    'location_text' => $location,
                    'description' => Str::headline(str_replace('_', ' ', $eventStatus)),
                    'visibility' => 'public',
                    'created_by' => $this->random($this->bookingStaffIds),
                    'created_at' => $createdAt->copy()->addHours($idx * random_int(2, 8)),
                    'updated_at' => $createdAt->copy()->addHours($idx * random_int(2, 8)),
                ];
            }

            $pickupRows[] = [
                'merchant_id' => $merchantId,
                'shipment_id' => null,
                'tracking_number_tmp' => $tracking,
                'pickup_branch_id' => $originBranchId,
                'pickup_sub_branch_id' => $originSubId,
                'assigned_to' => $this->random($this->pickupStaffIds),
                'pickup_name' => $merchant->name,
                'pickup_phone' => $merchant->phone,
                'pickup_address' => $merchant->address,
                'pickup_city' => DB::table('branches')->where('id', $originBranchId)->value('city'),
                'pickup_area' => DB::table('branches')->where('id', $originSubId)->value('area'),
                'preferred_pickup_at' => $createdAt->copy()->addHours(2),
                'parcel_quantity' => 1,
                'status' => in_array($status, ['booked'], true) ? 'requested' : 'picked_up',
                'remarks' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt->copy()->addHours(2),
            ];

            if (in_array($status, ['out_for_delivery', 'delivered', 'delivery_failed', 'returned'], true)) {
                $deliveryAssignmentRows[] = [
                    'shipment_id' => null,
                    'tracking_number_tmp' => $tracking,
                    'delivery_staff_id' => $this->random($this->riderIds),
                    'assigned_date' => $createdAt->copy()->addDays(random_int(1, 4))->toDateString(),
                    'status' => $status === 'delivered' ? 'completed' : ($status === 'delivery_failed' ? 'failed' : 'assigned'),
                    'assigned_by' => $this->random($this->dispatchStaffIds),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt->copy()->addDays(random_int(1, 4)),
                ];
            }

            if ($paymentType === 'pod') {
                $collected = $status === 'delivered' ? $codAmount + $deliveryCharge : 0;
                $codRows[] = [
                    'shipment_id' => null,
                    'tracking_number_tmp' => $tracking,
                    'merchant_id' => $merchantId,
                    'pod_amount' => $codAmount,
                    'delivery_charge' => $deliveryCharge,
                    'pod_charge' => $codCharge,
                    'collected_amount' => $collected,
                    'status' => $this->codStatus($status, $paymentType),
                    'collected_by' => $status === 'delivered' ? $this->random($this->riderIds) : null,
                    'collected_at' => $status === 'delivered' ? $createdAt->copy()->addDays(random_int(1, 4))->toDateTimeString() : null,
                    'deposited_to_branch_id' => $status === 'delivered' ? $destBranchId : null,
                    'deposited_at' => $status === 'delivered' ? $createdAt->copy()->addDays(random_int(2, 5))->toDateTimeString() : null,
                    'settled_at' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt->copy()->addDays(random_int(1, 5)),
                ];
            }

            if (count($shipmentRows) >= 1000) {
                $this->flushShipmentBatch($shipmentRows, $itemRows, $trackingRows, $pickupRows, $codRows, $deliveryAssignmentRows);
                $shipmentRows = $itemRows = $trackingRows = $pickupRows = $codRows = $deliveryAssignmentRows = [];
            }
        }

        $this->flushShipmentBatch($shipmentRows, $itemRows, $trackingRows, $pickupRows, $codRows, $deliveryAssignmentRows);

        // Create delivery attempts after assignments are in DB.
        $assignments = DB::table('delivery_assignments')->whereIn('shipment_id', $this->shipmentIds)->get();
        foreach ($assignments->chunk(1000) as $chunk) {
            $attemptRows = [];
            foreach ($chunk as $assignment) {
                $shipment = DB::table('shipments')->find($assignment->shipment_id);
                $attemptRows[] = [
                    'delivery_assignment_id' => $assignment->id,
                    'shipment_id' => $assignment->shipment_id,
                    'delivery_staff_id' => $assignment->delivery_staff_id,
                    'status' => $shipment->status === 'delivered' ? 'delivered' : ($shipment->status === 'delivery_failed' ? 'failed' : 'attempted'),
                    'failure_reason' => $shipment->status === 'delivery_failed' ? $this->random(['Customer unavailable', 'Phone unreachable', 'Wrong address', 'Payment not ready']) : null,
                    'receiver_name' => $shipment->status === 'delivered' ? $shipment->receiver_name : null,
                    'receiver_phone' => $shipment->status === 'delivered' ? $shipment->receiver_phone : null,
                    'pod_collected_amount' => $shipment->status === 'delivered' ? $shipment->total_collectable_amount : 0,
                    'remarks' => $shipment->status === 'delivered' ? 'Delivered successfully.' : null,
                    'proof_photo_path' => $shipment->status === 'delivered' ? 'proofs/demo-'.$assignment->shipment_id.'.jpg' : null,
                    'signature_data' => null,
                    'created_at' => $assignment->updated_at,
                    'updated_at' => $assignment->updated_at,
                ];
            }
            DB::table('delivery_attempts')->insert($attemptRows);
        }

        $this->deliveredShipmentIds = DB::table('shipments')->whereIn('id', $this->shipmentIds)->where('status', 'delivered')->pluck('id')->values()->all();
    }

    private function flushShipmentBatch(array $shipmentRows, array $itemRows, array $trackingRows, array $pickupRows, array $codRows, array $deliveryAssignmentRows): void
    {
        if (!$shipmentRows) {
            return;
        }
        DB::table('shipments')->insertOrIgnore($shipmentRows);
        $trackingNumbers = array_column($shipmentRows, 'tracking_number');
        $map = DB::table('shipments')->whereIn('tracking_number', $trackingNumbers)->pluck('id', 'tracking_number')->all();
        $this->shipmentIds = array_merge($this->shipmentIds, array_values($map));

        $patch = function (array $rows) use ($map) {
            $out = [];
            foreach ($rows as $row) {
                $tmp = $row['tracking_number_tmp'] ?? null;
                unset($row['tracking_number_tmp']);
                if ($tmp && isset($map[$tmp])) {
                    $row['shipment_id'] = $map[$tmp];
                    $out[] = $row;
                }
            }
            return $out;
        };

        $this->insertChunks('shipment_items', $patch($itemRows));
        $this->insertChunks('tracking_events', $patch($trackingRows));
        $this->insertChunks('pickup_requests', $patch($pickupRows));
        $this->insertChunks('pod_records', $patch($codRows));
        $this->insertChunks('delivery_assignments', $patch($deliveryAssignmentRows));
    }

    private function seedDispatchManifests(): void
    {
        $this->command?->info('Seeding dispatch manifests...');
        $candidates = DB::table('shipments')->whereIn('id', $this->shipmentIds)->whereIn('status', ['dispatched', 'in_transit', 'received_at_destination', 'out_for_delivery', 'delivered', 'delivery_failed', 'returned'])->get();
        $chunks = $candidates->chunk(25);
        $manifestNo = 1;
        foreach ($chunks as $chunk) {
            $first = $chunk->first();
            if (!$first) continue;
            $manifestId = DB::table('dispatch_manifests')->insertGetId([
                'manifest_number' => 'MNF-NP-'.str_pad((string)$manifestNo++, 7, '0', STR_PAD_LEFT),
                'from_branch_id' => $first->origin_branch_id,
                'from_sub_branch_id' => $first->origin_sub_branch_id,
                'to_branch_id' => $first->destination_branch_id,
                'to_sub_branch_id' => $first->destination_sub_branch_id,
                'vehicle_number' => 'BA '.random_int(1, 9).' KHA '.random_int(1000, 9999),
                'driver_name' => $this->personName(),
                'seal_number' => 'SEAL'.random_int(100000, 999999),
                'status' => $this->random(['dispatched', 'received', 'received', 'received']),
                'created_by' => $this->random($this->dispatchStaffIds),
                'received_by' => $this->random($this->dispatchStaffIds),
                'dispatched_at' => Carbon::parse($first->created_at)->addHours(12),
                'received_at' => Carbon::parse($first->created_at)->addDays(random_int(1, 4)),
                'created_at' => $first->created_at,
                'updated_at' => Carbon::parse($first->created_at)->addDays(random_int(1, 4)),
            ]);
            $items = [];
            foreach ($chunk as $shipment) {
                $items[] = ['dispatch_manifest_id' => $manifestId, 'shipment_id' => $shipment->id, 'status' => 'received', 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('dispatch_manifest_items')->insert($items);
        }
    }

    private function seedSettlementsAndInvoices(): void
    {
        $this->command?->info('Seeding settlements, invoices, receipts and POD deposits...');
        $now = now();
        $codRows = DB::table('pod_records')->whereIn('shipment_id', $this->deliveredShipmentIds)->where('status', 'collected')->get()->groupBy('merchant_id');
        $settlementNo = 1;
        foreach ($codRows as $merchantId => $records) {
            foreach ($records->chunk(50) as $chunk) {
                $totalCod = $chunk->sum('pod_amount');
                $delivery = $chunk->sum('delivery_charge');
                $codCharge = $chunk->sum('pod_charge');
                $settlementId = DB::table('merchant_settlements')->insertGetId([
                    'merchant_id' => $merchantId,
                    'settlement_number' => 'SET-NP-'.str_pad((string)$settlementNo++, 8, '0', STR_PAD_LEFT),
                    'period_from' => now()->subDays(30)->toDateString(),
                    'period_to' => now()->toDateString(),
                    'total_pod_collected' => $totalCod,
                    'total_delivery_charges' => $delivery,
                    'total_pod_charges' => $codCharge,
                    'return_charges' => 0,
                    'adjustments' => 0,
                    'final_payable_amount' => $totalCod - $delivery - $codCharge,
                    'status' => $this->random(['pending', 'processing', 'settled', 'settled']),
                    'payment_method' => 'bank_transfer',
                    'bank_reference_number' => 'BNK'.random_int(100000, 999999),
                    'settled_by' => $this->random($this->accountsStaffIds),
                    'settled_at' => now()->subDays(random_int(0, 10)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $items = [];
                foreach ($chunk as $record) {
                    $items[] = [
                        'merchant_settlement_id' => $settlementId,
                        'shipment_id' => $record->shipment_id,
                        'pod_amount' => $record->pod_amount,
                        'delivery_charge' => $record->delivery_charge,
                        'pod_charge' => $record->pod_charge,
                        'net_amount' => $record->pod_amount - $record->delivery_charge - $record->pod_charge,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('merchant_settlement_items')->insert($items);
            }
        }

        $invoiceRows = [];
        $shipments = DB::table('shipments')->whereIn('id', array_slice($this->shipmentIds, 0, min(3000, count($this->shipmentIds))))->get();
        $n = 1;
        foreach ($shipments as $shipment) {
            $subtotal = $shipment->delivery_charge + $shipment->pod_charge + $shipment->return_charge;
            $tax = round($subtotal * 0.13, 2);
            $invoiceRows[] = [
                'merchant_id' => $shipment->merchant_id,
                'shipment_id' => $shipment->id,
                'invoice_number' => 'INV-NP-'.str_pad((string)$n++, 8, '0', STR_PAD_LEFT),
                'type' => 'shipment',
                'invoice_date' => Carbon::parse($shipment->created_at)->toDateString(),
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total_amount' => $subtotal + $tax,
                'status' => $this->random(['paid', 'unpaid', 'paid', 'paid']),
                'created_at' => $shipment->created_at,
                'updated_at' => $shipment->updated_at,
            ];
        }
        $this->insertChunks('invoices', $invoiceRows);

        $invoiceItems = [];
        $receipts = [];
        foreach (DB::table('invoices')->where('invoice_number', 'like', 'INV-NP-%')->get() as $invoice) {
            $invoiceItems[] = ['invoice_id' => $invoice->id, 'description' => 'Courier delivery charge', 'quantity' => 1, 'unit_price' => $invoice->subtotal, 'total' => $invoice->subtotal, 'created_at' => $now, 'updated_at' => $now];
            if ($invoice->status === 'paid') {
                $receipts[] = ['invoice_id' => $invoice->id, 'receipt_number' => 'RCT-NP-'.$invoice->id, 'amount' => $invoice->total_amount, 'payment_method' => 'bank_transfer', 'reference_number' => 'PAY'.random_int(100000, 999999), 'paid_at' => $now, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        $this->insertChunks('invoice_items', $invoiceItems);
        $this->insertChunks('payment_receipts', $receipts);

        $depositRows = [];
        foreach (array_chunk($this->branchIds, 1) as $branchChunk) {
            $branchId = $branchChunk[0];
            for ($i = 0; $i < 3; $i++) {
                $depositRows[] = ['branch_id' => $branchId, 'staff_id' => $this->random($this->accountsStaffIds), 'amount' => random_int(50000, 250000), 'status' => $this->random(['pending', 'confirmed', 'confirmed']), 'remarks' => 'Daily POD counter deposit', 'created_at' => $now->copy()->subDays(random_int(0, 30)), 'updated_at' => $now];
            }
        }
        $this->insertChunks('pod_deposits', $depositRows);
    }

    private function seedApiWebhookNotifications(): void
    {
        $this->command?->info('Seeding API logs, webhooks and notifications...');
        $now = now();
        $apiRows = [];
        for ($i = 1; $i <= 5000; $i++) {
            $merchantId = $this->random($this->merchantIds);
            $apiRows[] = [
                'merchant_id' => $merchantId,
                'merchant_api_key_id' => $this->random($this->merchantApiKeyIds),
                'endpoint' => $this->random(['/api/v1/gateway/shipments', '/api/v1/gateway/rates/calculate', '/api/v1/gateway/shipments/track']),
                'method' => $this->random(['GET', 'POST']),
                'request_payload' => json_encode(['demo' => true, 'order' => 'ORD'.random_int(1000, 999999)]),
                'response_payload' => json_encode(['success' => true]),
                'status_code' => $this->random([200, 200, 201, 422, 500]),
                'ip_address' => '103.'.random_int(1, 255).'.'.random_int(1, 255).'.'.random_int(1, 255),
                'error_message' => null,
                'created_at' => $now->copy()->subMinutes(random_int(1, 100000)),
                'updated_at' => $now,
            ];
        }
        $this->insertChunks('api_logs', $apiRows);

        $webhookRows = [];
        $notificationRows = [];
        $smsRows = [];
        $subset = DB::table('shipments')->whereIn('id', array_slice($this->shipmentIds, 0, min(8000, count($this->shipmentIds))))->get();
        foreach ($subset as $shipment) {
            $event = $this->random(['shipment.created', 'pickup.completed', 'delivery.out_for_delivery', 'delivery.delivered', 'delivery.failed', 'pod.collected']);
            $webhookRows[] = [
                'merchant_id' => $shipment->merchant_id,
                'shipment_id' => $shipment->id,
                'event' => $event,
                'webhook_url' => 'https://merchant'.$shipment->merchant_id.'.example.test/webhooks/courier',
                'payload' => json_encode(['tracking_number' => $shipment->tracking_number, 'status' => $shipment->status]),
                'signature' => hash('sha256', $shipment->tracking_number),
                'response_status_code' => $this->random([200, 200, 200, 500, 404]),
                'response_body' => '{"received":true}',
                'attempt_count' => random_int(1, 3),
                'last_attempt_at' => $now->copy()->subMinutes(random_int(1, 5000)),
                'next_retry_at' => null,
                'status' => $this->random(['success', 'success', 'success', 'failed', 'pending']),
                'created_at' => $shipment->created_at,
                'updated_at' => $now,
            ];
            $notificationRows[] = [
                'user_id' => null,
                'merchant_id' => $shipment->merchant_id,
                'shipment_id' => $shipment->id,
                'channel' => 'in_app',
                'event' => $event,
                'recipient' => $shipment->receiver_phone,
                'subject' => 'Shipment update '.$shipment->tracking_number,
                'message' => 'Your shipment status is '.str_replace('_', ' ', $shipment->status).'.',
                'payload' => json_encode(['tracking_number' => $shipment->tracking_number]),
                'status' => 'sent',
                'error_message' => null,
                'sent_at' => $now,
                'created_at' => $shipment->created_at,
                'updated_at' => $now,
            ];
            $smsRows[] = [
                'shipment_id' => $shipment->id,
                'phone' => $shipment->receiver_phone,
                'message' => 'Courier update: '.$shipment->tracking_number.' is '.str_replace('_', ' ', $shipment->status),
                'provider' => 'demo_sms',
                'provider_reference' => 'SMS'.random_int(100000, 999999),
                'status' => 'sent',
                'error_message' => null,
                'sent_at' => $now,
                'created_at' => $shipment->created_at,
                'updated_at' => $now,
            ];
        }
        $this->insertChunks('webhook_delivery_logs', $webhookRows);
        $this->insertChunks('notification_logs', $notificationRows);
        $this->insertChunks('sms_logs', $smsRows);
    }

    private function seedSupportAndAudit(): void
    {
        $now = now();
        if (!DB::getSchemaBuilder()->hasTable('support_tickets')) {
            return;
        }
        $tickets = [];
        foreach (array_slice($this->merchantIds, 0, min(300, count($this->merchantIds))) as $idx => $merchantId) {
            $tickets[] = [
                'merchant_id' => $merchantId,
                'user_id' => null,
                'subject' => $this->random(['Parcel delay inquiry', 'POD settlement question', 'Webhook not received', 'Pickup reschedule request']),
                'message' => 'Demo support ticket for load testing.',
                'status' => $this->random(['open', 'pending', 'resolved']),
                'priority' => $this->random(['low', 'medium', 'high']),
                'created_at' => $now->copy()->subDays(random_int(0, 60)),
                'updated_at' => $now,
            ];
        }
        $this->insertChunks('support_tickets', $tickets);
    }

    private function timelineFor(string $status): array
    {
        $steps = ['booked', 'pickup_assigned', 'picked_up', 'received_at_origin', 'dispatched', 'in_transit', 'received_at_destination', 'out_for_delivery', 'delivered'];
        if ($status === 'delivery_failed') {
            $steps[count($steps)-1] = 'delivery_failed';
        }
        if ($status === 'returned') {
            $steps[] = 'returned';
        }
        $index = array_search($status, $steps, true);
        if ($index === false) {
            $index = min(2, count($steps) - 1);
        }
        return array_slice($steps, 0, $index + 1);
    }

    private function weightedStatus(int $i): string
    {
        $n = random_int(1, 100);
        return match (true) {
            $n <= 42 => 'delivered',
            $n <= 56 => 'out_for_delivery',
            $n <= 68 => 'received_at_destination',
            $n <= 78 => 'in_transit',
            $n <= 86 => 'dispatched',
            $n <= 92 => 'picked_up',
            $n <= 96 => 'delivery_failed',
            $n <= 98 => 'returned',
            default => 'booked',
        };
    }

    private function merchantStatus(string $status): string
    {
        return match ($status) {
            'booked', 'pickup_assigned' => 'pending',
            'picked_up', 'received_at_origin' => 'picked_up',
            'dispatched', 'in_transit', 'received_at_destination' => 'in_transit',
            'out_for_delivery' => 'out_for_delivery',
            'delivered' => 'delivered',
            'delivery_failed' => 'failed',
            'returned' => 'returned',
            default => 'pending',
        };
    }

    private function codStatus(string $status, string $paymentType): string
    {
        if ($paymentType !== 'pod') return 'not_applicable';
        return match ($status) {
            'delivered' => 'collected',
            'returned', 'delivery_failed' => 'pending',
            default => 'pending',
        };
    }

    private function deliveryCharge(?int $originBranchId, ?int $destBranchId, float $weight): float
    {
        $base = $originBranchId === $destBranchId ? 80 : random_int(130, 280);
        $extra = max(0, $weight - 2) * random_int(25, 60);
        return round($base + $extra, 2);
    }

    private function randomSubForBranch(int $branchId): ?int
    {
        $ids = DB::table('branches')->where('parent_id', $branchId)->where('type', 'sub_branch')->pluck('id')->all();
        return $ids ? $this->random($ids) : null;
    }

    private function insertChunks(string $table, array $rows, int $size = 1000): void
    {
        foreach (array_chunk($rows, $size) as $chunk) {
            if ($chunk) DB::table($table)->insert($chunk);
        }
    }

    private function insertIgnore(string $table, array $rows, int $size = 1000): void
    {
        foreach (array_chunk($rows, $size) as $chunk) {
            if ($chunk) DB::table($table)->insertOrIgnore($chunk);
        }
    }

    private function random(array $items)
    {
        return $items[array_rand($items)];
    }

    private function branchCode(string $name, string $prefix): string
    {
        $slug = strtoupper(preg_replace('/[^A-Z0-9]/', '', Str::ascii($name)));
        return 'NP-'.$prefix.'-'.substr($slug, 0, 12);
    }

    private function nepaliMobile(): string
    {
        return '98'.random_int(10000000, 99999999);
    }

    private function nepaliPhone(string $prefix): string
    {
        return $prefix.random_int(4000000, 9999999);
    }

    private function personName(): string
    {
        $first = ['Aarav', 'Aashish', 'Abhishek', 'Anil', 'Anita', 'Asmita', 'Bikash', 'Binita', 'Deepak', 'Dipika', 'Gita', 'Hari', 'Ishwor', 'Kabita', 'Kiran', 'Manoj', 'Nabin', 'Nisha', 'Prakash', 'Ramesh', 'Rita', 'Sabina', 'Sagar', 'Sanjay', 'Sita', 'Sujan', 'Sunita'];
        $last = ['Adhikari', 'Bhandari', 'Gautam', 'Ghimire', 'Karki', 'Khadka', 'Lama', 'Magar', 'Maharjan', 'Neupane', 'Pandey', 'Poudel', 'Rai', 'Shah', 'Sharma', 'Shrestha', 'Tamang', 'Thapa'];
        return $this->random($first).' '.$this->random($last);
    }

    private function houseAddress(?string $area, ?string $city): string
    {
        return 'Ward '.random_int(1, 32).', '.($area ?: 'Bazaar').', '.($city ?: 'Nepal');
    }

    private function districtsWithCities(): array
    {
        return [
            'Taplejung' => ['Phungling', 'Suketar', 'Dobhan'],
            'Panchthar' => ['Phidim', 'Yangnam', 'Rabi'],
            'Ilam' => ['Ilam Bazaar', 'Fikkal', 'Pashupatinagar'],
            'Jhapa' => ['Birtamod', 'Damak', 'Mechinagar', 'Kakarbhitta'],
            'Morang' => ['Biratnagar', 'Urlabari', 'Belbari', 'Pathari'],
            'Sunsari' => ['Itahari', 'Dharan', 'Inaruwa', 'Duhabi'],
            'Dhankuta' => ['Dhankuta Bazaar', 'Hile', 'Pakhribas'],
            'Terhathum' => ['Myanglung', 'Jirikhimti', 'Basantapur'],
            'Sankhuwasabha' => ['Khandbari', 'Tumlingtar', 'Chainpur'],
            'Bhojpur' => ['Bhojpur Bazaar', 'Dingla', 'Taksar'],
            'Solukhumbu' => ['Salleri', 'Lukla', 'Namche'],
            'Okhaldhunga' => ['Okhaldhunga Bazaar', 'Rumjatar', 'Manebhanjyang'],
            'Khotang' => ['Diktel', 'Halesi', 'Aiselukharka'],
            'Udayapur' => ['Gaighat', 'Katari', 'Beltar'],
            'Saptari' => ['Rajbiraj', 'Kanchanrup', 'Hanumannagar'],
            'Siraha' => ['Lahan', 'Siraha Bazaar', 'Mirchaiya'],
            'Dhanusha' => ['Janakpur', 'Dhalkebar', 'Mahendranagar'],
            'Mahottari' => ['Jaleshwar', 'Bardibas', 'Gaushala'],
            'Sarlahi' => ['Malangwa', 'Lalbandi', 'Hariwan'],
            'Rautahat' => ['Gaur', 'Chandrapur', 'Garuda'],
            'Bara' => ['Kalaiya', 'Simara', 'Nijgadh'],
            'Parsa' => ['Birgunj', 'Pokhariya', 'Thori'],
            'Dolakha' => ['Charikot', 'Jiri', 'Mainapokhari'],
            'Ramechhap' => ['Manthali', 'Ramechhap Bazaar', 'Khimti'],
            'Sindhuli' => ['Sindhulimadhi', 'Dudhauli', 'Bhurungi'],
            'Kavrepalanchok' => ['Dhulikhel', 'Banepa', 'Panauti'],
            'Sindhupalchok' => ['Chautara', 'Melamchi', 'Barhabise'],
            'Kathmandu' => ['New Road', 'Baneshwor', 'Kalanki', 'Chabahil', 'Koteshwor', 'Balaju'],
            'Lalitpur' => ['Patan', 'Jawalakhel', 'Lagankhel', 'Satdobato'],
            'Bhaktapur' => ['Bhaktapur Durbar Square', 'Suryabinayak', 'Thimi', 'Duwakot'],
            'Nuwakot' => ['Bidur', 'Trishuli', 'Battar'],
            'Rasuwa' => ['Dhunche', 'Syabrubesi', 'Timure'],
            'Dhading' => ['Dhading Besi', 'Malekhu', 'Gajuri'],
            'Makwanpur' => ['Hetauda', 'Manahari', 'Thaha'],
            'Chitwan' => ['Bharatpur', 'Narayanghat', 'Tandi', 'Mugling'],
            'Gorkha' => ['Gorkha Bazaar', 'Palungtar', 'Aabukhaireni'],
            'Lamjung' => ['Besisahar', 'Sundarbazar', 'Bhoteodar'],
            'Tanahun' => ['Damauli', 'Dumre', 'Bandipur'],
            'Syangja' => ['Putalibazar', 'Waling', 'Galyang'],
            'Kaski' => ['Pokhara', 'Lakeside', 'Prithvi Chowk', 'Bagar'],
            'Manang' => ['Chame', 'Manang Village', 'Dharapani'],
            'Mustang' => ['Jomsom', 'Kagbeni', 'Marpha'],
            'Myagdi' => ['Beni', 'Galeshwor', 'Darbang'],
            'Parbat' => ['Kusma', 'Phalebas', 'Dimuwa'],
            'Baglung' => ['Baglung Bazaar', 'Burtibang', 'Galkot'],
            'Nawalpur' => ['Kawasoti', 'Gaindakot', 'Daldale'],
            'Rupandehi' => ['Butwal', 'Bhairahawa', 'Tilottama', 'Devdaha'],
            'Kapilvastu' => ['Taulihawa', 'Krishnanagar', 'Chandrauta'],
            'Palpa' => ['Tansen', 'Rampur', 'Aryabhanjyang'],
            'Arghakhanchi' => ['Sandhikharka', 'Thada', 'Balkot'],
            'Gulmi' => ['Tamghas', 'Ridi', 'Wami'],
            'Rukum East' => ['Rukumkot', 'Sisne', 'Lukums'],
            'Rolpa' => ['Liwang', 'Sulichaur', 'Holeri'],
            'Pyuthan' => ['Pyuthan Khalanga', 'Bijuwar', 'Bhingri'],
            'Dang' => ['Ghorahi', 'Tulsipur', 'Lamahi'],
            'Banke' => ['Nepalgunj', 'Kohalpur', 'Khajura'],
            'Bardiya' => ['Gulariya', 'Rajapur', 'Bansgadhi'],
            'Rukum West' => ['Musikot', 'Chaurjahari', 'Aathbiskot'],
            'Salyan' => ['Salyan Khalanga', 'Shrinagar', 'Kapurkot'],
            'Dolpa' => ['Dunai', 'Juphal', 'Tripurakot'],
            'Jumla' => ['Jumla Bazaar', 'Khalanga', 'Tila'],
            'Mugu' => ['Gamgadhi', 'Rara', 'Soru'],
            'Humla' => ['Simikot', 'Yalbang', 'Sarkegad'],
            'Kalikot' => ['Manma', 'Raskot', 'Khandachakra'],
            'Jajarkot' => ['Khalanga', 'Bheri', 'Chhedagad'],
            'Dailekh' => ['Dailekh Bazaar', 'Dullu', 'Naumule'],
            'Surkhet' => ['Birendranagar', 'Chhinchu', 'Gurbhakot'],
            'Bajura' => ['Martadi', 'Kolti', 'Budhiganga'],
            'Bajhang' => ['Chainpur', 'Bungal', 'Jayaprithvi'],
            'Achham' => ['Mangalsen', 'Sanphebagar', 'Kamalbazaar'],
            'Doti' => ['Dipayal', 'Silgadhi', 'Budhar'],
            'Kailali' => ['Dhangadhi', 'Tikapur', 'Lamki', 'Attariya'],
            'Kanchanpur' => ['Mahendranagar', 'Belauri', 'Punarbas'],
            'Dadeldhura' => ['Dadeldhura Bazaar', 'Amargadhi', 'Jogbudha'],
            'Baitadi' => ['Dasharathchand', 'Patan', 'Melauli'],
            'Darchula' => ['Khalanga', 'Gokuleshwar', 'Malikarjun'],
        ];
    }
}
