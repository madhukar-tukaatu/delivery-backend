<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_transfer_routes', function (Blueprint $table) {
            // Add origin_branch_id as nullable foreign key
            if (!Schema::hasColumn('branch_transfer_routes', 'origin_branch_id')) {
                $table->foreignId('origin_branch_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('coverage_locations', 'id')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('branch_transfer_routes', function (Blueprint $table) {
            if (Schema::hasColumn('branch_transfer_routes', 'origin_branch_id')) {
                $table->dropForeign(['origin_branch_id']);
                $table->dropColumn('origin_branch_id');
            }
        });
    }
};
