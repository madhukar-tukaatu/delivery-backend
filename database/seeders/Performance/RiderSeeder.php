<?php

namespace Database\Seeders\Performance;

use App\Models\User;
use Database\Seeders\SeederConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RiderSeeder extends Seeder
{
    public function run(): void
    {
        $perBranch = SeederConfig::performance()['riders_per_branch'];
        $subs = \Modules\Branch\Models\Branch::where('type','sub_branch')->get();
        foreach ($subs as $branch) {
            for ($i = 1; $i <= $perBranch; $i++) {
                $email = 'rider-'.$branch->id.'-'.$i.'@example.test';
                $user = User::updateOrCreate(['email' => $email], [
                    'name' => 'Rider '.$branch->area.' '.$i,
                    'phone' => '95'.str_pad((string)(($branch->id * 1000) + $i), 8, '0', STR_PAD_LEFT),
                    'role' => 'rider',
                    'branch_id' => $branch->id,
                    'password' => Hash::make('password'),
                    'is_active' => true,
                ]);
                $user->syncRoles(['rider']);
            }
        }
    }
}
