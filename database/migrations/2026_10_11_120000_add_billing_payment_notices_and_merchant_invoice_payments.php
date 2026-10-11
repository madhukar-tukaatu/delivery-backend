<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verify-and-mark-paid billing:
 *  - billing_payment_notices: a marketplace (API) or merchant (portal) tells us it paid.
 *    A notice never changes paid/outstanding; the billing branch verifies it (records a
 *    payment) or rejects it. HamroPay notices (awaiting_gateway) are verified by the provider.
 *  - merchant_invoice_payments: partial payments on merchant delivery-charge invoices.
 *  - invoices.outstanding_amount / hamropay_sessions; invoices.status may be partially_paid.
 *  - marketplace_bill_deliveries: push (webhook) delivery status per bill event.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Merchant portal menu: "Invoices" becomes "Billing" (same page, same permission).
        if (Schema::hasTable('menu_items')) {
            DB::table('menu_items')->where('path', '/merchant/invoices')->where('label', 'Invoices')->update(['label' => 'Billing', 'updated_at' => now()]);
        }

        if (! Schema::hasColumn('invoices', 'outstanding_amount')) {
            Schema::table('invoices', function (Blueprint $t) {
                $t->decimal('outstanding_amount', 12, 2)->nullable()->after('paid_amount');
            });
            DB::table('invoices')->where('status', 'paid')->update(['outstanding_amount' => 0]);
            DB::table('invoices')->where('status', '<>', 'paid')->update(['outstanding_amount' => DB::raw('total_amount')]);
        }
        if (! Schema::hasColumn('invoices', 'hamropay_sessions')) {
            Schema::table('invoices', function (Blueprint $t) {
                $t->json('hamropay_sessions')->nullable()->after('outstanding_amount');
            });
        }

        if (! Schema::hasTable('merchant_invoice_payments')) {
            Schema::create('merchant_invoice_payments', function (Blueprint $t) {
                $t->id();
                $t->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $t->unsignedBigInteger('merchant_id')->nullable()->index();
                $t->unsignedBigInteger('branch_id')->nullable()->index();
                $t->decimal('amount', 12, 2);
                $t->string('method', 64)->nullable();
                $t->string('reference', 191)->nullable();
                $t->dateTime('paid_at');
                $t->string('note', 500)->nullable();
                $t->string('source', 40);
                $t->string('recorded_by_type', 32)->nullable();
                $t->unsignedBigInteger('recorded_by')->nullable();
                $t->string('request_id', 191)->nullable()->unique();
                $t->string('batch_reference', 64)->nullable()->index();
                $t->unsignedBigInteger('notice_id')->nullable()->index();
                $t->string('status', 16)->default('recorded')->index();
                $t->dateTime('reversed_at')->nullable();
                $t->unsignedBigInteger('reversed_by')->nullable();
                $t->string('reverse_reason', 500)->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasColumn('marketplace_bill_payments', 'notice_id')) {
            Schema::table('marketplace_bill_payments', function (Blueprint $t) {
                $t->unsignedBigInteger('notice_id')->nullable()->index()->after('request_id');
            });
        }

        if (! Schema::hasTable('billing_payment_notices')) {
            Schema::create('billing_payment_notices', function (Blueprint $t) {
                $t->id();
                $t->string('payer_type', 16);           // marketplace | merchant
                $t->unsignedBigInteger('payer_id');      // marketplaces.id | merchants.id
                $t->string('target_type', 24);           // marketplace_bill | invoice
                $t->unsignedBigInteger('target_id');
                $t->unsignedBigInteger('branch_id')->nullable()->index();
                $t->decimal('amount', 12, 2);
                $t->string('method', 64)->nullable();
                $t->string('reference', 191)->nullable();
                $t->dateTime('paid_at')->nullable();
                $t->string('note', 500)->nullable();
                $t->string('status', 24)->default('pending_verification')->index();
                $t->string('source', 24);                // marketplace_api | merchant_portal | hamropay
                $t->string('batch_reference', 64)->nullable()->index();
                $t->string('request_id', 191)->nullable()->unique();
                $t->string('submitted_by_type', 24)->nullable();
                $t->unsignedBigInteger('submitted_by')->nullable();
                $t->string('gateway_txn_id', 64)->nullable()->index();
                $t->decimal('verified_amount', 12, 2)->nullable();
                $t->unsignedBigInteger('verified_by')->nullable();
                $t->dateTime('verified_at')->nullable();
                $t->unsignedBigInteger('payment_id')->nullable();
                $t->unsignedBigInteger('rejected_by')->nullable();
                $t->dateTime('rejected_at')->nullable();
                $t->string('reject_reason', 500)->nullable();
                $t->timestamps();
                $t->index(['target_type', 'target_id', 'status'], 'bpn_target_status_idx');
                $t->index(['payer_type', 'payer_id', 'status'], 'bpn_payer_status_idx');
            });
        }

        if (! Schema::hasTable('marketplace_bill_deliveries')) {
            Schema::create('marketplace_bill_deliveries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('marketplace_bill_id')->nullable()->index();
                $t->unsignedBigInteger('marketplace_id')->index();
                $t->string('event', 64);
                $t->string('event_id', 64)->unique();
                $t->string('url', 500)->nullable();
                $t->string('status', 16)->default('queued')->index(); // queued | delivered | failed | skipped
                $t->unsignedInteger('attempts')->default(0);
                $t->unsignedSmallInteger('http_status')->nullable();
                $t->string('last_error', 1000)->nullable();
                $t->dateTime('queued_at')->nullable();
                $t->dateTime('last_attempt_at')->nullable();
                $t->dateTime('delivered_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('menu_items')) {
            DB::table('menu_items')->where('path', '/merchant/invoices')->where('label', 'Billing')->update(['label' => 'Invoices', 'updated_at' => now()]);
        }
        Schema::dropIfExists('marketplace_bill_deliveries');
        Schema::dropIfExists('billing_payment_notices');
        Schema::dropIfExists('merchant_invoice_payments');
        if (Schema::hasColumn('marketplace_bill_payments', 'notice_id')) {
            Schema::table('marketplace_bill_payments', fn (Blueprint $t) => $t->dropColumn('notice_id'));
        }
        foreach (['hamropay_sessions', 'outstanding_amount'] as $col) {
            if (Schema::hasColumn('invoices', $col)) {
                Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
    }
};