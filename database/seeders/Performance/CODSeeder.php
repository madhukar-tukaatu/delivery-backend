<?php

namespace Database\Seeders\Performance;

use Database\Seeders\SeederConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CODSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('cod_records')->whereIn('shipment_id', DB::table('shipments')->where('source','performance_seed')->select('id'))->exists()) return;
        $riders = DB::table('users')->where('role','rider')->pluck('id')->all();
        $rows = [];
        DB::table('shipments')->where('source','performance_seed')->where('payment_type','cod')->orderBy('id')->chunk(1000, function ($shipments) use (&$rows, $riders) {
            foreach ($shipments as $s) {
                $collected = $s->status === 'delivered';
                $rows[] = ['shipment_id'=>$s->id,'merchant_id'=>$s->merchant_id,'cod_amount'=>$s->cod_amount,'delivery_charge'=>$s->delivery_charge,'cod_charge'=>$s->cod_charge,'collected_amount'=>$collected ? $s->total_collectable_amount : 0,'status'=>$collected ? 'collected' : 'pending','collected_by'=>$collected && $riders ? $riders[array_rand($riders)] : null,'collected_at'=>$collected ? now()->subDays(mt_rand(0,10)) : null,'deposited_to_branch_id'=>$collected ? $s->destination_branch_id : null,'deposited_at'=>$collected && mt_rand(1,100) <= 70 ? now()->subDays(mt_rand(0,8)) : null,'settled_at'=>null,'created_at'=>now(),'updated_at'=>now()];
            }
            if (count($rows) >= SeederConfig::performance()['chunk']) { DB::table('cod_records')->insert($rows); $rows = []; }
        });
        if ($rows) DB::table('cod_records')->insert($rows);

        $depositRows = [];
        DB::table('cod_records')->where('status','collected')->whereNotNull('deposited_to_branch_id')->selectRaw('deposited_to_branch_id as branch_id, collected_by as staff_id, SUM(collected_amount) as amount')->groupBy('deposited_to_branch_id','collected_by')->chunk(500, function ($groups) use (&$depositRows) {
            foreach ($groups as $g) $depositRows[] = ['branch_id'=>$g->branch_id,'staff_id'=>$g->staff_id,'amount'=>$g->amount,'status'=>'confirmed','remarks'=>'Performance seed COD deposit','created_at'=>now(),'updated_at'=>now()];
        });
        if ($depositRows) DB::table('cod_deposits')->insert($depositRows);
    }
}
