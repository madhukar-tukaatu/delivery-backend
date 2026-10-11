<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace free-delivery bills: which marketplace a company invoice belongs
 * to (frozen at creation), which branch bill it was sent on, and who paid it.
 * The payment columns are also used for store (merchant) delivery bills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'marketplace_id')) {
                $table->unsignedBigInteger('marketplace_id')->nullable()->after('branch_id')->index();
            }
            if (! Schema::hasColumn('invoices', 'marketplace_bill_id')) {
                $table->unsignedBigInteger('marketplace_bill_id')->nullable()->after('marketplace_id')->index();
            }
            if (! Schema::hasColumn('invoices', 'sent_at')) {
                $table->timestamp('sent_at')->nullable();
            }
            if (! Schema::hasColumn('invoices', 'sent_by')) {
                $table->unsignedBigInteger('sent_by')->nullable()->after('sent_at');
            }
            if (! Schema::hasColumn('invoices', 'send_count')) {
                $table->unsignedInteger('send_count')->default(0)->after('sent_by');
            }
            if (! Schema::hasColumn('invoices', 'paid_at')) {
                $table->timestamp('paid_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('invoices', 'paid_by_type')) {
                // admin | branch | marketplace | hamropay | merchant
                $table->string('paid_by_type', 16)->nullable()->after('paid_at');
            }
            if (! Schema::hasColumn('invoices', 'paid_by')) {
                // users.id for admin/branch/merchant, marketplaces.id for marketplace
                $table->unsignedBigInteger('paid_by')->nullable()->after('paid_by_type');
            }
            if (! Schema::hasColumn('invoices', 'payment_method')) {
                $table->string('payment_method', 64)->nullable()->after('paid_by');
            }
            if (! Schema::hasColumn('invoices', 'payment_reference')) {
                $table->string('payment_reference', 191)->nullable()->after('payment_method');
            }
            if (! Schema::hasColumn('invoices', 'paid_amount')) {
                $table->decimal('paid_amount', 12, 2)->nullable()->after('payment_reference');
            }
            if (! Schema::hasColumn('invoices', 'reopened_at')) {
                $table->timestamp('reopened_at')->nullable()->after('paid_amount');
            }
            if (! Schema::hasColumn('invoices', 'reopen_reason')) {
                $table->string('reopen_reason', 500)->nullable()->after('reopened_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            foreach (['marketplace_id', 'marketplace_bill_id'] as $col) {
                if (Schema::hasColumn('invoices', $col)) {
                    $table->dropIndex([$col]);
                }
            }
        });
        Schema::table('invoices', function (Blueprint $table) {
            $cols = array_values(array_filter([
                'marketplace_id', 'marketplace_bill_id', 'sent_by', 'send_count', 'paid_at', 'paid_by_type',
                'paid_by', 'payment_method', 'payment_reference', 'paid_amount', 'reopened_at', 'reopen_reason',
            ], fn ($c) => Schema::hasColumn('invoices', $c)));
            if ($cols) {
                $table->dropColumn($cols);
            }
        });
    }
};
