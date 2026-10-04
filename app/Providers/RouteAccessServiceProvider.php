<?php

namespace App\Providers;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\ServiceProvider;

final class RouteAccessServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Admin menu metadata
        |--------------------------------------------------------------------------
        |
        | Permissions are detected automatically from permission middleware.
        |
        | Only the page/index route needs menu information because an API route
        | cannot reliably tell us its React URL, icon or menu order.
        |
        */

        LaravelRoute::macro(
            'adminMenu',
            function (
                string $label,
                string $frontendRoute,
                string $icon = 'menu',
                int $sortOrder = 999,
                ?string $parent = null,
                string $section = 'admin',
                ?string $permission = null
            ): LaravelRoute {
                /** @var LaravelRoute $this */

                $action = $this->getAction();

                $menu = [
                    'section' => $section,
                    'title' => $label,
                    'label' => $label,
                    'route' => $frontendRoute,
                    'icon' => $icon,
                    'sort_order' => $sortOrder,
                ];

                $parentLabel = trim((string) $parent);

                if ($parentLabel !== '') {
                    $menu['parent'] = $parentLabel;
                }

                $permissionSlug = trim((string) $permission);

                if ($permissionSlug !== '') {
                    $menu['permission'] = $permissionSlug;
                }

                $action['_admin_menu'] = $menu;

                $this->setAction($action);

                return $this;
            }
        );
    }
}