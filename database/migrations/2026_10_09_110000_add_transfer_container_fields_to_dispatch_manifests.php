<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TR transfer containers.
 *
 * A dispatch manifest is the container for one branch -> next hop trip
 * (TR-000001). These columns add the TR number, vehicle / rider details,
 * received / missing / extra counts and cancel audit, plus per-item scan
 * and discrepancy data for the receiving branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_manifests', function (Blueprint $table) {
            if (! Schema::hasColumn('dispatch_manifests', 'transfer_number')) {
                $table->string('transfer_number', 32)->nullable()->unique();
            }
            if (! Schema::hasColumn('dispatch_manifests', 'vehicle_type')) {
                $table->string('vehicle_type', 20)->nullable();
            }
            if (! Schema::hasColumn('dispatch_manifests', 'rider_user_id')) {
                $table->foreignId('rider_user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            foreach (['expected_count', 'received_count', 'missing_count', 'extra_count'] as $col) {
                if (! Schema::hasColumn('dispatch_manifests', $col)) {
                    $table->unsignedInteger($col)->default(0);
                }
            }
            if (! Schema::hasColumn('dispatch_manifests', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable();
            }
            if (! Schema::hasColumn('dispatch_manifests', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable();
            }
            if (! Schema::hasColumn('dispatch_manifests', 'cancel_reason')) {
                $table->string('cancel_reason', 255)->nullable();
            }
        });

        Schema::table('dispatch_manifest_items', function (Blueprint $table) {
            if (! Schema::hasColumn('dispatch_manifest_items', 'received_by')) {
                $table->unsignedBigInteger('received_by')->nullable();
            }
            if (! Schema::hasColumn('dispatch_manifest_items', 'scanned_at')) {
                $table->timestamp('scanned_at')->nullable();
            }
            if (! Schema::hasColumn('dispatch_manifest_items', 'discrepancy')) {
                // null | missing | extra | damaged
                $table->string('discrepancy', 20)->nullable()->index();
            }
            if (! Schema::hasColumn('dispatch_manifest_items', 'discrepancy_note')) {
                $table->string('discrepancy_note', 255)->nullable();
            }
            if (! Schema::hasColumn('dispatch_manifest_items', 'is_extra')) {
                $table->boolean('is_extra')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_manifest_items', function (Blueprint $table) {
            foreach (['received_by', 'scanned_at', 'discrepancy', 'discrepancy_note', 'is_extra'] as $col) {
                if (Schema::hasColumn('dispatch_manifest_items', $col)) {
                    if ($col === 'discrepancy') {
                        $table->dropIndex(['discrepancy']);
                    }
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('dispatch_manifests', function (Blueprint $table) {
            if (Schema::hasColumn('dispatch_manifests', 'rider_user_id')) {
                $table->dropForeign(['rider_user_id']);
            }
            if (Schema::hasColumn('dispatch_manifests', 'transfer_number')) {
                $table->dropUnique(['transfer_number']);
            }
            foreach ([
                'transfer_number', 'vehicle_type', 'rider_user_id', 'expected_count', 'received_count',
                'missing_count', 'extra_count', 'cancelled_at', 'cancelled_by', 'cancel_reason',
            ] as $col) {
                if (Schema::hasColumn('dispatch_manifests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
