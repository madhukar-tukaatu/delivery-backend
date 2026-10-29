<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\BranchCommissionController;
use Modules\Billing\Http\Controllers\BranchShareController;
use Modules\Billing\Http\Controllers\InterBranchStatementController;
use Modules\Billing\Http\Controllers\HamroPayMerchantController;
use Modules\Billing\Http\Controllers\InvoiceController;
use Modules\Billing\Http\Controllers\MarketplaceBillController;
use Modules\Billing\Http\Controllers\MarketplaceBillingApiController;
use Modules\Billing\Http\Controllers\MerchantBillingController;
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
            // Store bill payments: notices waiting for branch verification, reverse, verify / reject.
            Route::get('invoices/payment-notices', [InvoiceController::class, 'notices'])
                ->name('invoices.notices.index');
            Route::post('invoices/{invoice}/payments/{payment}/reverse', [InvoiceController::class, 'reversePayment'])
                ->whereNumber('invoice')
                ->whereNumber('payment')
                ->name('invoices.reverse-payment');
            Route::post('invoices/{invoice}/notices/{notice}/verify', [InvoiceController::class, 'verifyNotice'])
                ->whereNumber('invoice')
                ->whereNumber('notice')
                ->name('invoices.notice-verify.mark-paid');
            Route::post('invoices/{invoice}/notices/{notice}/reject', [InvoiceController::class, 'rejectNotice'])
                ->whereNumber('invoice')
                ->whereNumber('notice')
                ->name('invoices.notice-reject.mark-paid');
            Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])
                ->whereNumber('invoice')
                ->name('invoices.show');
            Route::post('invoices/{invoice}/confirm-hamropay', [InvoiceController::class, 'confirmHamroPay'])
                ->name('invoices.confirm-hamropay');

            // Marketplace free-delivery bills: each branch sends (pushes) its own
            // bills to each marketplace. The marketplace sends payment notices; the
            // billing branch verifies them (only that records the payment).
            Route::get('marketplace-bills', [MarketplaceBillController::class, 'index'])
                ->name('marketplace-bills.index')
                ->adminMenu('Marketplace bills', '/admin/marketplace-bills', 'invoices', 44, 'Finance');
            Route::get('marketplace-bills/ready', [MarketplaceBillController::class, 'ready'])
                ->name('marketplace-bills.ready.list');
            Route::post('marketplace-bills', [MarketplaceBillController::class, 'store'])
                ->name('marketplace-bills.store');
            Route::get('marketplace-bills/{bill}', [MarketplaceBillController::class, 'show'])
                ->whereNumber('bill')
                ->name('marketplace-bills.show');
            Route::post('marketplace-bills/{bill}/send', [MarketplaceBillController::class, 'send'])
                ->whereNumber('bill')
                ->name('marketplace-bills.send');
            Route::post('marketplace-bills/{bill}/resend', [MarketplaceBillController::class, 'send'])
                ->whereNumber('bill')
                ->name('marketplace-bills.resend.send');
            Route::post('marketplace-bills/{bill}/mark-paid', [MarketplaceBillController::class, 'markPaid'])
                ->whereNumber('bill')
                ->name('marketplace-bills.mark-paid');
            Route::post('marketplace-bills/{bill}/payments/{payment}/reverse', [MarketplaceBillController::class, 'reversePayment'])
                ->whereNumber('bill')
                ->whereNumber('payment')
                ->name('marketplace-bills.reverse-payment');
            Route::post('marketplace-bills/{bill}/report-not-received', [MarketplaceBillController::class, 'reportNotReceived'])
                ->whereNumber('bill')
                ->name('marketplace-bills.report-not-received');
            Route::post('marketplace-bills/{bill}/cancel', [MarketplaceBillController::class, 'cancel'])
                ->whereNumber('bill')
                ->name('marketplace-bills.cancel-bill');
            Route::post('marketplace-bills/{bill}/confirm-hamropay', [MarketplaceBillController::class, 'confirmHamroPay'])
                ->whereNumber('bill')
                ->name('marketplace-bills.confirm-hamropay');
            Route::post('marketplace-bills/{bill}/push', [MarketplaceBillController::class, 'push'])
                ->whereNumber('bill')
                ->name('marketplace-bills.push.send');
            Route::post('marketplace-bills/{bill}/notices/{notice}/verify', [MarketplaceBillController::class, 'verifyNotice'])
                ->whereNumber('bill')
                ->whereNumber('notice')
                ->name('marketplace-bills.notice-verify.mark-paid');
            Route::post('marketplace-bills/{bill}/notices/{notice}/reject', [MarketplaceBillController::class, 'rejectNotice'])
                ->whereNumber('bill')
                ->whereNumber('notice')
                ->name('marketplace-bills.notice-reject.mark-paid');

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
            Route::get('branch-shares/reports', [BranchShareController::class, 'reports'])
                ->name('branch-shares.reports');
            Route::get('branch-shares/config', [BranchShareController::class, 'config'])
                ->name('branch-shares.config');
            Route::post('branch-shares/config', [BranchShareController::class, 'saveConfig'])
                ->name('branch-shares.config.update');
            Route::post('branch-shares/simulate', [BranchShareController::class, 'simulate'])
                ->name('branch-shares.simulate');
            Route::get('shipments/{shipment}/branch-shares', [BranchShareController::class, 'shipment'])
                ->name('branch-shares.shipment');
            Route::post('shipments/{shipment}/branch-shares/recompute', [BranchShareController::class, 'recompute'])
                ->name('branch-shares.recompute');

            // Collecting branch -> other branch statements
            Route::get('inter-branch-settlements', [InterBranchStatementController::class, 'index'])
                ->name('inter-branch-settlements.index')
                ->adminMenu('Inter-branch settlements', '/admin/inter-branch-settlements', 'settlements', 48, 'Finance');
            Route::get('inter-branch-settlements/position', [InterBranchStatementController::class, 'position'])
                ->name('inter-branch-settlements.summary');
            Route::get('inter-branch-settlements/shipments', [InterBranchStatementController::class, 'shipments'])
                ->name('inter-branch-settlements.shipments.index');
            Route::get('inter-branch-settlements/shipments/{shipment}', [InterBranchStatementController::class, 'shipmentBreakdown'])
                ->whereNumber('shipment')
                ->name('inter-branch-settlements.shipments.show');
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
            Route::post('invoices/{invoice}/confirm-hamropay', [InvoiceController::class, 'confirmHamroPay'])
                ->name('invoices.confirm-hamropay');
            Route::post('invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])
                ->name('invoices.mark-paid');
            // Manual email trigger for merchant
            Route::post('invoices/{invoice}/send-email', [InvoiceController::class, 'sendEmail'])
                ->name('invoices.send-email');
            Route::get('invoices/summary', [InvoiceController::class, 'summary'])
                ->name('invoices.summary');
        });

        // Merchant portal "Billing": what the store owes each branch, payment
        // notices (verified by the branch) and HamroPay (auto-confirmed).
        Route::prefix('billing')
            ->name('billing.')
            ->middleware(['permission:merchant.invoices'])
            ->group(function () {
                Route::get('summary', [MerchantBillingController::class, 'summary'])->name('summary');
                Route::get('invoices', [MerchantBillingController::class, 'invoices'])->name('invoices');
                Route::get('invoices/lookup', [MerchantBillingController::class, 'lookup'])->name('invoices.lookup');
                Route::get('invoices/{invoice}', [MerchantBillingController::class, 'show'])->whereNumber('invoice')->name('invoices.show');
                Route::post('invoices/{invoice}/payment-notice', [MerchantBillingController::class, 'notice'])->whereNumber('invoice')->name('invoices.notice');
                Route::get('notices', [MerchantBillingController::class, 'notices'])->name('notices');
                Route::get('pay-all/preview', [MerchantBillingController::class, 'payAllPreview'])->name('pay-all.preview');
                Route::post('pay-all', [MerchantBillingController::class, 'payAll'])->name('pay-all');
                Route::post('hamropay', [MerchantBillingController::class, 'hamroPay'])->name('hamropay');
                Route::post('hamropay/confirm', [MerchantBillingController::class, 'confirmHamroPay'])->name('hamropay.confirm');
            });
    });

