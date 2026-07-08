<?php

namespace Database\Seeders\Nepal;

use Database\Seeders\Helpers\NepalData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NepalDistrictSeeder extends Seeder
{
    public function run(): void
    {
        $provinceIds = DB::table('nepal_provinces')->pluck('id', 'code');
        foreach (NepalData::districts() as [$provinceCode, $district, $hq]) {
            DB::table('nepal_districts')->updateOrInsert(['code' => Str::upper(Str::slug($district, '_'))], [
                'province_id' => $provinceIds[$provinceCode],
                'name' => $district,
                'headquarter' => $hq,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $districtId = DB::table('nepal_districts')->where('name', $district)->value('id');
            foreach (NepalData::municipalitiesFor($district, $hq) as $area) {
                DB::table('nepal_municipalities')->updateOrInsert(['district_id' => $districtId, 'name' => $area], [
                    'type' => 'service_area',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
