<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Support\RoutePermissionMapper;
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

                // Explicit adminMenu() slug wins. Otherwise prefer *.view.
                if (empty($menu['permission']) && $menuPermission !== null) {
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
            | Generic route.permission middleware
            |--------------------------------------------------------------------------
            |
            | Runtime authorization derives the permission from the route name.
            | Use the same mapper during synchronization so newly added routes
            | create their permissions without a seeder entry.
            |--------------------------------------------------------------------------
            */

            if ($middleware === 'route.permission') {
                $permission = RoutePermissionMapper::fromRouteName(
                    $route->getName()
                );

                if ($permission !== null) {
                    $permissions[] = $permission;
                }

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Explicit route.permission:permission declarations
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
        | Optional parent group
        |--------------------------------------------------------------------------
        |
        | Only set when the route declares a parent label. Existing children
        | of that group are left alone, and menus without a parent keep
        | whatever parent_id they already have.
        |--------------------------------------------------------------------------
        */

        $parentLabel = trim((string) ($menu['parent'] ?? ''));

        if (
            $parentLabel !== ''
            && Schema::hasColumn($table, 'parent_id')
        ) {
            $data['parent_id'] = $this->resolveMenuParentId(
                $table,
                $section,
                $parentLabel
            );
        }

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

    private function resolveMenuParentId(
        string $table,
        string $section,
        string $label
    ): int {
        $query = DB::table($table)
            ->where('section', $section);

        if (Schema::hasColumn($table, 'parent_id')) {
            $query->whereNull('parent_id');
        }

        if (Schema::hasColumn($table, 'label')) {
            $query->where('label', $label);
        } elseif (Schema::hasColumn($table, 'title')) {
            $query->where('title', $label);
        } elseif (Schema::hasColumn($table, 'name')) {
            $query->where('name', $label);
        }

        $existingId = $query->value('id');

        if ($existingId !== null) {
            return (int) $existingId;
        }

        $row = $this->filterColumns($table, [
            'section' => $section,
            'title' => $label,
            'label' => $label,
            'name' => $label,
            'icon' => 'money',
            'sort_order' => 50,
            'order' => 50,
            'is_active' => true,
            'parent_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table($table)->insertGetId($row);
    }

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
        $displayPermission = str_starts_with($permission, 'transfers.')
            ? 'shipment_'.$permission
            : $permission;

        return Str::of($displayPermission)
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

        if (($segments[0] ?? null) === 'transfers') {
            return 'Shipment Transfers';
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