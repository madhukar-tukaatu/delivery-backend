<?php

use Illuminate\Support\Facades\Route;
use Modules\COD\Http\Controllers\CodController;

/*
|--------------------------------------------------------------------------
| Admin COD Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | COD (Admin)
            |--------------------------------------------------------------------------
            | Auto generated permissions:
            | cod.view
            | cod.collect
            | cod.deposit
            */

            Route::get('cod', [CodController::class, 'index'])
                ->name('cod.index');

            Route::post('cod/{cod}/collect', [CodController::class, 'collect'])
                ->name('cod.collect');

            Route::post('cod/deposit', [CodController::class, 'deposit'])
                ->name('cod.deposit');
        });
    });

/*
|--------------------------------------------------------------------------
| Merchant COD Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | COD (Merchant)
            |--------------------------------------------------------------------------
            | Permissions:
            | merchant.cod OR cod.view
            */

            Route::get('cod', [CodController::class, 'index'])
                ->name('cod.index');
        });
    });

/*
|--------------------------------------------------------------------------
| Staff COD Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/staff')
    ->name('staff.')
    ->middleware(['auth:sanctum', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | COD (Staff)
            |--------------------------------------------------------------------------
            | Permissions:
            | staff.cod OR cod.view
            */

            Route::get('cod', [CodController::class, 'index'])
                ->name('cod.index');

            Route::post('cod/deposit', [CodController::class, 'deposit'])
                ->name('cod.deposit');
        });
    });