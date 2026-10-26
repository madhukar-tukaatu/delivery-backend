<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR pickup-run containers: branch manager groups pickup requests into
 * an organized run (e.g. PR-KATHM-000001) with sequenced stops (PR 1 Koteshwor,
 * PR 2 Baneshwor, etc.), assigns to a rider, and tracks starting-to-arrive route.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pickup_runs')) {
            Schema::create('pickup_runs', function (Blueprint $table) {
                $table->id();
                $table->string('run_number', 40)->unique();
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('rider_user_id')->nullable()->index();
                $table->string('vehicle_type', 30)->nullable();
                $table->string('vehicle_number', 60)->nullable();
                $table->string('area_label', 191)->nullable();
                // open | assigned | in_progress | completed | closed | cancelled
                $table->string('status', 30)->default('open')->index();

                $table->unsignedInteger('expected_count')->default(0);
                $table->unsignedInteger('picked_up_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);

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

        if (! Schema::hasTable('pickup_run_items')) {
            Schema::create('pickup_run_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('pickup_run_id')->index();
                $table->unsignedBigInteger('pickup_request_id')->index();
                $table->unsignedInteger('sequence')->default(0);
                // queued | in_progress | arrived | picked_up | failed | removed
                $table->string('status', 30)->default('queued')->index();
                $table->string('reason', 500)->nullable();
                $table->timestamp('arrived_at')->nullable();
                $table->timestamp('picked_up_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamp('removed_at')->nullable();
                $table->unsignedBigInteger('added_by')->nullable();
                $table->timestamps();

                $table->index(['pickup_run_id', 'status']);
                $table->index(['pickup_request_id', 'status']);
            });
        }

        if (Schema::hasTable('pickup_requests') && ! Schema::hasColumn('pickup_requests', 'pickup_run_id')) {
            Schema::table('pickup_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('pickup_run_id')->nullable()->after('branch_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pickup_requests') && Schema::hasColumn('pickup_requests', 'pickup_run_id')) {
            Schema::table('pickup_requests', function (Blueprint $table) {
                $table->dropColumn('pickup_run_id');
            });
        }
        Schema::dropIfExists('pickup_run_items');
        Schema::dropIfExists('pickup_runs');
    }
};

