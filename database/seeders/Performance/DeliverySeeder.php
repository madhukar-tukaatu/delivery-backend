<?php

namespace Database\Seeders\Performance;

use Database\Seeders\SeederConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DeliverySeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('delivery_assignments')->where('status','like','perf_%')->exists()) return;
        $ridersByBranch = DB::table('users')->where('role','rider')->get()->groupBy('branch_id');
        $shipments = DB::table('shipments')->where('source','performance_seed')->whereIn('status',['out_for_delivery','delivered','delivery_failed'])->get();
        $assignRows = [];
        foreach ($shipments as $s) {
            $riders = $ridersByBranch[$s->destination_sub_branch_id] ?? $ridersByBranch->flatten();
            $rider = $riders->isNotEmpty() ? $riders->random() : null;
            $status = $s->status === 'delivered' ? 'perf_completed' : ($s->status === 'delivery_failed' ? 'perf_failed' : 'perf_assigned');
            $assignRows[] = ['shipment_id'=>$s->id,'delivery_staff_id'=>$rider?->id,'assigned_date'=>now()->subDays(mt_rand(0,3))->toDateString(),'status'=>$status,'assigned_by'=>null,'created_at'=>now(),'updated_at'=>now()];
            if (count($assignRows) >= SeederConfig::performance()['chunk']) { DB::table('delivery_assignments')->insert($assignRows); $assignRows = []; }
        }
        if ($assignRows) DB::table('delivery_assignments')->insert($assignRows);

        $attemptRows = [];
        DB::table('delivery_assignments')->where('status','like','perf_%')->orderBy('id')->chunk(1000, function ($assignments) use (&$attemptRows) {
            $shipments = DB::table('shipments')->whereIn('id', $assignments->pluck('shipment_id'))->get()->keyBy('id');
            foreach ($assignments as $a) {
                $s = $shipments[$a->shipment_id];
                if ($s->status === 'out_for_delivery') continue;
                $attemptRows[] = ['delivery_assignment_id'=>$a->id,'shipment_id'=>$s->id,'delivery_staff_id'=>$a->delivery_staff_id,'status'=>$s->status === 'delivered' ? 'delivered' : 'failed','failure_reason'=>$s->status === 'delivery_failed' ? ['Customer unavailable','Wrong address','Phone unreachable'][array_rand(['Customer unavailable','Wrong address','Phone unreachable'])] : null,'receiver_name'=>$s->status === 'delivered' ? $s->receiver_name : null,'receiver_phone'=>$s->status === 'delivered' ? $s->receiver_phone : null,'cod_collected_amount'=>$s->status === 'delivered' ? $s->total_collectable_amount : 0,'remarks'=>'Performance seed delivery attempt','proof_photo_path'=>null,'signature_data'=>null,'created_at'=>now(),'updated_at'=>now()];
            }
            if (count($attemptRows) >= 1000) { DB::table('delivery_attempts')->insert($attemptRows); $attemptRows = []; }
        });
        if ($attemptRows) DB::table('delivery_attempts')->insert($attemptRows);
    }
}
