<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('shipments', 'order_source')) {
                $table->string('order_source')->nullable()->after('merchant_order_id');
            }

            if (!Schema::hasColumn('shipments', 'service_type')) {
                $table->string('service_type')->default('standard')->after('order_source');
            }

            if (!Schema::hasColumn('shipments', 'self_drop')) {
                $table->boolean('self_drop')->default(false)->after('service_type');
            }

            if (!Schema::hasColumn('shipments', 'special_instructions')) {
                $table->text('special_instructions')->nullable()->after('remarks');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasColumn('shipments', 'special_instructions')) {
                $table->dropColumn('special_instructions');
            }

            if (Schema::hasColumn('shipments', 'self_drop')) {
                $table->dropColumn('self_drop');
            }

            if (Schema::hasColumn('shipments', 'service_type')) {
                $table->dropColumn('service_type');
            }

            if (Schema::hasColumn('shipments', 'order_source')) {
                $table->dropColumn('order_source');
            }
        });
    }
};