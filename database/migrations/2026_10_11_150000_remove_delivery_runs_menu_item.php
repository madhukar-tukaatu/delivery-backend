<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DR delivery runs live inside the Deliveries page (/admin/deliveries).
 * Remove the separate "Delivery Runs" sidebar item that an earlier
 * access:sync created from the old route macro.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_items') || ! Schema::hasColumn('menu_items', 'path')) {
            return;
        }

        $ids = DB::table('menu_items')->where('path', '/admin/delivery-runs')->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        // Re-parent any children first so nothing is left orphaned.
        DB::table('menu_items')->whereIn('parent_id', $ids)->update(['parent_id' => null]);
        DB::table('menu_items')->whereIn('id', $ids)->delete();
    }

    public function down(): void
    {
        // Intentionally empty: the page no longer exists.
    }
};
