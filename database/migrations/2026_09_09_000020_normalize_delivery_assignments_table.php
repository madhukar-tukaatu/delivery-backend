<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalize the delivery_assignments table.
 *
 * The table accumulated columns across several migrations and ended up
 * inconsistent with the code, which references rider_id, branch_id,
 * failed_at, failure_reason, remarks, pod_collected_amount, signature_path
 * and delivery_type — none of which existed. It also had a NOT NULL
 * delivery_staff_id that blocked creating an unassigned ("pending")
 * delivery.
 *
 * This migration converges the table to the column set the delivery
 * workflow actually uses, and makes delivery_staff_id nullable so a
 * pending assignment (awaiting a rider) can be created at sort time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_assignments')) {
            return;
        }

        Schema::table('delivery_assignments', function (Blueprint $table) {
            $this->addIfMissing($table, 'rider_id', 'unsignedBigInteger', ['nullable' => true]);
            $this->addIfMissing($table, 'branch_id', 'unsignedBigInteger', ['nullable' => true]);
            $this->addIfMissing($table, 'sub_branch_id', 'unsignedBigInteger', ['nullable' => true]);
            $this->addIfMissing($table, 'delivery_type', 'string', ['default' => 'last_mile']);
            $this->addIfMissing($table, 'attempt_no', 'integer', ['default' => 1]);
            $this->addIfMissing($table, 'assigned_at', 'timestamp', ['nullable' => true]);
            $this->addIfMissing($table, 'accepted_at', 'timestamp', ['nullable' => true]);
            $this->addIfMissing($table, 'out_for_delivery_at', 'timestamp', ['nullable' => true]);
            $this->addIfMissing($table, 'delivered_at', 'timestamp', ['nullable' => true]);
            $this->addIfMissing($table, 'failed_at', 'timestamp', ['nullable' => true]);
            $this->addIfMissing($table, 'failure_reason', 'string', ['nullable' => true]);
            $this->addIfMissing($table, 'remarks', 'text', ['nullable' => true]);
            $this->addIfMissing($table, 'pod_collected_amount', 'decimal', ['total' => 12, 'places' => 2, 'default' => 0]);
            $this->addIfMissing($table, 'signature_path', 'string', ['nullable' => true]);
        });

        // Backfill rider_id from the legacy delivery_staff_id where present.
        if (
            Schema::hasColumn('delivery_assignments', 'rider_id')
            && Schema::hasColumn('delivery_assignments', 'delivery_staff_id')
        ) {
            DB::table('delivery_assignments')
                ->whereNull('rider_id')
                ->whereNotNull('delivery_staff_id')
                ->update([
                    'rider_id' => DB::raw('delivery_staff_id'),
                ]);
        }

        // Make delivery_staff_id nullable so a pending (unassigned) delivery
        // can be created. MySQL only; guard other drivers.
        if (
            Schema::hasColumn('delivery_assignments', 'delivery_staff_id')
            && DB::getDriverName() === 'mysql'
        ) {
            try {
                DB::statement(
                    'ALTER TABLE `delivery_assignments` MODIFY `delivery_staff_id` BIGINT UNSIGNED NULL'
                );
            } catch (\Throwable $e) {
                // If a FK constraint blocks the modify, leave as-is; the
                // service keeps delivery_staff_id in sync with rider_id.
            }
        }

        // Backfill: any shipment already sorted for last-mile delivery but
        // without a live delivery assignment gets a pending one, so it shows
        // up on the delivery board.
        if (Schema::hasTable('shipments')) {
            $sorted = DB::table('shipments')
                ->where('status', 'sorted_for_delivery')
                ->get();

            foreach ($sorted as $shipment) {
                $hasLive = DB::table('delivery_assignments')
                    ->where('shipment_id', $shipment->id)
                    ->whereNotIn('status', ['failed', 'cancelled'])
                    ->exists();

                if ($hasLive) {
                    continue;
                }

                DB::table('delivery_assignments')->insert([
                    'shipment_id' => $shipment->id,
                    'branch_id' => $shipment->destination_branch_id ?? $shipment->current_branch_id ?? null,
                    'sub_branch_id' => $shipment->destination_sub_branch_id ?? $shipment->current_sub_branch_id ?? null,
                    'delivery_type' => 'last_mile',
                    'status' => 'pending',
                    'attempt_no' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: added columns are left in place. Reverting the
        // nullability of delivery_staff_id is intentionally skipped to avoid
        // breaking rows created without a legacy staff id.
    }

    private function addIfMissing(Blueprint $table, string $column, string $type, array $options = []): void
    {
        if (Schema::hasColumn('delivery_assignments', $column)) {
            return;
        }

        $definition = match ($type) {
            'string' => $table->string($column),
            'text' => $table->text($column),
            'integer' => $table->integer($column),
            'unsignedBigInteger' => $table->unsignedBigInteger($column),
            'timestamp' => $table->timestamp($column),
            'decimal' => $table->decimal($column, $options['total'] ?? 12, $options['places'] ?? 2),
            default => $table->string($column),
        };

        if (($options['nullable'] ?? false) === true) {
            $definition->nullable();
        }

        if (array_key_exists('default', $options)) {
            $definition->default($options['default']);
        }

        if (in_array($column, ['rider_id', 'branch_id', 'sub_branch_id', 'delivery_type'], true)) {
            $definition->index();
        }
    }
};
