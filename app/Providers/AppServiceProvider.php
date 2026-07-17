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
        //
    }

    public function boot(): void
    {
        RateLimiter::for(
            'public-merchant',
            function (Request $request): Limit {
                $merchantId = $request->attributes->get(
                    'merchant_id'
                );

                $key = $merchantId
                    ? 'merchant:' . $merchantId
                    : 'ip:' . $request->ip();

                return Limit::perMinute(60)
                    ->by($key)
                    ->response(function (
                        Request $request,
                        array $headers
                    ) {
                        return response()->json([
                            'success' => false,
                            'message' =>
                                'Too many pricing requests. Please try again shortly.',
                        ], 429, $headers);
                    });
            }
        );
    }
}