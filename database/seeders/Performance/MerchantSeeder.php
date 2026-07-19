<?php

namespace Database\Seeders\Performance;

use Database\Seeders\Helpers\NepalData;
use Database\Seeders\SeederConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MerchantSeeder extends Seeder
{
    public function run(): void
    {
        $target = SeederConfig::performance()['merchants'];
        if (DB::table('merchants')->where('code', 'like', 'PERF-M%')->count() >= $target) return;
        $branches = DB::table('branches')->where('type','branch')->get();
        $subBranches = DB::table('branches')->where('type','sub_branch')->get()->groupBy('parent_id');
        $types = NepalData::merchantTypes();
        $rows = [];
        for ($i = 1; $i <= $target; $i++) {
            $branch = $branches->random();
            $subs = $subBranches[$branch->id] ?? collect([$branch]);
            $sub = $subs->random();
            $type = $types[array_rand($types)];
            $rows[] = ['default_branch_id'=>$branch->id,'default_sub_branch_id'=>$sub->id,'name'=>$type.' Store '.$i,'code'=>'PERF-M'.str_pad((string)$i, 5, '0', STR_PAD_LEFT),'owner_name'=>NepalData::names()[array_rand(NepalData::names())],'contact_person'=>'Manager '.$i,'phone'=>'98'.str_pad((string)(10000000+$i), 8, '0', STR_PAD_LEFT),'email'=>'merchant'.$i.'@example.test','website_url'=>'https://merchant'.$i.'.test','business_type'=>$type,'pan_vat_number'=>'PAN'.str_pad((string)$i, 7, '0', STR_PAD_LEFT),'address'=>$sub->area.', '.$branch->city,'bank_name'=>'Nabil Bank','bank_account_name'=>$type.' Store '.$i,'bank_account_number'=>'00'.str_pad((string)$i, 12, '0', STR_PAD_LEFT),'status'=>'active','created_at'=>now(),'updated_at'=>now()];
        }
        foreach (array_chunk($rows, SeederConfig::performance()['chunk']) as $chunk) DB::table('merchants')->insertOrIgnore($chunk);

        $merchantIds = DB::table('merchants')->where('code','like','PERF-M%')->pluck('id','code');
        $pickupRows = $keyRows = $webhookRows = [];
        foreach ($merchantIds as $code => $id) {
            $m = DB::table('merchants')->find($id);
            $pickupRows[] = ['merchant_id'=>$id,'branch_id'=>$m->default_branch_id,'sub_branch_id'=>$m->default_sub_branch_id,'name'=>'Default Warehouse','contact_person'=>$m->contact_person,'phone'=>$m->phone,'city'=>DB::table('branches')->where('id',$m->default_branch_id)->value('city'),'area'=>DB::table('branches')->where('id',$m->default_sub_branch_id)->value('area'),'address'=>$m->address,'is_default'=>true,'status'=>'active','created_at'=>now(),'updated_at'=>now()];
            $keyRows[] = ['merchant_id'=>$id,'name'=>'Performance Sandbox Key','api_key'=>'perf_key_'.$id,'api_secret_hash'=>Hash::make('perf_secret_'.$id),'environment'=>'sandbox','permissions'=>json_encode(['shipments:create','shipments:read','rates:calculate']),'status'=>'active','created_at'=>now(),'updated_at'=>now()];
            $webhookRows[] = ['merchant_id'=>$id,'url'=>'https://webhook.site/perf-'.$id,'secret'=>'secret-'.$id,'events'=>json_encode(['shipment.created','shipment.status_changed','delivery.delivered','pod.collected']),'status'=>'active','created_at'=>now(),'updated_at'=>now()];
        }
        foreach (array_chunk($pickupRows, 1000) as $chunk) DB::table('merchant_pickup_locations')->insertOrIgnore($chunk);
        foreach (array_chunk($keyRows, 1000) as $chunk) DB::table('merchant_api_keys')->insertOrIgnore($chunk);
        foreach (array_chunk($webhookRows, 1000) as $chunk) DB::table('merchant_webhooks')->insertOrIgnore($chunk);
    }
}
