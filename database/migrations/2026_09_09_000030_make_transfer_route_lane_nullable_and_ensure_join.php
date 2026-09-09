<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supports the "route = ordered list of lanes" design.
 *
 * A transfer route is now a PATH composed of one or more lanes chained through
 * transit hubs (stored in branch_transfer_route_lanes with sequence_number).
 * A route therefore no longer needs a single direct origin->destination lane.
 *
 * Changes:
 *   1. Make branch_transfer_routes.branch_transfer_lane_id NULLABLE. It is kept
 *      for backward compatibility (populated with the FIRST lane of the path),
 *      but is no longer required.
 *   2. Ensure the branch_transfer_route_lanes join table exists (it should from
 *      the 2026_09_06 restore migration, but guard for environments that dropped it).
 *
 * Idempotent + MySQL-safe: no-op where already correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Ensure the ordered-lanes join table exists.
        if (! Schema::hasTable('branch_transfer_route_lanes')) {
            Schema::create('branch_transfer_route_lanes', function (Blueprint $table): void {
                $table->id();

                $table->foreignId('branch_transfer_route_id')
                    ->constrained('branch_transfer_routes')
                    ->cascadeOnDelete();

                $table->foreignId('branch_transfer_lane_id')
                    ->constrained('branch_transfer_lanes')
                    ->restrictOnDelete();

                $table->unsignedTinyInteger('sequence_number');
                $table->timestamps();

                $table->unique(
                    ['branch_transfer_route_id', 'sequence_number'],
                    'transfer_route_sequence_unique'
                );

                $table->unique(
                    ['branch_transfer_route_id', 'branch_transfer_lane_id'],
                    'transfer_route_lane_unique'
                );
            });
        }

        // 2. Make branch_transfer_lane_id nullable on branch_transfer_routes.
        if (! Schema::hasTable('branch_transfer_routes')
            || ! Schema::hasColumn('branch_transfer_routes', 'branch_transfer_lane_id')) {
            return;
        }

        // MySQL requires dropping the FK before altering the column, then re-adding it.
        if (DB::getDriverName() === 'mysql') {
            $this->dropForeignKeysOnColumn('branch_transfer_routes', 'branch_transfer_lane_id');

            // Alter to nullable. Uses raw SQL to avoid needing doctrine/dbal.
            DB::statement(
                'ALTER TABLE `branch_transfer_routes` '
                . 'MODIFY `branch_transfer_lane_id` BIGINT UNSIGNED NULL'
            );

            // Re-add the FK (still valid; nullable FKs are allowed).
            Schema::table('branch_transfer_routes', function (Blueprint $table): void {
                $table->foreign('branch_transfer_lane_id')
                    ->references('id')->on('branch_transfer_lanes')
                    ->cascadeOnDelete();
            });
        }
        // On sqlite (local) the column is effectively flexible; nothing required.
    }

    public function down(): void
    {
        // Re-tightening to NOT NULL is unsafe if multi-lane routes exist (their
        // anchor may be null), so we leave the column nullable on rollback.
    }

    /**
     * Drop every foreign key on the given column (names differ per environment).
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
