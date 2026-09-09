<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converges the branch_transfer_routes schema across environments.
 *
 * The ordered-lanes design requires these columns on branch_transfer_routes:
 *   - branch_transfer_lane_id (nullable anchor lane; first lane of the path)
 *   - transit_branch_ids (json)
 *   - checkpoints (json)
 *   - base_rate, currency, distance_km, estimated_hours
 *
 * On servers built from an older generation of the table (which used
 * origin_branch_id / destination_branch_id / transfer_count / stops and NO
 * branch_transfer_lane_id), the guarded create migration was skipped, so these
 * columns were never added. This migration adds whatever is missing.
 *
 * Idempotent: only adds columns that don't already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_transfer_routes')) {
            return;
        }

        Schema::table('branch_transfer_routes', function (Blueprint $table): void {
            if (! Schema::hasColumn('branch_transfer_routes', 'branch_transfer_lane_id')) {
                // Nullable anchor lane. FK added separately below (guarded).
                $table->unsignedBigInteger('branch_transfer_lane_id')->nullable()->after('name');
            }

            if (! Schema::hasColumn('branch_transfer_routes', 'transit_branch_ids')) {
                $table->json('transit_branch_ids')->nullable()->after('branch_transfer_lane_id');
            }

            if (! Schema::hasColumn('branch_transfer_routes', 'checkpoints')) {
                $table->json('checkpoints')->nullable()->after('transit_branch_ids');
            }

            if (! Schema::hasColumn('branch_transfer_routes', 'base_rate')) {
                $table->decimal('base_rate', 12, 2)->default(0)->after('service_type');
            }

            if (! Schema::hasColumn('branch_transfer_routes', 'currency')) {
                $table->char('currency', 3)->default('NPR')->after('base_rate');
            }

            if (! Schema::hasColumn('branch_transfer_routes', 'distance_km')) {
                $table->decimal('distance_km', 10, 2)->default(0)->after('currency');
            }

            if (! Schema::hasColumn('branch_transfer_routes', 'estimated_hours')) {
                $table->unsignedInteger('estimated_hours')->default(0)->after('distance_km');
            }

            if (! Schema::hasColumn('branch_transfer_routes', 'priority')) {
                $table->unsignedInteger('priority')->default(100)->after('estimated_hours');
            }

            if (! Schema::hasColumn('branch_transfer_routes', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('priority');
            }

            if (! Schema::hasColumn('branch_transfer_routes', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_default');
            }

            if (! Schema::hasColumn('branch_transfer_routes', 'notes')) {
                $table->text('notes')->nullable()->after('is_active');
            }
        });

        // Add the FK for branch_transfer_lane_id if the lanes table exists and the
        // FK isn't already present (MySQL only; guarded to stay idempotent).
        if (DB::getDriverName() === 'mysql'
            && Schema::hasTable('branch_transfer_lanes')
            && Schema::hasColumn('branch_transfer_routes', 'branch_transfer_lane_id')
            && ! $this->foreignKeyExists('branch_transfer_routes', 'branch_transfer_lane_id')) {
            Schema::table('branch_transfer_routes', function (Blueprint $table): void {
                $table->foreign('branch_transfer_lane_id')
                    ->references('id')->on('branch_transfer_lanes')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Non-destructive: leave the added columns in place on rollback.
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
