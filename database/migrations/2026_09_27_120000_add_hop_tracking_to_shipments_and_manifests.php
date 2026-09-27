<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hop-by-hop transfer tracking columns.
 *
 * Shipments: transfer_route_id, next_hop_branch_id, transfer_leg_index, path_text
 * Dispatch manifests: route metadata used by TransferController::createManifestForRoute
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shipments')) {
            Schema::table('shipments', function (Blueprint $table) {
                if (!Schema::hasColumn('shipments', 'transfer_route_id')) {
                    $table->unsignedBigInteger('transfer_route_id')->nullable()->after('destination_sub_branch_id')->index();
                }
                if (!Schema::hasColumn('shipments', 'next_hop_branch_id')) {
                    $table->unsignedBigInteger('next_hop_branch_id')->nullable()->after('current_sub_branch_id')->index();
                }
                if (!Schema::hasColumn('shipments', 'transfer_leg_index')) {
                    $table->unsignedSmallInteger('transfer_leg_index')->nullable()->after('next_hop_branch_id');
                }
                if (!Schema::hasColumn('shipments', 'path_text')) {
                    $table->string('path_text', 500)->nullable()->after('transfer_leg_index');
                }
            });
        }

        if (Schema::hasTable('dispatch_manifests')) {
            Schema::table('dispatch_manifests', function (Blueprint $table) {
                if (!Schema::hasColumn('dispatch_manifests', 'driver_phone')) {
                    $table->string('driver_phone', 30)->nullable()->after('driver_name');
                }
                if (!Schema::hasColumn('dispatch_manifests', 'notes')) {
                    $table->text('notes')->nullable()->after('seal_number');
                }
                if (!Schema::hasColumn('dispatch_manifests', 'route_id')) {
                    $table->unsignedBigInteger('route_id')->nullable()->index()->after('status');
                }
                if (!Schema::hasColumn('dispatch_manifests', 'route_code')) {
                    $table->string('route_code', 64)->nullable()->after('route_id');
                }
                if (!Schema::hasColumn('dispatch_manifests', 'is_multi_hop')) {
                    $table->boolean('is_multi_hop')->default(false)->after('route_code');
                }
                if (!Schema::hasColumn('dispatch_manifests', 'transit_branch_ids')) {
                    $table->json('transit_branch_ids')->nullable()->after('is_multi_hop');
                }
                if (!Schema::hasColumn('dispatch_manifests', 'final_destination_branch_id')) {
                    $table->unsignedBigInteger('final_destination_branch_id')->nullable()->index()->after('transit_branch_ids');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shipments')) {
            Schema::table('shipments', function (Blueprint $table) {
                foreach (['path_text', 'transfer_leg_index', 'next_hop_branch_id', 'transfer_route_id'] as $col) {
                    if (Schema::hasColumn('shipments', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('dispatch_manifests')) {
            Schema::table('dispatch_manifests', function (Blueprint $table) {
                foreach ([
                    'final_destination_branch_id',
                    'transit_branch_ids',
                    'is_multi_hop',
                    'route_code',
                    'route_id',
                    'notes',
                    'driver_phone',
                ] as $col) {
                    if (Schema::hasColumn('dispatch_manifests', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};