<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: the rider bags each DR parcel at the warehouse before
 * starting the run (delivery_run_items.status 'bagged').
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_run_items')) {
            Schema::table('delivery_run_items', function (Blueprint $table) {
                if (! Schema::hasColumn('delivery_run_items', 'bagged_at')) {
                    $table->timestamp('bagged_at')->nullable()->after('arrived_at');
                }
                if (! Schema::hasColumn('delivery_run_items', 'bagged_by')) {
                    $table->unsignedBigInteger('bagged_by')->nullable()->after('bagged_at');
                }
            });
        }
        if (Schema::hasTable('delivery_runs') && ! Schema::hasColumn('delivery_runs', 'started_at')) {
            Schema::table('delivery_runs', function (Blueprint $table) {
                $table->timestamp('started_at')->nullable()->after('dispatched_at');
            });
        }
    }

    public function down(): void
    {
        // Additive columns are left in place on rollback (no data loss).
    }
};
