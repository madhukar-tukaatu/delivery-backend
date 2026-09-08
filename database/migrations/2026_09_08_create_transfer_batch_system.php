<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Transfer Batches - Groups shipments for branch-to-branch transfers
        if (!Schema::hasTable('transfer_batches')) {
            Schema::create('transfer_batches', function (Blueprint $table) {
                $table->id();
                $table->string('batch_code', 100)->unique();
                $table->foreignId('from_branch_id')
                    ->constrained('coverage_locations', 'id')
                    ->cascadeOnDelete();
                $table->foreignId('to_branch_id')
                    ->constrained('coverage_locations', 'id')
                    ->cascadeOnDelete();
                // Don't add FK to branch_transfer_routes here - add in a separate migration
                $table->unsignedBigInteger('branch_transfer_route_id')->nullable();
                $table->enum('status', [
                    'pending',
                    'confirmed',
                    'sorted',
                    'dispatched',
                    'in_transit',
                    'delivered',
                    'cancelled'
                ])->default('pending');
                $table->integer('item_count')->default(0);
                $table->datetime('scheduled_at')->nullable();
                $table->datetime('dispatched_at')->nullable();
                $table->datetime('delivered_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['from_branch_id', 'status']);
                $table->index(['to_branch_id', 'status']);
                $table->index('branch_transfer_route_id');
                $table->index('status');
            });
        }

        // Transfer Batch Items - Individual shipments in batch
        if (!Schema::hasTable('transfer_batch_items')) {
            Schema::create('transfer_batch_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transfer_batch_id')
                    ->constrained('transfer_batches', 'id')
                    ->cascadeOnDelete();
                $table->foreignId('shipment_id')
                    ->constrained('shipments', 'id')
                    ->cascadeOnDelete();
                $table->integer('zone_id')->nullable();
                $table->integer('sort_order')->default(0);
                $table->enum('status', [
                    'pending',
                    'sorted',
                    'in_transit',
                    'delivered',
                    'failed'
                ])->default('pending');
                $table->text('delivery_instructions')->nullable();
                $table->timestamps();

                $table->unique(['transfer_batch_id', 'shipment_id']);
                $table->index('transfer_batch_id');
                $table->index('shipment_id');
                $table->index(['transfer_batch_id', 'zone_id']);
            });
        }

        // Transfer Shipment Tracker - Track shipment journey
        if (!Schema::hasTable('transfer_shipment_trackers')) {
            Schema::create('transfer_shipment_trackers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shipment_id')
                    ->constrained('shipments', 'id')
                    ->cascadeOnDelete();
                // Don't add FK to branch_transfer_routes here - add in a separate migration
                $table->unsignedBigInteger('branch_transfer_route_id')->nullable();
                $table->integer('current_leg')->default(1);
                $table->foreignId('current_branch_id')
                    ->constrained('coverage_locations', 'id')
                    ->cascadeOnDelete();
                $table->enum('status', [
                    'not_started',
                    'picked_up',
                    'in_consolidation',
                    'in_transit',
                    'at_hub',
                    'out_for_delivery',
                    'delivered',
                    'failed'
                ])->default('not_started');
                $table->json('leg_history')->nullable();
                $table->datetime('completed_at')->nullable();
                $table->timestamps();

                $table->unique('shipment_id');
                $table->index('branch_transfer_route_id');
                $table->index('current_branch_id');
                $table->index('status');
            });
        }

        // Delivery Zones - Define zones within a branch for last-mile delivery
        if (!Schema::hasTable('delivery_zones')) {
            Schema::create('delivery_zones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')
                    ->constrained('coverage_locations', 'id')
                    ->cascadeOnDelete();
                $table->string('zone_name', 255);
                $table->string('zone_code', 50);
                $table->string('area_name')->nullable();
                $table->text('description')->nullable();
                $table->decimal('latitude', 11, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();
                $table->decimal('coverage_radius_km', 5, 2)->nullable();
                $table->integer('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['branch_id', 'zone_code']);
                $table->index('branch_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('transfer_shipment_trackers');
        Schema::dropIfExists('transfer_batch_items');
        Schema::dropIfExists('transfer_batches');
    }
};
