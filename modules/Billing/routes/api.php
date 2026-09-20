<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\BranchCommissionController;
use Modules\Billing\Http\Controllers\HamroPayMerchantController;
use Modules\Billing\Http\Controllers\InvoiceController;
use Modules\Billing\Http\Controllers\PaymentGatewayAccountController;

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            Route::get('invoices', [InvoiceController::class, 'index'])
                ->name('invoices.index');
            Route::post('shipments/{shipment}/invoice', [InvoiceController::class, 'shipmentInvoice'])
                ->name('invoices.create');
            Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])
                ->name('invoices.mark-paid');
            Route::post('invoices/{invoice}/pay-hamropay', [InvoiceController::class, 'payHamroPay'])
                ->name('invoices.pay-hamropay');

            // Merchant HamroPay KYB
            Route::get('merchants/{merchant}/hamropay', [HamroPayMerchantController::class, 'status'])
                ->name('merchants.hamropay.status');
            Route::post('merchants/{merchant}/hamropay/send-otp', [HamroPayMerchantController::class, 'sendOtp'])
                ->name('merchants.hamropay.send-otp');
            Route::post('merchants/{merchant}/hamropay/validate-otp', [HamroPayMerchantController::class, 'validateOtp'])
                ->name('merchants.hamropay.validate-otp');
            Route::post('merchants/{merchant}/hamropay/register', [HamroPayMerchantController::class, 'register'])
                ->name('merchants.hamropay.register');
            Route::post('merchants/{merchant}/hamropay/update-kyc', [HamroPayMerchantController::class, 'updateKyc'])
                ->name('merchants.hamropay.update-kyc');

            // Payment gateway accounts (company + branch)
            Route::get('payment-gateways/catalog', [PaymentGatewayAccountController::class, 'catalog'])
                ->name('payment-gateways.catalog');
            Route::get('payment-gateways/accounts', [PaymentGatewayAccountController::class, 'index'])
                ->name('payment-gateways.accounts');
            Route::post('payment-gateways/company', [PaymentGatewayAccountController::class, 'upsertCompany'])
                ->name('payment-gateways.company');
            Route::post('payment-gateways/branches/{branchId}', [PaymentGatewayAccountController::class, 'upsertBranch'])
                ->name('payment-gateways.branch');

            // HQ commission billing (superadmin receivables from branches)
            Route::get('hq-commissions/settings', [BranchCommissionController::class, 'settings'])
                ->name('hq-commissions.settings');
            Route::post('hq-commissions/settings', [BranchCommissionController::class, 'updateSettings'])
                ->name('hq-commissions.settings.update');
            Route::get('hq-commissions/summary', [BranchCommissionController::class, 'summary'])
                ->name('hq-commissions.summary');
            Route::get('hq-commissions/bills', [BranchCommissionController::class, 'bills'])
                ->name('hq-commissions.bills');
            Route::get('hq-commissions/settlements', [BranchCommissionController::class, 'settlements'])
                ->name('hq-commissions.settlements');
            Route::post('hq-commissions/settlements', [BranchCommissionController::class, 'createSettlement'])
                ->name('hq-commissions.settlements.store');
            Route::post('hq-commissions/settlements/{settlement}/pay-hamropay', [BranchCommissionController::class, 'payHamroPay'])
                ->name('hq-commissions.settlements.pay-hamropay');
            Route::post('hq-commissions/settlements/{settlement}/mark-paid', [BranchCommissionController::class, 'markPaid'])
                ->name('hq-commissions.settlements.mark-paid');
        });
    });

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant'])
    ->group(function () {
        Route::middleware(['route.permission'])->group(function () {
            Route::get('invoices', [InvoiceController::class, 'index'])
                ->name('invoices.index');
            Route::post('invoices/{invoice}/pay-hamropay', [InvoiceController::class, 'payHamroPay'])
                ->name('invoices.pay-hamropay');
            Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])
                ->name('invoices.mark-paid');
        });
    });
