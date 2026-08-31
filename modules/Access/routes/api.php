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
        |
        | This route MUST NOT use route.permission.
        | Otherwise the user cannot load the sidebar to discover what
        | they are allowed to access.
        |
        */

        Route::get('me/menus', [MenuController::class, 'my'])
            ->name('me.menus');

        /*
        |--------------------------------------------------------------------------
        | Access Management
        |--------------------------------------------------------------------------
        */

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Users
            |--------------------------------------------------------------------------
            */

            Route::apiResource('users', UserController::class)
                ->names([
                    'index' => 'users.index',
                    'store' => 'users.store',
                    'show' => 'users.show',
                    'update' => 'users.update',
                    'destroy' => 'users.destroy',
                ]);

            Route::post(
                'users/{user}/toggle',
                [UserController::class, 'toggle']
            )->name('users.status');

            /*
            |--------------------------------------------------------------------------
            | Roles
            |--------------------------------------------------------------------------
            */

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
            | Permission List
            |--------------------------------------------------------------------------
            |
            | Used by RoleForm -> PermissionSelector
            |
            */

            Route::get(
                'permissions',
                [RoleController::class, 'permissions']
            )->name('roles.permissions');

            /*
            |--------------------------------------------------------------------------
            | Menus
            |--------------------------------------------------------------------------
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