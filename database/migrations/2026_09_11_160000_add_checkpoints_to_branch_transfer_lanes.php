<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_transfer_lanes', function (Blueprint $table) {
            // Add checkpoints (road waypoints for this specific lane path)
            if (!Schema::hasColumn('branch_transfer_lanes', 'checkpoints')) {
                $table->json('checkpoints')
                    ->nullable()
                    ->after('estimated_hours')
                    ->comment('Road waypoints (Mugling, Gorkha, etc) for this specific lane path');
            }

            // Add variant name to distinguish different paths between same origin/destination
            if (!Schema::hasColumn('branch_transfer_lanes', 'variant_name')) {
                $table->string('variant_name', 100)
                    ->nullable()
                    ->after('checkpoints')
                    ->comment('e.g., "Via Khaireni", "Via Gorkha", "Via Narayanghat"');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branch_transfer_lanes', function (Blueprint $table) {
            if (Schema::hasColumn('branch_transfer_lanes', 'variant_name')) {
                $table->dropColumn('variant_name');
            }
            if (Schema::hasColumn('branch_transfer_lanes', 'checkpoints')) {
                $table->dropColumn('checkpoints');
            }
        });
    }
};
