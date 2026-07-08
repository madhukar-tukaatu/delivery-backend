<?php

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\Branch;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Services\ShipmentService;

class DemoShipmentSeeder extends Seeder
{
    public function run(): void
    {
        $merchant = Merchant::where('code', 'ABC-FASHION')->first();
        $origin = Branch::where('city','Kathmandu')->where('type','branch')->first();
        $originSub = Branch::where('city','Kathmandu')->where('type','sub_branch')->first();
        $dest = Branch::where('city','Kaski')->where('type','branch')->first() ?: Branch::where('city','Pokhara')->where('type','branch')->first() ?: Branch::where('type','branch')->skip(4)->first();
        $destSub = Branch::where('parent_id',$dest?->id)->where('type','sub_branch')->first();
        if (!$merchant || !$origin || !$dest) return;
        if (!\Modules\Shipment\Models\Shipment::where('merchant_order_id', 'ORD-1001')->exists()) {
            app(ShipmentService::class)->create(['merchant_order_id'=>'ORD-1001','pickup_name'=>'ABC Fashion Store','pickup_phone'=>'9811111111','pickup_address'=>'New Baneshwor, Kathmandu','pickup_city'=>'Kathmandu','pickup_area'=>'Baneshwor','origin_branch_id'=>$origin->id,'origin_sub_branch_id'=>$originSub?->id,'destination_branch_id'=>$dest->id,'destination_sub_branch_id'=>$destSub?->id,'customer_name'=>'Ram Sharma','customer_phone'=>'9822222222','customer_address'=>'Lakeside, Pokhara','customer_city'=>$dest->city,'customer_area'=>$destSub?->area,'product_description'=>'Shoes','quantity'=>1,'weight'=>1.5,'declared_value'=>3500,'payment_type'=>'cod','cod_amount'=>3500,'delivery_charge_paid_by'=>'customer'], null, $merchant->id, 'seed');
        }
    }
}
