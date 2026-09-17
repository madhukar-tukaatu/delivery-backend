<?php
use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\ForgotPasswordController;
use Modules\Auth\Http\Controllers\ResetPasswordController;
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

        // Password reset routes
        Route::post('forgot-password', ForgotPasswordController::class)
            ->name('auth.forgot-password');

        Route::post('reset-password', ResetPasswordController::class)
            ->name('auth.reset-password');

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

            // Profile routes
            Route::get('profile', [AuthController::class, 'profile'])
                ->name('profile');

            Route::put('profile', [AuthController::class, 'updateProfile'])
                ->name('profile.update');

            Route::post('profile/password', [AuthController::class, 'changePassword'])
                ->name('profile.password');
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
