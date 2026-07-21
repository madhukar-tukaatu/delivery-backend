<?php

use App\Http\Controllers\Auth\SetInitialPasswordController;
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;

Route::prefix('v1/auth')
    ->name('auth.')
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Public Authentication Routes
        |--------------------------------------------------------------------------
        */

        Route::post('login', [AuthController::class, 'login'])
            ->name('login');

        /*
        |--------------------------------------------------------------------------
        | Protected Authentication Routes
        |--------------------------------------------------------------------------
        */

        Route::middleware(['auth:sanctum'])->group(function () {

            Route::get('me', [AuthController::class, 'me'])
                ->name('me');

            Route::post('logout', [AuthController::class, 'logout'])
                ->name('logout');
        });
    });

Route::post(
    '/auth/set-initial-password',
    [
        SetInitialPasswordController::class,
        'store',
    ]
)->middleware('throttle:6,1');
