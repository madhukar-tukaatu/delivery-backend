<?php

namespace Database\Seeders\Production;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class ProductionAdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $mainBranchId = DB::table('branches')
            ->where('code', 'NP-KTM-MAIN')
            ->value('id');

        $user = User::query()->firstOrCreate(
            ['email' => env('PRODUCTION_ADMIN_EMAIL', 'admin@example.com')],
            [
                'name' => env('PRODUCTION_ADMIN_NAME', 'Tukaatu Admin'),
                'phone' => env('PRODUCTION_ADMIN_PHONE', '9800000000'),
                'password' => Hash::make(env('PRODUCTION_ADMIN_PASSWORD', 'password')),
                'role' => 'super_admin',
                'branch_id' => $mainBranchId,
                'is_active' => true,
            ]
        );

        $update = [
            'name' => env('PRODUCTION_ADMIN_NAME', 'Tukaatu Admin'),
            'phone' => env('PRODUCTION_ADMIN_PHONE', '9800000000'),
            'role' => 'super_admin',
            'branch_id' => $mainBranchId,
            'is_active' => true,
            'updated_at' => now(),
        ];

        if (env('RESET_PRODUCTION_ADMIN_PASSWORD', false)) {
            $update['password'] = Hash::make(env('PRODUCTION_ADMIN_PASSWORD', 'password'));
        }

        $cleanUpdate = collect($update)
            ->filter(fn ($value, $column) => Schema::hasColumn('users', $column))
            ->toArray();

        DB::table('users')
            ->where('id', $user->id)
            ->update($cleanUpdate);

        if (method_exists($user, 'syncRoles')) {
            $user->syncRoles(['super_admin']);
        }

        $this->command?->info('Production admin user ready: ' . $user->email);
    }
}