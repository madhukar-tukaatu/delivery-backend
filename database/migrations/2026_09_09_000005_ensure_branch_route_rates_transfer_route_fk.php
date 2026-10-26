<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * On a fresh database branch_transfer_routes does not exist yet when
 * 2026_08_16_102746 adds branch_route_rates.branch_transfer_route_id, so the
 * FK is added here once the table exists. Idempotent: no-op where the FK is
 * already present (e.g. existing production databases).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (
            DB::getDriverName() !== 'mysql'
            || !Schema::hasTable('branch_route_rates')
            || !Schema::hasTable('branch_transfer_routes')
            || !Schema::hasColumn('branch_route_rates', 'branch_transfer_route_id')
            || $this->foreignKeyExists('branch_route_rates', 'branch_transfer_route_id')
        ) {
            return;
        }

        // Clear dangling references before adding the constraint.
        DB::table('branch_route_rates')
            ->whereNotNull('branch_transfer_route_id')
            ->whereNotIn('branch_transfer_route_id', DB::table('branch_transfer_routes')->select('id'))
            ->update(['branch_transfer_route_id' => null]);

        Schema::table('branch_route_rates', function (Blueprint $table): void {
            $table->foreign('branch_transfer_route_id')
                ->references('id')
                ->on('branch_transfer_routes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Non-destructive.
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }
};
