<?php

use Illuminate\Support\Facades\Route;
use Modules\SupportTicket\Http\Controllers\SupportTicketController;

/*
|--------------------------------------------------------------------------
| Admin Support Ticket Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Support Tickets
            |--------------------------------------------------------------------------
            | Auto permissions:
            | support.view
            | support.manage
            */

            Route::get('support-tickets', [SupportTicketController::class, 'index'])
                ->name('support-tickets.index');

            Route::post('support-tickets', [SupportTicketController::class, 'store'])
                ->name('support-tickets.store');

            Route::get('support-tickets/{ticket}', [SupportTicketController::class, 'show'])
                ->name('support-tickets.show');

            Route::match(['put','patch'], 'support-tickets/{ticket}', [SupportTicketController::class, 'update'])
                ->name('support-tickets.update');

            Route::delete('support-tickets/{ticket}', [SupportTicketController::class, 'destroy'])
                ->name('support-tickets.destroy');
        });
    });


/*
|--------------------------------------------------------------------------
| Merchant Support Ticket Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Merchant Support Tickets
            |--------------------------------------------------------------------------
            | Auto permissions:
            | merchant.support
            | support.view
            */

            Route::get('support-tickets', [SupportTicketController::class, 'index'])
                ->name('support-tickets.index');

            Route::post('support-tickets', [SupportTicketController::class, 'store'])
                ->name('support-tickets.store');
        });
    });