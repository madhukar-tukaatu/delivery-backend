<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\BranchCommissionController;
use Modules\Billing\Http\Controllers\BranchShareController;
use Modules\Billing\Http\Controllers\InterBranchStatementController;
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
            // Separate path so access:sync does not rename the seeded Invoices menu.
            Route::get('delivery-charges', [InvoiceController::class, 'index'])
                ->name('delivery-charges.index')
                ->adminMenu('Delivery charges', '/admin/delivery-charges', 'invoices', 45, 'Finance');
            Route::post('shipments/{shipment}/invoice', [InvoiceController::class, 'shipmentInvoice'])
                ->name('invoices.create');
            Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])
                ->name('invoices.mark-paid');
            Route::post('invoices/{invoice}/mark-checked', [InvoiceController::class, 'markChecked'])
                ->name('invoices.mark-checked');
            Route::post('invoices/{invoice}/close', [InvoiceController::class, 'close'])
                ->name('invoices.close');
            Route::post('invoices/{invoice}/pay-hamropay', [InvoiceController::class, 'payHamroPay'])
                ->name('invoices.pay-hamropay');
            // Manual email triggers
            Route::post('invoices/{invoice}/send-email', [InvoiceController::class, 'sendEmail'])
                ->name('invoices.send-email');
            Route::post('invoices/send-digest', [InvoiceController::class, 'sendDigest'])
                ->name('invoices.send-digest');
            Route::get('invoices/summary', [InvoiceController::class, 'summary'])
                ->name('invoices.summary');

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
            Route::post('payment-gateways/marketplaces/{marketplaceId}', [PaymentGatewayAccountController::class, 'upsertMarketplace'])
                ->name('payment-gateways.marketplace');

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

            // Transfer delivery-charge split per branch (origin / transit / delivery)
            Route::get('branch-shares', [BranchShareController::class, 'index'])
                ->name('branch-shares.index')
                ->adminMenu('Branch shares', '/admin/branch-shares', 'money', 47, 'Finance');
            Route::get('branch-shares/config', [BranchShareController::class, 'config'])
                ->name('branch-shares.config');
            Route::post('branch-shares/config', [BranchShareController::class, 'saveConfig'])
                ->name('branch-shares.config.update');
            Route::get('shipments/{shipment}/branch-shares', [BranchShareController::class, 'shipment'])
                ->name('branch-shares.shipment');
            Route::post('shipments/{shipment}/branch-shares/recompute', [BranchShareController::class, 'recompute'])
                ->name('branch-shares.recompute');

            // Collecting branch -> other branch statements
            Route::get('inter-branch-settlements', [InterBranchStatementController::class, 'index'])
                ->name('inter-branch-settlements.index')
                ->adminMenu('Inter-branch settlements', '/admin/inter-branch-settlements', 'settlements', 48, 'Finance');
            Route::post('inter-branch-settlements/generate', [InterBranchStatementController::class, 'generate'])
                ->name('inter-branch-settlements.generate');
            Route::get('inter-branch-settlements/{statement}', [InterBranchStatementController::class, 'show'])
                ->whereNumber('statement')
                ->name('inter-branch-settlements.show');
            Route::post('inter-branch-settlements/{statement}/issue', [InterBranchStatementController::class, 'issue'])
                ->name('inter-branch-settlements.issue');
            Route::post('inter-branch-settlements/{statement}/pay-hamropay', [InterBranchStatementController::class, 'payHamroPay'])
                ->name('inter-branch-settlements.pay-hamropay');
            Route::post('inter-branch-settlements/{statement}/mark-paid', [InterBranchStatementController::class, 'markPaid'])
                ->name('inter-branch-settlements.mark-paid');
            Route::post('inter-branch-settlements/{statement}/mark-received', [InterBranchStatementController::class, 'markReceived'])
                ->name('inter-branch-settlements.mark-received');
            Route::post('inter-branch-settlements/{statement}/send-email', [InterBranchStatementController::class, 'sendEmail'])
                ->name('inter-branch-settlements.send-email');
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
            // Manual email trigger for merchant
            Route::post('invoices/{invoice}/send-email', [InvoiceController::class, 'sendEmail'])
                ->name('invoices.send-email');
            Route::get('invoices/summary', [InvoiceController::class, 'summary'])
                ->name('invoices.summary');
        });
    });
