<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            // Add transfer-specific tracking columns if they don't exist
            if (!Schema::hasColumn('shipments', 'dispatched_at')) {
                $table->timestamp('dispatched_at')->nullable()->after('cancelled_at');
            }

            if (!Schema::hasColumn('shipments', 'received_at_destination_at')) {
                $table->timestamp('received_at_destination_at')->nullable()->after('dispatched_at');
            }

            if (!Schema::hasColumn('shipments', 'sorted_for_delivery_at')) {
                $table->timestamp('sorted_for_delivery_at')->nullable()->after('received_at_destination_at');
            }

            // Add transfer status column for current shipment state in transfer workflow
            if (!Schema::hasColumn('shipments', 'transfer_status')) {
                $table->string('transfer_status')->nullable()->default(null)->after('settlement_status')->index();
                // Values: null (not in transfer), dispatched, in_transit, received_at_destination_branch, sorted_for_delivery
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasColumn('shipments', 'dispatched_at')) {
                $table->dropColumn('dispatched_at');
            }

            if (Schema::hasColumn('shipments', 'received_at_destination_at')) {
                $table->dropColumn('received_at_destination_at');
            }

            if (Schema::hasColumn('shipments', 'sorted_for_delivery_at')) {
                $table->dropColumn('sorted_for_delivery_at');
            }

            if (Schema::hasColumn('shipments', 'transfer_status')) {
                $table->dropColumn('transfer_status');
            }
        });
    }
};
