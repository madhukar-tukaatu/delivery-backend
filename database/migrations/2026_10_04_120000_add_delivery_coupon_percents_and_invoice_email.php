<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'delivery_free_by')) {
                $table->string('delivery_free_by', 20)->default('none')->after('delivery_charge_paid_by');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            // merchant_id is already nullable (2026_01_01_000110). payer_type is a string, so "company" needs no enum change.
            if (! Schema::hasColumn('invoices', 'billed_to_email')) {
                $table->string('billed_to_email')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasColumn('shipments', 'delivery_free_by')) {
                $table->dropColumn('delivery_free_by');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'billed_to_email')) {
                $table->dropColumn('billed_to_email');
            }
        });
    }
};
