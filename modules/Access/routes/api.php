<?php

use Illuminate\Support\Facades\Route;
use Modules\Access\Http\Controllers\MenuController;
use Modules\Access\Http\Controllers\RoleController;
use Modules\Access\Http\Controllers\UserController;

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Current User Menus
        |--------------------------------------------------------------------------
        | Do NOT protect this with route.permission.
        | The sidebar needs this route to load menus after login.
        */

        Route::get('me/menus', [MenuController::class, 'my'])
            ->name('me.menus');

        /*
        |--------------------------------------------------------------------------
        | Access-Controlled Admin Routes
        |--------------------------------------------------------------------------
        */

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Users
            |--------------------------------------------------------------------------
            | Auto generated permissions:
            | users.view
            | users.create
            | users.edit
            | users.delete
            | users.status
            */

            Route::apiResource('users', UserController::class)
                ->names([
                    'index' => 'users.index',
                    'store' => 'users.store',
                    'show' => 'users.show',
                    'update' => 'users.update',
                    'destroy' => 'users.destroy',
                ]);

            Route::post('users/{user}/toggle', [UserController::class, 'toggle'])
                ->name('users.status');

            /*
            |--------------------------------------------------------------------------
            | Roles & Permissions
            |--------------------------------------------------------------------------
            | Auto generated permissions:
            | roles.view
            | roles.create
            | roles.edit
            | roles.delete
            | roles.permissions
            */

            Route::get('permissions', [RoleController::class, 'permissions'])
                ->name('roles.permissions');

            Route::apiResource('roles', RoleController::class)
                ->names([
                    'index' => 'roles.index',
                    'store' => 'roles.store',
                    'show' => 'roles.show',
                    'update' => 'roles.update',
                    'destroy' => 'roles.destroy',
                ]);

            /*
            |--------------------------------------------------------------------------
            | Menus
            |--------------------------------------------------------------------------
            | Auto generated permissions:
            | menus.view
            | menus.create
            | menus.edit
            | menus.delete
            */

            Route::apiResource('menus', MenuController::class)
                ->except(['show'])
                ->names([
                    'index' => 'menus.index',
                    'store' => 'menus.store',
                    'update' => 'menus.update',
                    'destroy' => 'menus.destroy',
                ]);
        });
    });