<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pickup_requests')) {
            Schema::table('pickup_requests', function (Blueprint $table) {
                if (!Schema::hasColumn('pickup_requests', 'assigned_to')) $table->foreignId('assigned_to')->nullable()->after('sub_branch_id');
                if (!Schema::hasColumn('pickup_requests', 'picked_up_by')) $table->foreignId('picked_up_by')->nullable()->after('assigned_to');
                if (!Schema::hasColumn('pickup_requests', 'requested_at')) $table->timestamp('requested_at')->nullable();
                if (!Schema::hasColumn('pickup_requests', 'assigned_at')) $table->timestamp('assigned_at')->nullable();
                if (!Schema::hasColumn('pickup_requests', 'picked_up_at')) $table->timestamp('picked_up_at')->nullable();
                if (!Schema::hasColumn('pickup_requests', 'failed_at')) $table->timestamp('failed_at')->nullable();
                if (!Schema::hasColumn('pickup_requests', 'failed_reason')) $table->string('failed_reason')->nullable();
                if (!Schema::hasColumn('pickup_requests', 'remarks')) $table->text('remarks')->nullable();
            });
        }

        if (Schema::hasTable('delivery_assignments')) {
            if (!Schema::hasColumn('delivery_assignments', 'branch_id')) {
                Schema::table('delivery_assignments', function (Blueprint $table) {
                    $table->foreignId('branch_id')
                        ->nullable()
                        ->after('shipment_id')
                        ->constrained('branches')
                        ->nullOnDelete();
                });
            }

            if (!Schema::hasColumn('delivery_assignments', 'sub_branch_id')) {
                Schema::table('delivery_assignments', function (Blueprint $table) {
                    $table->foreignId('sub_branch_id')
                        ->nullable()
                        ->after('branch_id')
                        ->constrained('branches')
                        ->nullOnDelete();
                });
            }

            if (!Schema::hasColumn('delivery_assignments', 'assigned_at')) {
                Schema::table('delivery_assignments', function (Blueprint $table) {
                    $table->timestamp('assigned_at')->nullable()->after('status');
                });
            }

            if (!Schema::hasColumn('delivery_assignments', 'out_for_delivery_at')) {
                Schema::table('delivery_assignments', function (Blueprint $table) {
                    $table->timestamp('out_for_delivery_at')->nullable()->after('assigned_at');
                });
            }

            if (!Schema::hasColumn('delivery_assignments', 'delivered_at')) {
                Schema::table('delivery_assignments', function (Blueprint $table) {
                    $table->timestamp('delivered_at')->nullable()->after('out_for_delivery_at');
                });
            }

            if (!Schema::hasColumn('delivery_assignments', 'failed_at')) {
                Schema::table('delivery_assignments', function (Blueprint $table) {
                    $table->timestamp('failed_at')->nullable()->after('delivered_at');
                });
            }

            if (!Schema::hasColumn('delivery_assignments', 'failed_reason')) {
                Schema::table('delivery_assignments', function (Blueprint $table) {
                    $table->text('failed_reason')->nullable()->after('failed_at');
                });
            }

            if (!Schema::hasColumn('delivery_assignments', 'remarks')) {
                Schema::table('delivery_assignments', function (Blueprint $table) {
                    $table->text('remarks')->nullable()->after('failed_reason');
                });
            }
        }

        // if (Schema::hasTable('delivery_assignments')) {
        //     Schema::table('delivery_assignments', function (Blueprint $table) {
        //         if (!Schema::hasColumn('delivery_assignments', 'assigned_by')) $table->foreignId('assigned_by')->nullable()->after('rider_id');
        //         if (!Schema::hasColumn('delivery_assignments', 'sub_branch_id')) $table->foreignId('sub_branch_id')->nullable()->after('branch_id');
        //         if (!Schema::hasColumn('delivery_assignments', 'assigned_at')) $table->timestamp('assigned_at')->nullable();
        //         if (!Schema::hasColumn('delivery_assignments', 'out_for_delivery_at')) $table->timestamp('out_for_delivery_at')->nullable();
        //         if (!Schema::hasColumn('delivery_assignments', 'delivered_at')) $table->timestamp('delivered_at')->nullable();
        //         if (!Schema::hasColumn('delivery_assignments', 'failed_at')) $table->timestamp('failed_at')->nullable();
        //         if (!Schema::hasColumn('delivery_assignments', 'failed_reason')) $table->string('failed_reason')->nullable();
        //         if (!Schema::hasColumn('delivery_assignments', 'receiver_name')) $table->string('receiver_name')->nullable();
        //         if (!Schema::hasColumn('delivery_assignments', 'cod_collected_amount')) $table->decimal('cod_collected_amount', 12, 2)->default(0);
        //         if (!Schema::hasColumn('delivery_assignments', 'remarks')) $table->text('remarks')->nullable();
        //     });
        // }

        if (Schema::hasTable('cod_records')) {
            Schema::table('cod_records', function (Blueprint $table) {
                if (!Schema::hasColumn('cod_records', 'collected_by')) $table->foreignId('collected_by')->nullable();
                if (!Schema::hasColumn('cod_records', 'deposited_by')) $table->foreignId('deposited_by')->nullable();
                if (!Schema::hasColumn('cod_records', 'confirmed_by')) $table->foreignId('confirmed_by')->nullable();
                if (!Schema::hasColumn('cod_records', 'collected_amount')) $table->decimal('collected_amount', 12, 2)->default(0);
                if (!Schema::hasColumn('cod_records', 'collected_at')) $table->timestamp('collected_at')->nullable();
                if (!Schema::hasColumn('cod_records', 'deposited_at')) $table->timestamp('deposited_at')->nullable();
                if (!Schema::hasColumn('cod_records', 'confirmed_at')) $table->timestamp('confirmed_at')->nullable();
                if (!Schema::hasColumn('cod_records', 'remarks')) $table->text('remarks')->nullable();
            });
        }

        if (Schema::hasTable('shipment_route_steps')) {
            Schema::table('shipment_route_steps', function (Blueprint $table) {
                if (!Schema::hasColumn('shipment_route_steps', 'departed_at')) $table->timestamp('departed_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Safety patch intentionally does not drop columns.
    }
};
