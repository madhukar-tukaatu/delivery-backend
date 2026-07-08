<?php

namespace Database\Seeders\Performance;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DispatchSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('dispatch_manifests')->where('manifest_number','like','PERF-MAN-%')->exists()) return;
        $users = DB::table('users')->where('role','dispatch_staff')->pluck('id')->all();
        $shipments = DB::table('shipments')->where('source','performance_seed')->whereIn('status',['in_transit','received_at_destination','out_for_delivery','delivered','delivery_failed'])->select('id','origin_branch_id','origin_sub_branch_id','destination_branch_id','destination_sub_branch_id','status')->get()->groupBy(fn($s) => $s->origin_branch_id.'-'.$s->destination_branch_id);
        $manifestNo = 1;
        foreach ($shipments as $group) {
            foreach ($group->chunk(40) as $chunk) {
                $first = $chunk->first();
                $manifestId = DB::table('dispatch_manifests')->insertGetId(['manifest_number'=>'PERF-MAN-'.str_pad((string)$manifestNo++, 7, '0', STR_PAD_LEFT),'from_branch_id'=>$first->origin_branch_id,'from_sub_branch_id'=>$first->origin_sub_branch_id,'to_branch_id'=>$first->destination_branch_id,'to_sub_branch_id'=>$first->destination_sub_branch_id,'vehicle_number'=>'BA '.mt_rand(1,9).' KHA '.mt_rand(1000,9999),'driver_name'=>'Driver '.mt_rand(1,500),'seal_number'=>'SEAL'.mt_rand(10000,99999),'status'=>'received','created_by'=>$users ? $users[array_rand($users)] : null,'received_by'=>$users ? $users[array_rand($users)] : null,'dispatched_at'=>now()->subDays(mt_rand(0,3)),'received_at'=>now()->subDays(mt_rand(0,2)),'created_at'=>now(),'updated_at'=>now()]);
                $items = [];
                foreach ($chunk as $s) $items[] = ['dispatch_manifest_id'=>$manifestId,'shipment_id'=>$s->id,'status'=>'received','created_at'=>now(),'updated_at'=>now()];
                DB::table('dispatch_manifest_items')->insert($items);
            }
        }
    }
}
