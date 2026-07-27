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
                string $section = 'admin'
            ): LaravelRoute {
                /** @var LaravelRoute $this */

                $action = $this->getAction();

                $action['_admin_menu'] = [
                    'section' => $section,
                    'title' => $label,
                    'label' => $label,
                    'route' => $frontendRoute,
                    'icon' => $icon,
                    'sort_order' => $sortOrder,
                ];

                $this->setAction($action);

                return $this;
            }
        );
    }
}