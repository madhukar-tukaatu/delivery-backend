<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the original endpoint/service uniqueness with variant-aware lane
 * uniqueness. The checkpoint path signature is added by the follow-up
 * migration dated 2026_09_13.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('branch_transfer_lanes')) {
            return;
        }

        if (!Schema::hasColumn('branch_transfer_lanes', 'variant_name')) {
            Schema::table('branch_transfer_lanes', function (Blueprint $table): void {
                $table->string('variant_name', 100)
                    ->nullable()
                    ->after('checkpoints');
            });
        }

        // The old composite unique index is also the only supporting index for
        // one or both branch foreign keys on older installations. Add dedicated
        // FK indexes before dropping it, otherwise MySQL rejects the migration
        // with error 1553 (index needed in a foreign key constraint).
        if (DB::getDriverName() === 'mysql') {
            $this->ensureIndex(
                'branch_transfer_lanes',
                ['from_branch_id'],
                'transfer_lane_from_branch_index',
            );
            $this->ensureIndex(
                'branch_transfer_lanes',
                ['to_branch_id'],
                'transfer_lane_to_branch_index',
            );
        }

        foreach ([
            'transfer_lane_direction_service_unique',
            'btl_from_to_service_unique',
        ] as $index) {
            $this->dropIndexIfExists('branch_transfer_lanes', $index);
        }

        if (!$this->indexExists('branch_transfer_lanes', 'transfer_lane_variant_unique')) {
            Schema::table('branch_transfer_lanes', function (Blueprint $table): void {
                $table->unique(
                    ['from_branch_id', 'to_branch_id', 'service_type', 'variant_name'],
                    'transfer_lane_variant_unique',
                );
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('branch_transfer_lanes')) {
            return;
        }

        $this->dropIndexIfExists('branch_transfer_lanes', 'transfer_lane_variant_unique');

        if (!$this->indexExists('branch_transfer_lanes', 'transfer_lane_direction_service_unique')) {
            Schema::table('branch_transfer_lanes', function (Blueprint $table): void {
                $table->unique(
                    ['from_branch_id', 'to_branch_id', 'service_type'],
                    'transfer_lane_direction_service_unique',
                );
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }

    private function ensureIndex(string $table, array $columns, string $index): void
    {
        if ($this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $index): void {
            $blueprint->index($columns, $index);
        });
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (DB::getDriverName() === 'mysql' && !$this->indexExists($table, $index)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->dropIndex($index);
            });
        } catch (Throwable) {
            // Older environments may not have this historical index.
        }
    }
};
