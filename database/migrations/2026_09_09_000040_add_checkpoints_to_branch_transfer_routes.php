<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds map-picked road checkpoints to a transfer route.
 *
 * Checkpoints are ROAD WAYPOINTS (e.g. Mugling, Gorkha) that describe the physical
 * road path a route follows. They are NOT branches and NOT lane boundaries — they
 * are purely descriptive points (name/city/landmark + coordinates) used for display
 * and for distinguishing alternative routes that share the same lanes.
 *
 * Shape (JSON array):
 *   [{ "name": "Mugling", "city": "Mugling", "landmark": null,
 *      "latitude": 27.86, "longitude": 84.57 }, ...]
 *
 * Distinct from lane-derived transit branches (real hubs where the parcel changes hands).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_transfer_routes')) {
            return;
        }

        Schema::table('branch_transfer_routes', function (Blueprint $table): void {
            if (! Schema::hasColumn('branch_transfer_routes', 'checkpoints')) {
                // Position after transit_branch_ids when present; otherwise after the
                // anchor lane column. (Migration ordering across envs can vary.)
                $after = Schema::hasColumn('branch_transfer_routes', 'transit_branch_ids')
                    ? 'transit_branch_ids'
                    : 'branch_transfer_lane_id';

                $table->json('checkpoints')->nullable()->after($after);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('branch_transfer_routes')) {
            return;
        }

        Schema::table('branch_transfer_routes', function (Blueprint $table): void {
            if (Schema::hasColumn('branch_transfer_routes', 'checkpoints')) {
                $table->dropColumn('checkpoints');
            }
        });
    }
};
