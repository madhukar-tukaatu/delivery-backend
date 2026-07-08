<?php

namespace Database\Seeders\Nepal;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;

class NepalBranchSeeder extends Seeder
{
    public function run(): void
    {
        $main = Branch::firstOrCreate(['code' => 'HO'], [
            'name' => 'Kathmandu Main Branch',
            'type' => 'main_branch',
            'phone' => '01-5900000',
            'email' => 'head@courier.test',
            'city' => 'Kathmandu',
            'area' => 'Central',
            'address' => 'Kathmandu, Nepal',
            'status' => 'active',
        ]);

        DB::table('nepal_districts')->orderBy('name')->get()->each(function ($district) use ($main) {
            $code = 'BR-'.Str::upper(Str::substr(Str::slug($district->name, ''), 0, 10));
            Branch::firstOrCreate(['code' => $code], [
                'parent_id' => $main->id,
                'name' => $district->name.' Branch',
                'type' => 'branch',
                'phone' => '98'.str_pad((string) $district->id, 8, '0', STR_PAD_LEFT),
                'email' => Str::slug($district->name).'.branch@courier.test',
                'city' => $district->name,
                'area' => $district->headquarter,
                'address' => $district->headquarter.', '.$district->name,
                'status' => 'active',
            ]);
        });
    }
}
