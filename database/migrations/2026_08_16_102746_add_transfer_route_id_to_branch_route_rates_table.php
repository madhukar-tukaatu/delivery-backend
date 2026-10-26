<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('branch_route_rates')) {
            return;
        }

        if (!Schema::hasColumn('branch_route_rates', 'branch_transfer_route_id')) {
            Schema::table('branch_route_rates', function (Blueprint $table): void {
                $table->unsignedBigInteger('branch_transfer_route_id')
                    ->nullable()
                    ->after('delivery_coverage_location_id');
            });
        }

        // On a fresh database branch_transfer_routes is created later
        // (2026_09_08_create_transfer_system); the FK is then added by
        // 2026_09_09_000005_ensure_branch_route_rates_transfer_route_fk.
        if (
            Schema::hasTable('branch_transfer_routes')
            && !$this->foreignKeyExists('branch_route_rates', 'branch_transfer_route_id')
        ) {
            Schema::table('branch_route_rates', function (Blueprint $table): void {
                $table->foreign('branch_transfer_route_id')
                    ->references('id')
                    ->on('branch_transfer_routes')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('branch_route_rates', function (Blueprint $table): void {
            if ($this->foreignKeyExists('branch_route_rates', 'branch_transfer_route_id')) {
                $table->dropForeign(['branch_transfer_route_id']);
            }
            $table->dropColumn('branch_transfer_route_id');
        });
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }
};
