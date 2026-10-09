<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transport cost entered by the dispatching branch for a transfer trip,
 * allocated per manifest item (per shipment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_manifests', function (Blueprint $table) {
            if (! Schema::hasColumn('dispatch_manifests', 'transport_cost')) {
                $table->decimal('transport_cost', 12, 2)->default(0);
            }
            if (! Schema::hasColumn('dispatch_manifests', 'transport_cost_split_mode')) {
                $table->string('transport_cost_split_mode', 10)->default('equal');
            }
            if (! Schema::hasColumn('dispatch_manifests', 'transport_cost_entered_by')) {
                $table->unsignedBigInteger('transport_cost_entered_by')->nullable();
            }
            if (! Schema::hasColumn('dispatch_manifests', 'transport_cost_entered_at')) {
                $table->timestamp('transport_cost_entered_at')->nullable();
            }
            if (! Schema::hasColumn('dispatch_manifests', 'transport_cost_updated_by')) {
                $table->unsignedBigInteger('transport_cost_updated_by')->nullable();
            }
            if (! Schema::hasColumn('dispatch_manifests', 'transport_cost_updated_at')) {
                $table->timestamp('transport_cost_updated_at')->nullable();
            }
            if (! Schema::hasColumn('dispatch_manifests', 'transport_cost_log')) {
                // [{from, to, mode, by, at, reason}]
                $table->json('transport_cost_log')->nullable();
            }
        });

        Schema::table('dispatch_manifest_items', function (Blueprint $table) {
            if (! Schema::hasColumn('dispatch_manifest_items', 'transport_cost')) {
                $table->decimal('transport_cost', 12, 2)->default(0);
            }
            if (! Schema::hasColumn('dispatch_manifest_items', 'received_at')) {
                $table->timestamp('received_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_manifest_items', function (Blueprint $table) {
            foreach (['transport_cost', 'received_at'] as $col) {
                if (Schema::hasColumn('dispatch_manifest_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('dispatch_manifests', function (Blueprint $table) {
            foreach ([
                'transport_cost', 'transport_cost_split_mode', 'transport_cost_entered_by',
                'transport_cost_entered_at', 'transport_cost_updated_by', 'transport_cost_updated_at',
                'transport_cost_log',
            ] as $col) {
                if (Schema::hasColumn('dispatch_manifests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
