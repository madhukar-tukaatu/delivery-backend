<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On a fresh database branch_transfer_routes is created later
        // (2026_09_08_create_transfer_system), so only touch it if present.
        if (
            Schema::hasTable('branch_transfer_routes')
            && !Schema::hasColumn('branch_transfer_routes', 'stops')
        ) {
            Schema::table('branch_transfer_routes', function (Blueprint $table): void {
                $column = $table->json('stops')->nullable();

                if (Schema::hasColumn('branch_transfer_routes', 'transit_count')) {
                    $column->after('transit_count');
                }
            });
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        Schema::dropIfExists('branch_transfer_route_lanes');
        Schema::dropIfExists('pricing_quote_transfer_lanes');
        Schema::dropIfExists('branch_transfer_lanes');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        if (Schema::hasColumn('branch_transfer_routes', 'stops')) {
            Schema::table('branch_transfer_routes', function (Blueprint $table): void {
                $table->dropColumn('stops');
            });
        }
    }
};
