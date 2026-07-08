<?php

namespace App\Console\Commands;

use App\Support\RoutePermissionMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ResetPermissionsClean extends Command
{
    protected $signature = 'app:reset-permissions-clean {--yes : Confirm reset without prompt}';

    protected $description = 'Reset all permission records and reseed clean grouped permissions';

    public function handle(): int
    {
        if (!$this->option('yes')) {
            if (!$this->confirm('This will delete existing permissions and role permission assignments. Continue?')) {
                $this->warn('Cancelled.');
                return self::SUCCESS;
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            DB::table('role_has_permissions')->delete();
            DB::table('model_has_permissions')->delete();
            DB::table('permissions')->delete();

            $this->seedConfiguredPermissions();
            $this->seedRoutePermissions();
            $this->syncRolePermissions();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('Permissions reset and reseeded cleanly.');
        $this->info('Run php artisan optimize:clear and login again.');

        return self::SUCCESS;
    }

    private function seedConfiguredPermissions(): void
    {
        foreach (config('access.permission_groups', []) as $groupKey => $group) {
            foreach ($group['permissions'] as $permissionName) {
                $this->createPermission($permissionName, $groupKey, $group['label'] ?? $groupKey);
            }
        }
    }

    private function seedRoutePermissions(): void
    {
        foreach (Route::getRoutes() as $route) {
            $routeName = $route->getName();

            if (!$this->shouldGenerateFromRoute($routeName, $route->middleware())) {
                continue;
            }

            $permissionName = RoutePermissionMapper::fromRouteName($routeName);

            if (!$permissionName) {
                continue;
            }

            $groupKey = explode('.', $permissionName)[0];

            $this->createPermission($permissionName, $groupKey, ucwords(str_replace('_', ' ', $groupKey)));
        }
    }

    private function syncRolePermissions(): void
    {
        $allPermissions = Permission::query()
            ->where('guard_name', 'web')
            ->pluck('name')
            ->toArray();

        foreach (config('access.role_permissions', []) as $roleName => $permissions) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            if ($permissions === ['*']) {
                $role->syncPermissions($allPermissions);
                continue;
            }

            $validPermissions = array_values(array_intersect($permissions, $allPermissions));

            $role->syncPermissions($validPermissions);
        }
    }

    private function createPermission(string $name, string $groupKey, string $groupLabel): void
    {
        $data = [
            'name' => $name,
            'guard_name' => 'web',
        ];

        $extra = [];

        if (Schema::hasColumn('permissions', 'group')) {
            $extra['group'] = $groupKey;
        }

        if (Schema::hasColumn('permissions', 'group_label')) {
            $extra['group_label'] = $groupLabel;
        }

        if (Schema::hasColumn('permissions', 'label')) {
            $extra['label'] = $this->labelFromPermission($name);
        }

        if (Schema::hasColumn('permissions', 'description')) {
            $extra['description'] = $this->labelFromPermission($name);
        }

        Permission::firstOrCreate($data, $extra);
    }

    private function shouldGenerateFromRoute(?string $routeName, array $middleware): bool
    {
        if (!$routeName) {
            return false;
        }

        if (
            !str_starts_with($routeName, 'admin.')
            && !str_starts_with($routeName, 'merchant.')
            && !str_starts_with($routeName, 'staff.')
        ) {
            return false;
        }

        return in_array('route.permission', $middleware, true);
    }

    private function labelFromPermission(string $permission): string
    {
        [$module, $action] = array_pad(explode('.', $permission, 2), 2, '');

        $moduleLabel = ucwords(str_replace('_', ' ', $module));
        $actionLabel = ucwords(str_replace('_', ' ', $action));

        return trim("{$actionLabel} {$moduleLabel}");
    }
}