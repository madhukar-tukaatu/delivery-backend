<?php
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\SetInitialPasswordController;

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

Route::prefix('v1/auth')
    ->group(function (): void {
        Route::post(
            '/set-initial-password',
            SetInitialPasswordController::class
        )->name(
            'auth.set-initial-password'
        );
    });
