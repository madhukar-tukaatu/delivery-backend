<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind application-wide services here.
    }

    public function boot(): void
    {
        // Module routes are loaded from routes/api.php to keep route caching predictable.
    }
}
