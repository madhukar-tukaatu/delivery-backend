<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Branch\Models\Branch;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantApiKey;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Merchant\Models\MerchantWebhook;
use Modules\Rate\Models\MerchantRateCard;
use Modules\Rate\Models\RateCard;
use Modules\Rate\Models\RateRule;

class DemoMerchantSeeder extends Seeder
{
    public function run(): void
    {
        $branch = Branch::where('city', 'Kathmandu')->where('type', 'branch')->first() ?: Branch::where('type', 'branch')->first();
        $sub = Branch::where('city', 'Kathmandu')->where('type', 'sub_branch')->first() ?: Branch::where('type', 'sub_branch')->first();
        $merchant = Merchant::firstOrCreate(['code' => 'ABC-FASHION'], ['name' => 'ABC Fashion Store', 'owner_name' => 'ABC Owner', 'contact_person' => 'ABC Manager', 'phone' => '9811111111', 'email' => 'merchant@example.com', 'website_url' => 'https://example-store.test', 'business_type' => 'Fashion', 'address' => 'New Baneshwor, Kathmandu', 'default_branch_id' => $branch?->id, 'default_sub_branch_id' => $sub?->id, 'status' => 'active']);
        $user = User::updateOrCreate(['email' => 'merchant@example.com'], ['name' => 'ABC Fashion Merchant', 'phone' => '9811111111', 'role' => 'merchant', 'merchant_id' => $merchant->id, 'password' => Hash::make('password'), 'is_active' => true]);
        $user->syncRoles(['merchant']);
        MerchantPickupLocation::firstOrCreate(['merchant_id' => $merchant->id, 'name' => 'Main Warehouse'], ['branch_id' => $branch?->id, 'sub_branch_id' => $sub?->id, 'contact_person' => 'Warehouse Manager', 'phone' => '9811111111', 'city' => 'Kathmandu', 'area' => 'Baneshwor', 'address' => 'New Baneshwor, Kathmandu', 'is_default' => true, 'status' => 'active']);
        MerchantApiKey::firstOrCreate(['api_key' => 'demo_public_key'], ['merchant_id' => $merchant->id, 'name' => 'Demo Sandbox Key', 'api_secret_hash' => Hash::make('demo_secret'), 'environment' => 'sandbox', 'permissions' => ['shipments:create', 'shipments:read', 'rates:calculate'], 'status' => 'active']);
        MerchantWebhook::firstOrCreate(['merchant_id' => $merchant->id, 'url' => 'https://webhook.site/demo'], ['secret' => 'demo_webhook_secret', 'events' => ['shipment.created', 'shipment.status_changed', 'delivery.delivered', 'pod.collected'], 'status' => 'active']);
        $rateCard = RateCard::firstOrCreate(['code' => 'DEFAULT'], ['name' => 'Default Courier Rate Card', 'status' => 'active']);
        RateRule::firstOrCreate(['rate_card_id' => $rateCard->id, 'origin_city' => null, 'destination_city' => null, 'min_weight' => 0, 'max_weight' => 5], ['base_charge' => 200, 'extra_per_kg' => 60, 'pod_percent' => 1, 'pod_fixed' => 10, 'return_charge' => 100, 'estimated_delivery_time' => '2-4 days', 'status' => 'active']);
        MerchantRateCard::firstOrCreate(['merchant_id' => $merchant->id, 'rate_card_id' => $rateCard->id], ['is_default' => true]);
    }
}
