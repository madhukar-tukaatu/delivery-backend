<?php

namespace Database\Seeders\Nepal;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;

class NepalSubBranchSeeder extends Seeder
{
    public function run(): void
    {
        $districts = DB::table('nepal_districts')->get()->keyBy('id');
        foreach (DB::table('nepal_municipalities')->orderBy('district_id')->orderBy('name')->get() as $area) {
            $district = $districts[$area->district_id];
            $parent = Branch::where('type', 'branch')->where('city', $district->name)->first();
            if (!$parent) {
                continue;
            }
            $code = 'SB-'.Str::upper(Str::substr(Str::slug($district->name.' '.$area->name, ''), 0, 14));
            $sub = Branch::firstOrCreate(['code' => $code], [
                'parent_id' => $parent->id,
                'name' => $area->name.' Sub-Branch',
                'type' => 'sub_branch',
                'phone' => '97'.str_pad((string) $area->id, 8, '0', STR_PAD_LEFT),
                'email' => Str::slug($area->name).'.sub@courier.test',
                'city' => $district->name,
                'area' => $area->name,
                'address' => $area->name.', '.$district->name,
                'status' => 'active',
            ]);
            DB::table('branch_service_areas')->updateOrInsert(['branch_id' => $sub->id, 'city' => $district->name, 'area' => $area->name], [
                'postal_code' => null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
