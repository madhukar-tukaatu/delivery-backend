<?php

declare(strict_types=1);

namespace Modules\Auth\Providers;

use Illuminate\Support\ServiceProvider;

final class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Register views
        $this->loadViewsFrom(
            __DIR__ . '/../Resources/views',
            'auth'
        );
    }
}
