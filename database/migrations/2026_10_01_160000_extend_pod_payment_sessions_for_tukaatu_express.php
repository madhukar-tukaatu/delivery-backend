<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Doorstep POD via Tukaatu: session stays pending until Tukaatu callbacks
 * fill QR (ready) then paid/failed. merchant_txn_id comes from Tukaatu, not
 * Express HamroPay createSession.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pod_payment_sessions')) {
            return;
        }

        Schema::table('pod_payment_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('pod_payment_sessions', 'delivery_assignment_id')) {
                $table->unsignedBigInteger('delivery_assignment_id')->nullable()->after('shipment_id')->index();
            }
            if (! Schema::hasColumn('pod_payment_sessions', 'merchant_txn_id')) {
                $table->string('merchant_txn_id', 80)->nullable()->after('payment_session_id')->index();
            }
            if (! Schema::hasColumn('pod_payment_sessions', 'transaction_id')) {
                $table->string('transaction_id', 120)->nullable()->after('provider_reference')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pod_payment_sessions')) {
            return;
        }

        Schema::table('pod_payment_sessions', function (Blueprint $table) {
            foreach (['delivery_assignment_id', 'merchant_txn_id', 'transaction_id'] as $col) {
                if (Schema::hasColumn('pod_payment_sessions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
