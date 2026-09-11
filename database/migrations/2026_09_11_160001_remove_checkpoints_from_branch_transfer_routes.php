<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Checkpoints are now stored at the LANE level, not the route level.
     * Routes inherit checkpoints from their lanes.
     * This migration removes the now-redundant checkpoints column from routes.
     */
    public function up(): void
    {
        Schema::table('branch_transfer_routes', function (Blueprint $table) {
            if (Schema::hasColumn('branch_transfer_routes', 'checkpoints')) {
                $table->dropColumn('checkpoints');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branch_transfer_routes', function (Blueprint $table) {
            $table->json('checkpoints')->nullable()->after('transit_branch_ids');
        });
    }
};
