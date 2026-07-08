<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Branch\Models\Branch;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $main = Branch::where('code', 'HO')->first();
        $ktm = Branch::where('name', 'Kathmandu Branch')->first() ?: Branch::where('type', 'branch')->first();
        $sub = Branch::where('city', 'Kathmandu')->where('type', 'sub_branch')->first() ?: Branch::where('type', 'sub_branch')->first();
        $users = [
            ['admin@example.com','Super Admin','9800000000','super_admin',$main?->id],
            ['main@example.com','Main Branch Admin','9800000004','main_admin',$main?->id],
            ['branch@example.com','Kathmandu Branch Manager','9800000001','branch_manager',$ktm?->id],
            ['subbranch@example.com','Sub-Branch Manager','9800000005','sub_branch_manager',$sub?->id],
            ['booking@example.com','Booking Staff','9800000006','booking_staff',$sub?->id],
            ['dispatch@example.com','Dispatch Staff','9800000007','dispatch_staff',$ktm?->id],
            ['rider@example.com','Demo Rider','9800000002','rider',$sub?->id],
            ['accounts@example.com','Accounts Staff','9800000003','accounts_staff',$main?->id],
            ['support@example.com','Support Staff','9800000008','support_staff',$main?->id],
        ];
        foreach ($users as [$email,$name,$phone,$role,$branchId]) {
            $user = User::updateOrCreate(['email' => $email], ['name'=>$name,'phone'=>$phone,'role'=>$role,'branch_id'=>$branchId,'password'=>Hash::make('password'),'is_active'=>true]);
            $user->syncRoles([$role]);
        }
    }
}
