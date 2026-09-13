<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('branch_transfer_lanes')) {
            return;
        }

        if (!Schema::hasColumn('branch_transfer_lanes', 'path_signature')) {
            Schema::table('branch_transfer_lanes', function (Blueprint $table): void {
                $table->string('path_signature', 64)
                    ->nullable()
                    ->after('variant_name');
            });
        }

        if (DB::getDriverName() === 'mysql') {
            // Keep branch foreign keys valid while historical unique indexes are
            // removed in the remainder of this migration.
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

        if (Schema::hasColumn('branch_transfer_lanes', 'transport_mode')) {
            DB::table('branch_transfer_lanes')
                ->whereNull('transport_mode')
                ->update(['transport_mode' => 'road']);
        }

        // Backfill every row before adding the unique index. The full canonical
        // checkpoint object is hashed in order, so metadata and waypoint order
        // both remain part of the lane variant identity.
        $rows = DB::table('branch_transfer_lanes')
            ->select(['id', 'checkpoints'])
            ->get();

        foreach ($rows as $row) {
            $checkpoints = $this->normalizeCheckpoints($row->checkpoints);
            $signature = hash(
                'sha256',
                json_encode(
                    $checkpoints,
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
            );

            DB::table('branch_transfer_lanes')
                ->where('id', $row->id)
                ->update(['path_signature' => $signature]);
        }

        $duplicates = DB::table('branch_transfer_lanes')
            ->select([
                'from_branch_id',
                'to_branch_id',
                'service_type',
                'transport_mode',
                'path_signature',
                DB::raw('COUNT(*) AS duplicate_count'),
            ])
            ->groupBy([
                'from_branch_id',
                'to_branch_id',
                'service_type',
                'transport_mode',
                'path_signature',
            ])
            ->having('duplicate_count', '>', 1)
            ->exists();

        if ($duplicates) {
            throw new RuntimeException(
                'Cannot add transfer lane path uniqueness because duplicate lane paths exist. '
                . 'Resolve duplicate branch/service/transport/checkpoint rows first.'
            );
        }

        foreach ([
            'transfer_lane_direction_service_unique',
            'btl_from_to_service_unique',
            'transfer_lane_variant_unique',
        ] as $index) {
            $this->dropIndexIfExists('branch_transfer_lanes', $index);
        }

        if (!$this->indexExists('branch_transfer_lanes', 'transfer_lane_path_unique')) {
            Schema::table('branch_transfer_lanes', function (Blueprint $table): void {
                $table->unique(
                    [
                        'from_branch_id',
                        'to_branch_id',
                        'service_type',
                        'transport_mode',
                        'path_signature',
                    ],
                    'transfer_lane_path_unique',
                );
            });
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "ALTER TABLE `branch_transfer_lanes` "
                . "MODIFY `path_signature` VARCHAR(64) NOT NULL"
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('branch_transfer_lanes')) {
            return;
        }

        $this->dropIndexIfExists('branch_transfer_lanes', 'transfer_lane_path_unique');

        if (Schema::hasColumn('branch_transfer_lanes', 'path_signature')) {
            Schema::table('branch_transfer_lanes', function (Blueprint $table): void {
                $table->dropColumn('path_signature');
            });
        }
    }

    private function normalizeCheckpoints(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $checkpoint) {
            if (!is_array($checkpoint)) {
                continue;
            }

            $name = trim((string) ($checkpoint['name']
                ?? $checkpoint['label']
                ?? $checkpoint['display_name']
                ?? ''));
            $city = trim((string) ($checkpoint['city'] ?? ''));
            $landmark = trim((string) ($checkpoint['landmark'] ?? ''));
            $latitude = $this->coordinate(
                $checkpoint['latitude'] ?? $checkpoint['lat'] ?? null,
                -90,
                90,
            );
            $longitude = $this->coordinate(
                $checkpoint['longitude']
                    ?? $checkpoint['lng']
                    ?? $checkpoint['lon']
                    ?? null,
                -180,
                180,
            );

            if ($name === '' && $city === '' && $landmark === ''
                && $latitude === null && $longitude === null) {
                continue;
            }

            $normalized[] = [
                'name' => $name !== '' ? $name : null,
                'city' => $city !== '' ? $city : null,
                'landmark' => $landmark !== '' ? $landmark : null,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }

        return $normalized;
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return $coordinate >= $minimum && $coordinate <= $maximum
            ? round($coordinate, 7)
            : null;
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
            // Historical index may not exist on every installation.
        }
    }
};
