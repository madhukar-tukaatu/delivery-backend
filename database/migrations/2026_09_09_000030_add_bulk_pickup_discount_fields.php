<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store-configurable bulk pickup discount.
 *
 * merchants:
 *   bulk_pickup_discount_threshold  min packets (shipments) in one pickup that
 *                                   trigger the discount (e.g. 4 = "more than 3").
 *   bulk_pickup_discount_amount     flat amount taken off that pickup's delivery
 *                                   charge (e.g. 50). Applied once per pickup.
 *
 * pickup_requests:
 *   delivery_discount               the discount actually applied to this pickup,
 *                                   locked in at pickup completion.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchants')) {
            Schema::table('merchants', function (Blueprint $table) {
                if (! Schema::hasColumn('merchants', 'bulk_pickup_discount_threshold')) {
                    $table->unsignedInteger('bulk_pickup_discount_threshold')->nullable();
                }
                if (! Schema::hasColumn('merchants', 'bulk_pickup_discount_amount')) {
                    $table->decimal('bulk_pickup_discount_amount', 12, 2)->nullable();
                }
            });
        }

        if (Schema::hasTable('pickup_requests')) {
            Schema::table('pickup_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('pickup_requests', 'delivery_discount')) {
                    $table->decimal('delivery_discount', 12, 2)->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('merchants')) {
            Schema::table('merchants', function (Blueprint $table) {
                foreach (['bulk_pickup_discount_threshold', 'bulk_pickup_discount_amount'] as $col) {
                    if (Schema::hasColumn('merchants', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('pickup_requests')) {
            Schema::table('pickup_requests', function (Blueprint $table) {
                if (Schema::hasColumn('pickup_requests', 'delivery_discount')) {
                    $table->dropColumn('delivery_discount');
                }
            });
        }
    }
};
