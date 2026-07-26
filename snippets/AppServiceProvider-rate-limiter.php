<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Keep existing registrations.
    }

    public function boot(): void
    {
        // Keep existing boot logic, then add this limiter.
        RateLimiter::for(
            'marketplace-pricing',
            function (Request $request): Limit {
                $marketplaceId = $request->attributes->get(
                    'marketplace_id'
                );

                return Limit::perMinute(
                    max(
                        1,
                        (int) config(
                            'marketplace.rate_limit_per_minute',
                            300
                        )
                    )
                )->by(
                    $marketplaceId
                        ? 'marketplace:' . $marketplaceId
                        : 'marketplace-ip:' . $request->ip()
                );
            }
        );
    }
}
