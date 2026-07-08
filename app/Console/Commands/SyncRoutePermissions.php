<?php

namespace App\Console\Commands;

use App\Support\RoutePermissionMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class SyncRoutePermissions extends Command
{
    protected $signature = 'app:sync-route-permissions';

    protected $description = 'Generate permissions from named routes protected by route.permission middleware';

    public function handle(): int
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $created = 0;
        $existing = 0;
        $skipped = 0;

        foreach (Route::getRoutes() as $route) {
            $routeName = $route->getName();
            $middleware = $route->gatherMiddleware();

            if (!$this->shouldGeneratePermission($routeName, $middleware)) {
                $skipped++;
                continue;
            }

            $permissionName = RoutePermissionMapper::fromRouteName($routeName);

            if (!$permissionName) {
                $skipped++;
                continue;
            }

            $permission = Permission::firstOrCreate(
                [
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ],
                [
                    'group' => explode('.', $permissionName)[0] ?? 'general',
                    'description' => ucwords(str_replace(['.', '_', '-'], ' ', $permissionName)),
                ]
            );

            $permission->wasRecentlyCreated ? $created++ : $existing++;
        }

        $superAdmin = Role::firstOrCreate(
            [
                'name' => 'super_admin',
                'guard_name' => 'web',
            ],
            [
                'label' => 'Super Admin',
                'is_system' => true,
            ]
        );

        $superAdmin->syncPermissions(
            Permission::where('guard_name', 'web')->pluck('name')->toArray()
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('Route permissions synced.');
        $this->line('Created: '.$created);
        $this->line('Already existed: '.$existing);
        $this->line('Skipped routes: '.$skipped);
        $this->line('Super admin refreshed with all permissions.');

        return self::SUCCESS;
    }

    private function shouldGeneratePermission(?string $routeName, array $middleware): bool
    {
        if (!$routeName) {
            return false;
        }

        if (!str_starts_with($routeName, 'admin.')
            && !str_starts_with($routeName, 'merchant.')
            && !str_starts_with($routeName, 'staff.')) {
            return false;
        }

        return in_array('route.permission', $middleware, true);
    }
}
