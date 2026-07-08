<?php

namespace Database\Seeders\Performance;

use App\Models\User;
use Database\Seeders\SeederConfig;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $perBranch = SeederConfig::performance()['staff_per_branch'];
        $roles = ['branch_manager','sub_branch_manager','booking_staff','dispatch_staff','pickup_staff','accounts_staff','support_staff'];
        $branches = \Modules\Branch\Models\Branch::whereIn('type', ['branch','sub_branch'])->get();
        foreach ($branches as $branch) {
            for ($i = 1; $i <= $perBranch; $i++) {
                $role = $roles[($branch->id + $i) % count($roles)];
                if ($branch->type === 'branch' && $i === 1) $role = 'branch_manager';
                if ($branch->type === 'sub_branch' && $i === 1) $role = 'sub_branch_manager';
                $email = 'staff-'.$role.'-'.$branch->id.'-'.$i.'@example.test';
                $user = User::updateOrCreate(['email' => $email], [
                    'name' => Str::headline($role).' '.$branch->name.' '.$i,
                    'phone' => '96'.str_pad((string)(($branch->id * 1000) + $i), 8, '0', STR_PAD_LEFT),
                    'role' => $role,
                    'branch_id' => $branch->id,
                    'password' => Hash::make('password'),
                    'is_active' => true,
                ]);
                $user->syncRoles([$role]);
            }
        }
    }
}