/*
|--------------------------------------------------------------------------
| Marketplace billing API (Tukaatu.com, FCA, any marketplace)
|--------------------------------------------------------------------------
| Signed with the marketplace API key (see MARKETPLACE_BILLING_API.md).
| Key scopes must include "billing" or "*" (keys without scopes may call all APIs).
*/
Route::prefix('v1/marketplace/billing')
    ->name('marketplace.billing.')
    ->middleware(['marketplace.api-key:billing', 'throttle:120,1'])
    ->group(function (): void {
        Route::get('summary', [MarketplaceBillingApiController::class, 'summary'])->name('summary');
        Route::get('bills', [MarketplaceBillingApiController::class, 'index'])->name('bills.index');
        Route::get('bills/{billNumber}', [MarketplaceBillingApiController::class, 'show'])->name('bills.show');
        Route::get('bills/{billNumber}/payable', [MarketplaceBillingApiController::class, 'payable'])->name('bills.payable');
        // Payment notice: pending until the billing branch verifies it (never marks paid by itself).
        Route::post('bills/{billNumber}/payment-notice', [MarketplaceBillingApiController::class, 'paymentNotice'])->name('bills.payment-notice');
        Route::get('bills/{billNumber}/payment-notices', [MarketplaceBillingApiController::class, 'notices'])->name('bills.payment-notices');
        // Legacy alias of payment-notice (202, creates a notice; no longer records a payment).
        Route::post('bills/{billNumber}/pay', [MarketplaceBillingApiController::class, 'pay'])->name('bills.pay');
        Route::post('bills/{billNumber}/hamropay', [MarketplaceBillingApiController::class, 'hamroPay'])->name('bills.hamropay');
        Route::post('bills/{billNumber}/hamropay/confirm', [MarketplaceBillingApiController::class, 'confirmHamroPay'])->name('bills.hamropay.confirm');
    });
