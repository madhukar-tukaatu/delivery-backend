<?php

namespace Database\Seeders\Nepal;

use Database\Seeders\Helpers\NepalData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NepalProvinceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (NepalData::provinces() as $province) {
            DB::table('nepal_provinces')->updateOrInsert(['code' => $province['code']], array_merge($province, ['created_at' => now(), 'updated_at' => now()]));
        }
    }
}
