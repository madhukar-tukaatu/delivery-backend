<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: parcels added to a DR while the rider is already collecting
 * are flagged so the staff app can highlight them as "New" until bagged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_run_items') && ! Schema::hasColumn('delivery_run_items', 'late_added_at')) {
            Schema::table('delivery_run_items', function (Blueprint $table) {
                $table->timestamp('late_added_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Additive column is left in place on rollback (no data loss).
    }
};
