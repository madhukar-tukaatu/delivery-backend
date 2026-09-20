<?php

use Illuminate\Support\Facades\Route;
use Modules\Settlement\Http\Controllers\SettlementController;

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            Route::get('settlements', [SettlementController::class, 'index'])
                ->name('settlements.index');

            Route::get('settlements/pending-cash', [SettlementController::class, 'pendingCash'])
                ->name('settlements.pending-cash');

            Route::get('settlements/preview', [SettlementController::class, 'preview'])
                ->name('settlements.preview');

            Route::get('settlements/{settlement}', [SettlementController::class, 'show'])
                ->name('settlements.show');

            Route::post('settlements', [SettlementController::class, 'store'])
                ->name('settlements.store');

            Route::post('settlements/{settlement}/mark-paid', [SettlementController::class, 'markPaid'])
                ->name('settlements.mark-paid');

            Route::post('settlements/{settlement}/pay-hamropay', [SettlementController::class, 'payHamroPay'])
                ->name('settlements.pay-hamropay');

            Route::post('settlements/{settlement}/confirm-hamropay', [SettlementController::class, 'confirmHamroPay'])
                ->name('settlements.confirm-hamropay');
        });
    });

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            Route::get('settlements', [SettlementController::class, 'index'])
                ->name('settlements.index');

            Route::get('settlements/{settlement}', [SettlementController::class, 'show'])
                ->name('settlements.show');
        });
    });
