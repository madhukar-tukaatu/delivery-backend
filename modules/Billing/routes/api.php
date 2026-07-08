<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\InvoiceController;

/*
|--------------------------------------------------------------------------
| Admin Billing Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Invoices (Admin)
            |--------------------------------------------------------------------------
            | Auto generated permissions:
            | invoices.view
            | invoices.create
            */

            Route::get('invoices', [InvoiceController::class, 'index'])
                ->name('invoices.index');

            Route::post('shipments/{shipment}/invoice', [InvoiceController::class, 'shipmentInvoice'])
                ->name('invoices.create');
        });
    });

/*
|--------------------------------------------------------------------------
| Merchant Billing Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Invoices (Merchant)
            |--------------------------------------------------------------------------
            | Permissions:
            | merchant.invoices OR invoices.view
            */

            Route::get('invoices', [InvoiceController::class, 'index'])
                ->name('invoices.index');
        });
    });