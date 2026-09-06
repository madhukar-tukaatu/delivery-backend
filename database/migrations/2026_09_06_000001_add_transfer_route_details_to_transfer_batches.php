<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shipment_transfer_batches')) {
            Schema::table('shipment_transfer_batches', function (Blueprint $table) {
                // Add route reference and details columns
                if (!Schema::hasColumn('shipment_transfer_batches', 'transfer_route_id')) {
                    $table->unsignedBigInteger('transfer_route_id')->nullable()->after('status');
                }
                
                if (!Schema::hasColumn('shipment_transfer_batches', 'route_code')) {
                    $table->string('route_code')->nullable()->after('transfer_route_id');
                }
                
                if (!Schema::hasColumn('shipment_transfer_batches', 'route_name')) {
                    $table->string('route_name')->nullable()->after('route_code');
                }
                
                if (!Schema::hasColumn('shipment_transfer_batches', 'transfer_count')) {
                    $table->integer('transfer_count')->default(0)->after('route_name');
                }
                
                if (!Schema::hasColumn('shipment_transfer_batches', 'transit_count')) {
                    $table->integer('transit_count')->default(0)->after('transfer_count');
                }
                
                if (!Schema::hasColumn('shipment_transfer_batches', 'transit_branch_ids')) {
                    $table->json('transit_branch_ids')->nullable()->after('transit_count');
                }
                
                if (!Schema::hasColumn('shipment_transfer_batches', 'path')) {
                    $table->json('path')->nullable()->after('transit_branch_ids');
                }
                
                if (!Schema::hasColumn('shipment_transfer_batches', 'path_text')) {
                    $table->text('path_text')->nullable()->after('path');
                }
                
                if (!Schema::hasColumn('shipment_transfer_batches', 'total_distance_km')) {
                    $table->decimal('total_distance_km', 8, 2)->default(0)->after('path_text');
                }
                
                if (!Schema::hasColumn('shipment_transfer_batches', 'total_estimated_hours')) {
                    $table->integer('total_estimated_hours')->default(0)->after('total_distance_km');
                }
                
                if (!Schema::hasColumn('shipment_transfer_batches', 'dispatched_by')) {
                    $table->unsignedBigInteger('dispatched_by')->nullable()->after('received_at');
                }
                
                if (!Schema::hasColumn('shipment_transfer_batches', 'received_by')) {
                    $table->unsignedBigInteger('received_by')->nullable()->after('dispatched_by');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('shipment_transfer_batches')) {
            Schema::table('shipment_transfer_batches', function (Blueprint $table) {
                $columns = [
                    'transfer_route_id',
                    'route_code',
                    'route_name',
                    'transfer_count',
                    'transit_count',
                    'transit_branch_ids',
                    'path',
                    'path_text',
                    'total_distance_km',
                    'total_estimated_hours',
                    'dispatched_by',
                    'received_by',
                ];
                
                foreach ($columns as $column) {
                    if (Schema::hasColumn('shipment_transfer_batches', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
