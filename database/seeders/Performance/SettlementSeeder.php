<?php

namespace Database\Seeders\Performance;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettlementSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('merchant_settlements')->where('settlement_number','like','PERF-SET-%')->exists()) return;
        $accounts = DB::table('users')->where('role','accounts_staff')->pluck('id')->all();
        $groups = DB::table('shipments')->where('source','performance_seed')->where('status','delivered')->where('payment_type','cod')->selectRaw('merchant_id, COUNT(*) as total_shipments, SUM(cod_amount) as cod, SUM(delivery_charge) as delivery, SUM(cod_charge) as cod_charge')->groupBy('merchant_id')->get();
        foreach ($groups as $idx => $g) {
            $payable = max(0, $g->cod - $g->delivery - $g->cod_charge);
            $sid = DB::table('merchant_settlements')->insertGetId(['merchant_id'=>$g->merchant_id,'settlement_number'=>'PERF-SET-'.str_pad((string)($idx+1), 7, '0', STR_PAD_LEFT),'period_from'=>now()->subDays(30)->toDateString(),'period_to'=>now()->toDateString(),'total_cod_collected'=>$g->cod,'total_delivery_charges'=>$g->delivery,'total_cod_charges'=>$g->cod_charge,'return_charges'=>0,'adjustments'=>0,'final_payable_amount'=>$payable,'status'=>($idx % 4 === 0) ? 'pending' : 'settled','payment_method'=>'bank_transfer','bank_reference_number'=>'NABIL'.mt_rand(100000,999999),'settled_by'=>$accounts ? $accounts[array_rand($accounts)] : null,'settled_at'=>($idx % 4 === 0) ? null : now()->subDays(mt_rand(0,5)),'created_at'=>now(),'updated_at'=>now()]);
            $items = [];
            DB::table('shipments')->where('source','performance_seed')->where('merchant_id',$g->merchant_id)->where('status','delivered')->where('payment_type','cod')->limit(100)->get()->each(function ($s) use (&$items, $sid) {
                $items[] = ['merchant_settlement_id'=>$sid,'shipment_id'=>$s->id,'cod_amount'=>$s->cod_amount,'delivery_charge'=>$s->delivery_charge,'cod_charge'=>$s->cod_charge,'net_amount'=>$s->cod_amount - $s->delivery_charge - $s->cod_charge,'created_at'=>now(),'updated_at'=>now()];
            });
            if ($items) DB::table('merchant_settlement_items')->insert($items);
        }
    }
}
