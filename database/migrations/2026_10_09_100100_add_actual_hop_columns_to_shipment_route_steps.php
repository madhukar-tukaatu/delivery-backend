<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shipment_route_steps rows with dispatch_manifest_id set are ACTUAL hops
 * (written on dispatch, received_at set on receive). Rows without it are the
 * legacy planned route from ShipmentRoutingService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_route_steps', function (Blueprint $table) {
            if (! Schema::hasColumn('shipment_route_steps', 'dispatch_manifest_id')) {
                $table->foreignId('dispatch_manifest_id')->nullable()
                    ->constrained('dispatch_manifests')->nullOnDelete();
            }
            if (! Schema::hasColumn('shipment_route_steps', 'transport_cost')) {
                $table->decimal('transport_cost', 12, 2)->default(0);
            }
            if (! Schema::hasColumn('shipment_route_steps', 'dispatched_at')) {
                $table->timestamp('dispatched_at')->nullable();
            }
        });

        Schema::table('shipment_route_steps', function (Blueprint $table) {
            $table->unique(['shipment_id', 'dispatch_manifest_id'], 'srs_shipment_manifest_unique');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_route_steps', function (Blueprint $table) {
            $table->dropUnique('srs_shipment_manifest_unique');
        });

        Schema::table('shipment_route_steps', function (Blueprint $table) {
            if (Schema::hasColumn('shipment_route_steps', 'dispatch_manifest_id')) {
                $table->dropConstrainedForeignId('dispatch_manifest_id');
            }
            foreach (['transport_cost', 'dispatched_at'] as $col) {
                if (Schema::hasColumn('shipment_route_steps', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
