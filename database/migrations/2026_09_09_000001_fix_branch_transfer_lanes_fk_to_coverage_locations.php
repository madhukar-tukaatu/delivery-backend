<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs branch_transfer_lanes so its schema matches the intended design across
 * ALL environments.
 *
 * Background:
 *   The original July migration created branch_transfer_lanes with foreign keys to
 *   the `branches` table and an `is_bidirectional` column. Later migrations corrected
 *   the design to reference `coverage_locations` (operational hubs) and dropped
 *   `is_bidirectional`, but every one of those migrations is guarded with
 *   `if (!Schema::hasTable(...))`, so on servers where the table already existed the
 *   corrected schema was never applied. That left live pointing foreign keys at
 *   `branches` while the app inserts coverage_location ids -> FK constraint violation.
 *
 * This migration is idempotent:
 *   - On environments already correct (FK -> coverage_locations, no is_bidirectional)
 *     it does nothing.
 *   - On environments still on the old schema it drops the wrong FKs, drops
 *     is_bidirectional, and re-adds FKs to coverage_locations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_transfer_lanes')) {
            return;
        }

        // Only the MySQL/MariaDB path is implemented via information_schema.
        // Local sqlite is already on the correct schema, so skip it there.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Drop any existing foreign keys on from_branch_id / to_branch_id,
        //    regardless of their current name or referenced table.
        foreach (['from_branch_id', 'to_branch_id'] as $column) {
            $this->dropForeignKeysOnColumn('branch_transfer_lanes', $column);
        }

        // 2. Guard: before re-pointing FKs at coverage_locations, make sure every
        //    existing row references an id that actually exists there. If not, the
        //    FK creation would fail with a cryptic error, so fail loudly instead.
        $orphans = DB::table('branch_transfer_lanes as l')
            ->leftJoin('coverage_locations as cf', 'cf.id', '=', 'l.from_branch_id')
            ->leftJoin('coverage_locations as ct', 'ct.id', '=', 'l.to_branch_id')
            ->where(function ($q) {
                $q->whereNull('cf.id')->orWhereNull('ct.id');
            })
            ->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Cannot re-point branch_transfer_lanes foreign keys to coverage_locations: "
                . "{$orphans} existing row(s) reference from_branch_id/to_branch_id values that "
                . "do not exist in coverage_locations. Clean up those rows first, then re-run migrate."
            );
        }

        // 3. Drop is_bidirectional to match the intended schema (local + model $fillable).
        if (Schema::hasColumn('branch_transfer_lanes', 'is_bidirectional')) {
            Schema::table('branch_transfer_lanes', function ($table) {
                $table->dropColumn('is_bidirectional');
            });
        }

        // 4. Re-add the foreign keys pointing at coverage_locations.
        Schema::table('branch_transfer_lanes', function ($table) {
            $table->foreign('from_branch_id')
                ->references('id')->on('coverage_locations')
                ->cascadeOnDelete();

            $table->foreign('to_branch_id')
                ->references('id')->on('coverage_locations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('branch_transfer_lanes')) {
            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Revert FKs back to branches (best-effort, restores prior state).
        foreach (['from_branch_id', 'to_branch_id'] as $column) {
            $this->dropForeignKeysOnColumn('branch_transfer_lanes', $column);
        }

        if (! Schema::hasColumn('branch_transfer_lanes', 'is_bidirectional')) {
            Schema::table('branch_transfer_lanes', function ($table) {
                $table->boolean('is_bidirectional')->default(false)->after('priority');
            });
        }

        Schema::table('branch_transfer_lanes', function ($table) {
            $table->foreign('from_branch_id')->references('id')->on('branches');
            $table->foreign('to_branch_id')->references('id')->on('branches');
        });
    }

    /**
     * Drop every foreign key constraint currently defined on the given column,
     * looked up dynamically from information_schema (names differ per environment).
     */
    private function dropForeignKeysOnColumn(string $table, string $column): void
    {
        $database = DB::getDatabaseName();

        $constraints = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->pluck('CONSTRAINT_NAME')
            ->unique();

        foreach ($constraints as $name) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
        }
    }
};
