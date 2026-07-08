<?php

namespace Database\Seeders\Performance;

use Database\Seeders\Helpers\NepalData;
use Database\Seeders\SeederConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $target = SeederConfig::performance()['customers'];
        if (DB::table('customers')->where('phone','like','97%')->count() >= $target) return;
        $merchants = DB::table('merchants')->pluck('id')->all();
        $areas = DB::table('branches')->where('type','sub_branch')->get();
        $names = NepalData::names();
        $rows = [];
        for ($i = 1; $i <= $target; $i++) {
            $area = $areas->random();
            $rows[] = ['merchant_id'=>$merchants[array_rand($merchants)] ?? null,'name'=>$names[array_rand($names)].' '.$i,'phone'=>'97'.str_pad((string)(10000000+$i), 8, '0', STR_PAD_LEFT),'email'=>'customer'.$i.'@example.test','city'=>$area->city,'area'=>$area->area,'address'=>$area->area.', '.$area->city,'type'=>($i % 8 === 0) ? 'business' : 'individual','created_at'=>now(),'updated_at'=>now()];
        }
        foreach (array_chunk($rows, SeederConfig::performance()['chunk']) as $chunk) DB::table('customers')->insertOrIgnore($chunk);
    }
}
