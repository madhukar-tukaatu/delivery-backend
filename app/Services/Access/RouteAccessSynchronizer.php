<?php

declare(strict_types=1);

namespace App\Services\Access;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Access\Models\MenuItem;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RouteAccessSynchronizer
{
    private string $guardName = 'web';

    public function sync(): array
    {
        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        $createdPermissions = [];
        $syncedMenus = 0;

        foreach (RouteFacade::getRoutes() as $route) {

            if (! $route instanceof Route) {
                continue;
            }

            $permissions = $this->extractPermissions($route);

            /*
            |--------------------------------------------------------------------------
            | Sync permissions
            |--------------------------------------------------------------------------
            */

            foreach ($permissions as $permissionName) {

                $permission = $this->syncPermission(
                    $permissionName
                );

                $createdPermissions[$permission->name] =
                    $permission->name;
            }

            /*
            |--------------------------------------------------------------------------
            | Admin menu
            |--------------------------------------------------------------------------
            */

            $menu = $route->getAction('_admin_menu');

            if (is_array($menu) && $menu !== []) {

                /*
                |--------------------------------------------------------------------------
                | Prefer *.view permission
                |--------------------------------------------------------------------------
                */

                $menuPermission = collect($permissions)
                    ->first(
                        static fn(string $permission): bool =>
                            str_ends_with(
                                $permission,
                                '.view'
                            )
                    )
                    ?? collect($permissions)->first();

                if ($menuPermission !== null) {
                    $menu['permission'] = $menuPermission;
                }

                $this->syncMenu($menu);

                $syncedMenus++;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Super Admin
        |--------------------------------------------------------------------------
        */

        $this->syncSuperAdmin();

        /*
        |--------------------------------------------------------------------------
        | Clear Spatie permission cache
        |--------------------------------------------------------------------------
        */

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        return [
            'permissions' => count($createdPermissions),
            'menus' => $syncedMenus,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Extract Route Permissions
    |--------------------------------------------------------------------------
    */

    private function extractPermissions(
        Route $route
    ): array {
        $permissions = [];

        foreach ($route->gatherMiddleware() as $middleware) {

            if (! is_string($middleware)) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | route.permission:pickups.view
            |
            | Also support:
            |
            | permission:pickups.view
            |--------------------------------------------------------------------------
            */

            if (
                str_starts_with(
                    $middleware,
                    'route.permission:'
                )
            ) {
                $definition = Str::after(
                    $middleware,
                    'route.permission:'
                );
            } elseif (
                str_starts_with(
                    $middleware,
                    'permission:'
                )
            ) {
                $definition = Str::after(
                    $middleware,
                    'permission:'
                );
            } else {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Remove optional guard
            |--------------------------------------------------------------------------
            |
            | pickups.view,web
            |--------------------------------------------------------------------------
            */

            $definition = explode(
                ',',
                $definition,
                2
            )[0];

            /*
            |--------------------------------------------------------------------------
            | Multiple permissions
            |--------------------------------------------------------------------------
            |
            | pickups.view|pickups.edit
            |--------------------------------------------------------------------------
            */

            foreach (
                explode('|', $definition)
                as $permission
            ) {

                $permission = trim($permission);

                if ($permission === '') {
                    continue;
                }

                $permissions[] = $permission;
            }
        }

        return array_values(
            array_unique($permissions)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Sync Permission
    |--------------------------------------------------------------------------
    */

    private function syncPermission(
        string $permissionName
    ): Permission {
        $permission = Permission::query()
            ->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => $this->guardName,
            ]);

        $displayName = $this->displayName(
            $permissionName
        );

        $groupName = $this->groupName(
            $permissionName
        );

        $updates = [];

        if (Schema::hasColumn(
            'permissions',
            'display_name'
        )) {
            $updates['display_name'] = $displayName;
        }

        if (Schema::hasColumn(
            'permissions',
            'label'
        )) {
            $updates['label'] = $displayName;
        }

        if (Schema::hasColumn(
            'permissions',
            'description'
        )) {
            $updates['description'] = $displayName;
        }

        if (Schema::hasColumn(
            'permissions',
            'group'
        )) {
            $updates['group'] = $groupName;
        }

        if (Schema::hasColumn(
            'permissions',
            'group_name'
        )) {
            $updates['group_name'] = $groupName;
        }

        if (Schema::hasColumn(
            'permissions',
            'module'
        )) {
            $updates['module'] = $groupName;
        }

        if (Schema::hasColumn(
            'permissions',
            'is_active'
        )) {
            $updates['is_active'] = true;
        }

        if ($updates !== []) {
            $permission->update($updates);
        }

        return $permission;
    }

    /*
    |--------------------------------------------------------------------------
    | Sync Menu
    |--------------------------------------------------------------------------
    */

    private function syncMenu(
        array $menu
    ): void {
        $table = (new MenuItem())->getTable();

        $route = trim(
            (string) (
                $menu['route'] ?? ''
            )
        );

        if ($route === '') {
            return;
        }

        $section = trim(
            (string) (
                $menu['section'] ?? 'admin'
            )
        );

        $data = $this->filterColumns(
            $table,
            [
                'section' => $section,

                'title' =>
                    $menu['title']
                    ?? $menu['label']
                    ?? null,

                'label' =>
                    $menu['label']
                    ?? $menu['title']
                    ?? null,

                'name' =>
                    $menu['label']
                    ?? $menu['title']
                    ?? null,

                'route' => $route,

                'href' => $route,

                'url' => $route,

                'path' => $route,

                'icon' =>
                    $menu['icon']
                    ?? 'menu',

                'permission' =>
                    $menu['permission']
                    ?? null,

                'sort_order' =>
                    $menu['sort_order']
                    ?? 999,

                'order' =>
                    $menu['sort_order']
                    ?? 999,

                'is_active' => true,

                'updated_at' => now(),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Determine route column
        |--------------------------------------------------------------------------
        */

        $routeColumn = collect([
            'route',
            'href',
            'url',
            'path',
        ])->first(
            static fn(string $column): bool =>
                Schema::hasColumn(
                    $table,
                    $column
                )
        );

        if (! $routeColumn) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Find existing menu
        |--------------------------------------------------------------------------
        */

        $query = DB::table($table)
            ->where(
                $routeColumn,
                $route
            );

        if (Schema::hasColumn(
            $table,
            'section'
        )) {
            $query->where(
                'section',
                $section
            );
        }

        $existing = $query->first();

        /*
        |--------------------------------------------------------------------------
        | Update existing
        |--------------------------------------------------------------------------
        */

        if ($existing) {

            DB::table($table)
                ->where(
                    'id',
                    $existing->id
                )
                ->update($data);

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Create menu
        |--------------------------------------------------------------------------
        */

        if (Schema::hasColumn(
            $table,
            'created_at'
        )) {
            $data['created_at'] = now();
        }

        DB::table($table)
            ->insert($data);
    }

    /*
    |--------------------------------------------------------------------------
    | Sync Super Admin
    |--------------------------------------------------------------------------
    */

    private function syncSuperAdmin(): void
    {
        $superAdmin = Role::query()
            ->firstOrCreate([
                'name' => 'super_admin',
                'guard_name' => $this->guardName,
            ]);

        $permissions = Permission::query()
            ->where(
                'guard_name',
                $this->guardName
            )
            ->pluck('name')
            ->all();

        $superAdmin->syncPermissions(
            $permissions
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Display Name
    |--------------------------------------------------------------------------
    */

    private function displayName(
        string $permission
    ): string {
        return Str::of($permission)
            ->replace(
                ['.', '_', '-'],
                ' '
            )
            ->title()
            ->toString();
    }

    /*
    |--------------------------------------------------------------------------
    | Group Name
    |--------------------------------------------------------------------------
    */

    private function groupName(
        string $permission
    ): string {
        $segments = explode(
            '.',
            $permission
        );

        array_pop($segments);

        return Str::of(
            implode(
                ' ',
                $segments
            )
        )
            ->replace(
                ['_', '-'],
                ' '
            )
            ->title()
            ->toString();
    }

    /*
    |--------------------------------------------------------------------------
    | Filter Existing Columns
    |--------------------------------------------------------------------------
    */

    private function filterColumns(
        string $table,
        array $data
    ): array {
        return collect($data)
            ->filter(
                static fn(
                    mixed $value,
                    string $column
                ): bool =>
                    Schema::hasColumn(
                        $table,
                        $column
                    )
            )
            ->all();
    }
}