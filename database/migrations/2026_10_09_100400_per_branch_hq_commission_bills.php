<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HQ commission is now charged to every branch on its own allocation, so a
 * shipment can have one commission bill per branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_commission_bills', function (Blueprint $table) {
            if (! Schema::hasColumn('branch_commission_bills', 'shipment_branch_share_id')) {
                $table->unsignedBigInteger('shipment_branch_share_id')->nullable()->index();
            }
            // Composite unique first so the shipment_id foreign key keeps an index.
            $table->unique(['shipment_id', 'branch_id'], 'bcb_shipment_branch_unique');
        });

        Schema::table('branch_commission_bills', function (Blueprint $table) {
            $table->dropUnique('branch_commission_bills_shipment_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('branch_commission_bills', function (Blueprint $table) {
            $table->unique(['shipment_id'], 'branch_commission_bills_shipment_id_unique');
        });

        Schema::table('branch_commission_bills', function (Blueprint $table) {
            $table->dropUnique('bcb_shipment_branch_unique');
            if (Schema::hasColumn('branch_commission_bills', 'shipment_branch_share_id')) {
                $table->dropColumn('shipment_branch_share_id');
            }
        });
    }
};
