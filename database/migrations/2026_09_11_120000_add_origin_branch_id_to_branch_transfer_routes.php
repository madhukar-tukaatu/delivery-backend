<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_transfer_routes', function (Blueprint $table) {
            // Add origin_branch_id as nullable column if it doesn't exist
            if (!Schema::hasColumn('branch_transfer_routes', 'origin_branch_id')) {
                $table->unsignedBigInteger('origin_branch_id')
                    ->nullable()
                    ->after('id');
            }
        });

        // Add the foreign key constraint separately if using MySQL and FK doesn't exist
        if (DB::getDriverName() === 'mysql'
            && Schema::hasColumn('branch_transfer_routes', 'origin_branch_id')
            && !$this->foreignKeyExists('branch_transfer_routes', 'origin_branch_id')) {
            Schema::table('branch_transfer_routes', function (Blueprint $table) {
                $table->foreign('origin_branch_id')
                    ->references('id')->on('branches')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('branch_transfer_routes', function (Blueprint $table) {
            if (Schema::hasColumn('branch_transfer_routes', 'origin_branch_id')) {
                if (DB::getDriverName() === 'mysql' && $this->foreignKeyExists('branch_transfer_routes', 'origin_branch_id')) {
                    $table->dropForeign(['origin_branch_id']);
                }
                $table->dropColumn('origin_branch_id');
            }
        });
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }
};
