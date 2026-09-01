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
    /**
     * Permission guard used by the application.
     */
    private string $guardName = 'web';

    /**
     * Synchronize route permissions and admin menus.
     */
    public function sync(): array
    {
        /*
        |--------------------------------------------------------------------------
        | Clear Spatie permission cache
        |--------------------------------------------------------------------------
        */

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        $createdPermissions = [];
        $syncedMenus = 0;

        /*
        |--------------------------------------------------------------------------
        | Scan application routes
        |--------------------------------------------------------------------------
        */

        foreach (RouteFacade::getRoutes() as $route) {
            if (! $route instanceof Route) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Extract permissions from route middleware
            |--------------------------------------------------------------------------
            */

            $permissions = $this->extractPermissions($route);

            /*
            |--------------------------------------------------------------------------
            | Synchronize permissions
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
            | Synchronize admin menu
            |--------------------------------------------------------------------------
            */

            $menu = $route->getAction('_admin_menu');

            if (
                is_array($menu) &&
                $menu !== []
            ) {
                /*
                |--------------------------------------------------------------------------
                | Prefer the .view permission for the menu
                |--------------------------------------------------------------------------
                |
                | Example:
                |
                | pickups.view
                | pickups.assign
                | pickups.transfer
                |
                | The menu should normally use:
                |
                | pickups.view
                |
                */

                $menuPermission =
                    collect($permissions)
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
        | Super admin
        |--------------------------------------------------------------------------
        |
        | Super admin receives every synchronized permission.
        |
        */

        $this->syncSuperAdmin();

        /*
        |--------------------------------------------------------------------------
        | Clear permission cache again
        |--------------------------------------------------------------------------
        */

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        return [
            'permissions' => count(
                $createdPermissions
            ),

            'menus' => $syncedMenus,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | EXTRACT PERMISSIONS
    |--------------------------------------------------------------------------
    |
    | Reads middleware such as:
    |
    | permission:staff.view
    |
    | permission:staff.edit
    |
    | permission:staff.view,web
    |
    | permission:staff.view|staff.edit
    |
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
            | Only process permission middleware
            |--------------------------------------------------------------------------
            */

            if (! str_starts_with(
                $middleware,
                'permission:'
            )) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Remove "permission:"
            |--------------------------------------------------------------------------
            */

            $definition = Str::after(
                $middleware,
                'permission:'
            );

            /*
            |--------------------------------------------------------------------------
            | Remove optional guard
            |--------------------------------------------------------------------------
            |
            | permission:staff.view,web
            |
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
            | permission:staff.view|staff.edit
            |
            */

            foreach (
                explode('|', $definition) as $permission
            ) {
                $permission = trim(
                    $permission
                );

                if ($permission === '') {
                    continue;
                }

                $permissions[] = $permission;
            }
        }

        return array_values(
            array_unique(
                $permissions
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SYNCHRONIZE PERMISSION
    |--------------------------------------------------------------------------
    */

    private function syncPermission(
        string $permissionName
    ): Permission {
        /*
        |--------------------------------------------------------------------------
        | Create permission if it does not exist
        |--------------------------------------------------------------------------
        */

        $permission = Permission::query()
            ->firstOrCreate([
                'name' => $permissionName,

                'guard_name' => $this->guardName,
            ]);

        /*
        |--------------------------------------------------------------------------
        | Generate display information
        |--------------------------------------------------------------------------
        */

        $displayName = $this->displayName(
            $permissionName
        );

        $groupName = $this->groupName(
            $permissionName
        );

        $updates = [];

        /*
        |--------------------------------------------------------------------------
        | Optional custom permission columns
        |--------------------------------------------------------------------------
        |
        | Your project may have some or all of these columns.
        | Only update columns that actually exist.
        |
        */

        if (
            Schema::hasColumn(
                'permissions',
                'display_name'
            )
        ) {
            $updates['display_name'] =
                $displayName;
        }

        if (
            Schema::hasColumn(
                'permissions',
                'label'
            )
        ) {
            $updates['label'] =
                $displayName;
        }

        if (
            Schema::hasColumn(
                'permissions',
                'description'
            )
        ) {
            $updates['description'] =
                $displayName;
        }

        if (
            Schema::hasColumn(
                'permissions',
                'group'
            )
        ) {
            $updates['group'] =
                $groupName;
        }

        if (
            Schema::hasColumn(
                'permissions',
                'group_name'
            )
        ) {
            $updates['group_name'] =
                $groupName;
        }

        if (
            Schema::hasColumn(
                'permissions',
                'module'
            )
        ) {
            $updates['module'] =
                $groupName;
        }

        if (
            Schema::hasColumn(
                'permissions',
                'is_active'
            )
        ) {
            $updates['is_active'] = true;
        }

        /*
        |--------------------------------------------------------------------------
        | Update optional metadata
        |--------------------------------------------------------------------------
        */

        if ($updates !== []) {
            $permission->update(
                $updates
            );
        }

        return $permission;
    }

    /*
    |--------------------------------------------------------------------------
    | SYNCHRONIZE MENU
    |--------------------------------------------------------------------------
    */

    private function syncMenu(
        array $menu
    ): void {
        $table = (new MenuItem())
            ->getTable();

        /*
        |--------------------------------------------------------------------------
        | Route
        |--------------------------------------------------------------------------
        */

        $route = trim(
            (string) (
                $menu['route'] ?? ''
            )
        );

        if ($route === '') {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Section
        |--------------------------------------------------------------------------
        */

        $section = trim(
            (string) (
                $menu['section'] ?? 'admin'
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Prepare menu data
        |--------------------------------------------------------------------------
        */

        $data = $this->filterColumns(
            $table,
            [
                'section' =>
                    $section,

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

                'route' =>
                    $route,

                'href' =>
                    $route,

                'url' =>
                    $route,

                'path' =>
                    $route,

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

                'is_active' =>
                    true,

                'updated_at' =>
                    now(),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Find the actual route column
        |--------------------------------------------------------------------------
        |
        | Different versions of the menu table may use:
        |
        | route
        | href
        | url
        | path
        |
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

        /*
        |--------------------------------------------------------------------------
        | Respect section when available
        |--------------------------------------------------------------------------
        */

        if (
            Schema::hasColumn(
                $table,
                'section'
            )
        ) {
            $query->where(
                'section',
                $section
            );
        }

        $existing = $query->first();

        /*
        |--------------------------------------------------------------------------
        | Update existing menu
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
        | Insert new menu
        |--------------------------------------------------------------------------
        */

        if (
            Schema::hasColumn(
                $table,
                'created_at'
            )
        ) {
            $data['created_at'] = now();
        }

        DB::table($table)
            ->insert($data);
    }

    /*
    |--------------------------------------------------------------------------
    | SYNCHRONIZE SUPER ADMIN
    |--------------------------------------------------------------------------
    */

    private function syncSuperAdmin(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Find or create super_admin role
        |--------------------------------------------------------------------------
        */

        $superAdmin = Role::query()
            ->firstOrCreate([
                'name' =>
                    'super_admin',

                'guard_name' =>
                    $this->guardName,
            ]);

        /*
        |--------------------------------------------------------------------------
        | Get all permissions for this guard
        |--------------------------------------------------------------------------
        */

        $permissions = Permission::query()
            ->where(
                'guard_name',
                $this->guardName
            )
            ->pluck('name')
            ->all();

        /*
        |--------------------------------------------------------------------------
        | Give every permission to super admin
        |--------------------------------------------------------------------------
        */

        $superAdmin->syncPermissions(
            $permissions
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DISPLAY NAME
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | pickups.assignable_staff
    |
    | becomes:
    |
    | Pickups Assignable Staff
    |
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
    | GROUP NAME
    |--------------------------------------------------------------------------
    |
    | Example:
    |
    | pricing.transfer_routes.view
    |
    | becomes:
    |
    | Pricing Transfer Routes
    |
    */

    private function groupName(
        string $permission
    ): string {
        $segments = explode(
            '.',
            $permission
        );

        /*
        |--------------------------------------------------------------------------
        | Remove action
        |--------------------------------------------------------------------------
        |
        | pricing.transfer_routes.view
        |
        | becomes:
        |
        | pricing.transfer_routes
        |
        */

        if (count($segments) > 1) {
            array_pop($segments);
        }

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
    | FILTER EXISTING DATABASE COLUMNS
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