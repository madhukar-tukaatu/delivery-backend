<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_transfer_routes', function (Blueprint $table) {
            // Ordered list of intermediate branch IDs (transit hubs) between origin and destination.
            // Example: route KTM -> PKR -> Mustang stores [46] (Pokhara) as the transit.
            if (!Schema::hasColumn('branch_transfer_routes', 'transit_branch_ids')) {
                $table->json('transit_branch_ids')->nullable()->after('branch_transfer_lane_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branch_transfer_routes', function (Blueprint $table) {
            if (Schema::hasColumn('branch_transfer_routes', 'transit_branch_ids')) {
                $table->dropColumn('transit_branch_ids');
            }
        });
    }
};
