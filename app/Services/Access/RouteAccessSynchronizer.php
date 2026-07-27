<?php

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

        foreach (
            RouteFacade::getRoutes()
            as $route
        ) {
            if (!$route instanceof Route) {
                continue;
            }

            $permissions =
                $this->extractPermissions(
                    $route
                );

            foreach (
                $permissions
                as $permissionName
            ) {
                $permission =
                    $this->syncPermission(
                        $permissionName
                    );

                $createdPermissions[
                    $permission->name
                ] = $permission->name;
            }

            $menu =
                $route->getAction(
                    '_admin_menu'
                );

            if (
                is_array($menu) &&
                $menu !== []
            ) {
                /*
                 * Use the view permission from
                 * the index/page route.
                 */
                $menuPermission =
                    collect($permissions)
                        ->first(
                            static fn (
                                string $permission
                            ): bool =>
                                str_ends_with(
                                    $permission,
                                    '.view'
                                )
                        )
                    ?? collect($permissions)
                        ->first();

                if ($menuPermission) {
                    $menu['permission'] =
                        $menuPermission;
                }

                $this->syncMenu($menu);

                $syncedMenus++;
            }
        }

        /*
         * Super admin receives every new permission.
         * Other role permissions are selected on the Roles page.
         */
        $this->syncSuperAdmin();

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        return [
            'permissions' =>
                count(
                    $createdPermissions
                ),

            'menus' =>
                $syncedMenus,
        ];
    }

    private function extractPermissions(
        Route $route
    ): array {
        $permissions = [];

        foreach (
            $route->gatherMiddleware()
            as $middleware
        ) {
            if (!is_string($middleware)) {
                continue;
            }

            if (
                !str_starts_with(
                    $middleware,
                    'permission:'
                )
            ) {
                continue;
            }

            $definition =
                Str::after(
                    $middleware,
                    'permission:'
                );

            /*
             * Remove optional guard:
             *
             * permission:permission.name,web
             */
            $definition =
                explode(
                    ',',
                    $definition,
                    2
                )[0];

            /*
             * Spatie supports:
             *
             * permission:first|second
             */
            foreach (
                explode('|', $definition)
                as $permission
            ) {
                $permission = trim(
                    $permission
                );

                if ($permission !== '') {
                    $permissions[] =
                        $permission;
                }
            }
        }

        return array_values(
            array_unique(
                $permissions
            )
        );
    }

    private function syncPermission(
        string $permissionName
    ): Permission {
        $permission =
            Permission::query()
                ->firstOrCreate([
                    'name' =>
                        $permissionName,

                    'guard_name' =>
                        $this->guardName,
                ]);

        $displayName =
            $this->displayName(
                $permissionName
            );

        $groupName =
            $this->groupName(
                $permissionName
            );

        $updates = [];

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
            $updates['is_active'] =
                true;
        }

        if ($updates !== []) {
            $permission->update(
                $updates
            );
        }

        return $permission;
    }

    private function syncMenu(
        array $menu
    ): void {
        $table =
            (new MenuItem())->getTable();

        $route = trim(
            (string) (
                $menu['route']
                ?? ''
            )
        );

        if ($route === '') {
            return;
        }

        $section = trim(
            (string) (
                $menu['section']
                ?? 'admin'
            )
        );

        $data = $this->filterColumns(
            $table,
            [
                'section' =>
                    $section,

                'title' =>
                    $menu['title']
                    ?? $menu['label'],

                'label' =>
                    $menu['label']
                    ?? $menu['title'],

                'name' =>
                    $menu['label']
                    ?? $menu['title'],

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

        $routeColumn =
            collect([
                'route',
                'href',
                'url',
                'path',
            ])->first(
                static fn (
                    string $column
                ): bool =>
                    Schema::hasColumn(
                        $table,
                        $column
                    )
            );

        if (!$routeColumn) {
            return;
        }

        $query = DB::table($table)
            ->where(
                $routeColumn,
                $route
            );

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

        $existing =
            $query->first();

        if ($existing) {
            DB::table($table)
                ->where(
                    'id',
                    $existing->id
                )
                ->update($data);

            return;
        }

        if (
            Schema::hasColumn(
                $table,
                'created_at'
            )
        ) {
            $data['created_at'] =
                now();
        }

        DB::table($table)
            ->insert($data);
    }

    private function syncSuperAdmin(): void
    {
        $superAdmin =
            Role::query()
                ->firstOrCreate([
                    'name' =>
                        'super_admin',

                    'guard_name' =>
                        $this->guardName,
                ]);

        $permissions =
            Permission::query()
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

    private function displayName(
        string $permission
    ): string {
        return Str::of($permission)
            ->replace(['.', '_', '-'], ' ')
            ->title()
            ->toString();
    }

    private function groupName(
        string $permission
    ): string {
        $segments = explode(
            '.',
            $permission
        );

        /*
         * pricing.transfer_routes.view
         * becomes:
         * Pricing Transfer Routes
         */
        array_pop($segments);

        return Str::of(
            implode(' ', $segments)
        )
            ->replace(['_', '-'], ' ')
            ->title()
            ->toString();
    }

    private function filterColumns(
        string $table,
        array $data
    ): array {
        return collect($data)
            ->filter(
                static fn (
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