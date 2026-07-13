<?php

namespace App\Console\Commands;

use Database\Seeders\System\PermissionSeeder;
use Database\Seeders\System\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ResetPermissionsClean extends Command
{
    protected $signature = 'app:reset-permissions-clean 
                            {--yes : Confirm reset without prompt}
                            {--admin-email= : Assign super_admin role to this email after reset}';

    protected $description = 'Reset all permissions and role-permission mappings, then reseed clean system permissions and roles.';

    public function handle(): int
    {
        if (!$this->option('yes')) {
            if (!$this->confirm('This will delete existing permissions and role permission assignments. Continue?')) {
                $this->warn('Cancelled.');

                return self::SUCCESS;
            }
        }

        $this->info('Clearing permission cache...');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->callSilent('optimize:clear');
        $this->callSilent('cache:clear');
        $this->callSilent('config:clear');
        $this->callSilent('route:clear');

        DB::transaction(function () {
            $this->info('Deleting old role permission mappings...');

            DB::table('role_has_permissions')->delete();
            DB::table('model_has_permissions')->delete();

            $this->info('Deleting old permissions...');
            DB::table('permissions')->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('Seeding clean permissions...');
        $this->call('db:seed', [
            '--class' => PermissionSeeder::class,
            '--force' => true,
        ]);

        $this->info('Seeding clean roles and role permissions...');
        $this->call('db:seed', [
            '--class' => RoleSeeder::class,
            '--force' => true,
        ]);

        $adminEmail = $this->option('admin-email') ?: env('PRODUCTION_ADMIN_EMAIL', 'admin@example.com');

        if ($adminEmail) {
            $userModel = config('auth.providers.users.model', \App\Models\User::class);

            $user = $userModel::query()
                ->where('email', $adminEmail)
                ->first();

            if ($user) {
                $user->syncRoles(['super_admin']);

                $this->info("super_admin role assigned to {$adminEmail}");
            } else {
                $this->warn("Admin user not found: {$adminEmail}");
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->newLine();

        $this->table(
            ['Type', 'Count'],
            [
                ['Permissions', Permission::query()->count()],
                ['Roles', Role::query()->count()],
                ['Super Admin Permissions', Role::where('name', 'super_admin')->first()?->permissions()->count() ?? 0],
            ]
        );

        $this->info('Permissions and roles reset successfully.');
        $this->info('Logout and login again in frontend.');

        return self::SUCCESS;
    }
}