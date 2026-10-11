<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DR delivery-run containers (last mile), the delivery side of PR pickups and
 * TR transfers: a branch groups parcels sorted for delivery into one run
 * (DR-KATHM-000001), hands it to a rider and reconciles it on return.
 * Guarded so it is safe to re-run on databases that already have the tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_runs')) {
            Schema::create('delivery_runs', function (Blueprint $table) {
                $table->id();
                $table->string('run_number', 40)->unique();
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('rider_user_id')->nullable()->index();
                $table->string('vehicle_type', 30)->nullable();
                $table->string('vehicle_number', 60)->nullable();
                $table->string('area_label', 191)->nullable();
                // open | assigned | out_for_delivery | completed | closed | cancelled
                $table->string('status', 30)->default('open')->index();

                $table->unsignedInteger('expected_count')->default(0);
                $table->unsignedInteger('delivered_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->unsignedInteger('returned_count')->default(0);
                $table->unsignedInteger('reattempt_count')->default(0);

                $table->decimal('cod_expected', 12, 2)->default(0);
                $table->decimal('cod_collected', 12, 2)->default(0);
                $table->decimal('online_collected', 12, 2)->default(0);
                $table->decimal('cash_received', 12, 2)->nullable();
                $table->decimal('cash_variance', 12, 2)->nullable();
                $table->boolean('cash_deposited')->default(false);
                $table->unsignedBigInteger('pod_deposit_id')->nullable();

                $table->text('notes')->nullable();
                $table->text('close_notes')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->unsignedBigInteger('dispatched_by')->nullable();
                $table->unsignedBigInteger('closed_by')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancel_reason', 500)->nullable();
                $table->timestamps();

                $table->index(['branch_id', 'status']);
            });
        }

        if (! Schema::hasTable('delivery_run_items')) {
            Schema::create('delivery_run_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('delivery_run_id')->index();
                $table->unsignedBigInteger('shipment_id')->index();
                $table->unsignedBigInteger('delivery_id')->nullable()->index();
                $table->unsignedInteger('sequence')->default(0);
                // queued | out_for_delivery | delivered | failed | removed
                $table->string('status', 30)->default('queued')->index();
                // delivered | failed | reattempt | returned | removed (final result after close)
                $table->string('outcome', 30)->nullable();
                $table->unsignedInteger('attempt')->default(1);
                $table->string('reason', 500)->nullable();
                $table->decimal('cod_amount', 12, 2)->default(0);
                $table->decimal('collected_amount', 12, 2)->default(0);
                $table->string('payment_method', 20)->nullable();
                $table->timestamp('arrived_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamp('removed_at')->nullable();
                $table->unsignedBigInteger('added_by')->nullable();
                $table->timestamps();

                $table->index(['delivery_run_id', 'status']);
                $table->index(['shipment_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_run_items');
        Schema::dropIfExists('delivery_runs');
    }
};
