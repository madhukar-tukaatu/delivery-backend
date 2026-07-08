<?php

namespace Database\Seeders\Performance;

use Database\Seeders\SeederConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PickupSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('pickup_requests')->where('remarks','like','Performance seed%')->exists()) return;
        $shipments = DB::table('shipments')->where('source','performance_seed')->select('id','merchant_id','origin_branch_id','origin_sub_branch_id','sender_name','sender_phone','sender_address','sender_city','sender_area','status')->get();
        $staff = DB::table('users')->where('role','pickup_staff')->pluck('id')->all();
        $rows = $attempts = [];
        foreach ($shipments as $s) {
            $pickupStatus = in_array($s->status, ['booked']) ? 'requested' : 'picked_up';
            $rows[] = ['merchant_id'=>$s->merchant_id,'shipment_id'=>$s->id,'pickup_branch_id'=>$s->origin_branch_id,'pickup_sub_branch_id'=>$s->origin_sub_branch_id,'assigned_to'=>$staff ? $staff[array_rand($staff)] : null,'pickup_name'=>$s->sender_name ?: 'Merchant Warehouse','pickup_phone'=>$s->sender_phone ?: '9800000000','pickup_address'=>$s->sender_address ?: 'Nepal','pickup_city'=>$s->sender_city,'pickup_area'=>$s->sender_area,'preferred_pickup_at'=>now()->subDays(mt_rand(0,5)),'parcel_quantity'=>1,'status'=>$pickupStatus,'remarks'=>'Performance seed pickup','created_at'=>now(),'updated_at'=>now()];
            if (count($rows) >= SeederConfig::performance()['chunk']) { DB::table('pickup_requests')->insert($rows); $rows = []; }
        }
        if ($rows) DB::table('pickup_requests')->insert($rows);

        DB::table('pickup_requests')->where('remarks','Performance seed pickup')->orderBy('id')->chunk(1000, function ($pickups) use (&$attempts) {
            foreach ($pickups as $p) {
                if ($p->status !== 'requested') $attempts[] = ['pickup_request_id'=>$p->id,'staff_id'=>$p->assigned_to,'status'=>'picked_up','remarks'=>'Parcel collected successfully.','created_at'=>now(),'updated_at'=>now()];
            }
            if (count($attempts) >= 1000) { DB::table('pickup_attempts')->insert($attempts); $attempts = []; }
        });
        if ($attempts) DB::table('pickup_attempts')->insert($attempts);
    }
}
