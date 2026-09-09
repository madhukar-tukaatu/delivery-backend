<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the origin-branch sort decision on the shipment so the delivery
 * and transfer phases can query it directly.
 *
 *   sort_mode : 'last_mile' | 'transfer' (null until sorted)
 *   sorted_at : timestamp of the sort
 *   sorted_by : staff user who performed the sort
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shipments')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'sort_mode')) {
                $table->string('sort_mode')
                    ->nullable()
                    ->index()
                    ->after('status');
            }

            if (! Schema::hasColumn('shipments', 'sorted_at')) {
                $table->timestamp('sorted_at')
                    ->nullable()
                    ->after('sort_mode');
            }

            if (! Schema::hasColumn('shipments', 'sorted_by')) {
                $table->foreignId('sorted_by')
                    ->nullable()
                    ->after('sorted_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('shipments')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasColumn('shipments', 'sorted_by')) {
                $table->dropForeign(['sorted_by']);
                $table->dropColumn('sorted_by');
            }

            if (Schema::hasColumn('shipments', 'sorted_at')) {
                $table->dropColumn('sorted_at');
            }

            if (Schema::hasColumn('shipments', 'sort_mode')) {
                $table->dropColumn('sort_mode');
            }
        });
    }
};
