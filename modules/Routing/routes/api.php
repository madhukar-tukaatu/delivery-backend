<?php

use Illuminate\Support\Facades\Route;
use Modules\Routing\Http\Controllers\RoutingQuoteController;

/*
|--------------------------------------------------------------------------
| Authenticated Routing (Internal Use)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')
    ->name('api.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Routing Quote
            |--------------------------------------------------------------------------
            | routing.quote
            */

            Route::post('routing/quote', [RoutingQuoteController::class, 'quote'])
                ->name('routing.quote');
        });
    });

/*
|--------------------------------------------------------------------------
| Gateway Routing (External Systems)
|--------------------------------------------------------------------------
*/

Route::prefix('v1/gateway')
    ->name('gateway.')
    ->middleware(['gateway.auth'])
    ->group(function () {

        Route::post('routing/quote', [RoutingQuoteController::class, 'quote'])
            ->name('routing.quote');
    });

/*
|--------------------------------------------------------------------------
| Public Routing (Dev / Limited Use)
|--------------------------------------------------------------------------
*/

Route::prefix('v1/public')
    ->name('public.')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | ⚠️ WARNING: Public Endpoint
        |--------------------------------------------------------------------------
        | Use only for development or protect with rate limiting / API keys
        */

        Route::post('routing/quote', [RoutingQuoteController::class, 'quote'])
            ->name('routing.quote');
    });