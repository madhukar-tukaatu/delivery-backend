<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('service_types')) {
            Schema::create('service_types', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->decimal('price_multiplier', 10, 2)->default(1);
                $table->decimal('fixed_addon_fee', 10, 2)->default(0);
                $table->unsignedInteger('estimated_min_hours')->nullable();
                $table->unsignedInteger('estimated_max_hours')->nullable();
                $table->time('pickup_cutoff_time')->nullable();
                $table->boolean('same_day_only')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table) {
                if (!Schema::hasColumn('branches', 'latitude')) $table->decimal('latitude', 11, 8)->nullable();
                if (!Schema::hasColumn('branches', 'longitude')) $table->decimal('longitude', 11, 8)->nullable();
                if (!Schema::hasColumn('branches', 'is_active')) $table->boolean('is_active')->default(true);
            });
        }

        if (!Schema::hasTable('branch_pricing_rules')) {
            Schema::create('branch_pricing_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('service_type_id');
                $table->decimal('base_radius_km', 8, 2)->default(5);
                $table->decimal('base_pickup_fee', 10, 2)->default(0);
                $table->decimal('base_delivery_fee', 10, 2)->default(0);
                $table->decimal('pickup_extra_per_km', 10, 2)->default(0);
                $table->decimal('delivery_extra_per_km', 10, 2)->default(0);
                $table->decimal('max_pickup_distance_km', 8, 2)->nullable();
                $table->decimal('max_delivery_distance_km', 8, 2)->nullable();
                $table->decimal('base_weight_kg', 8, 2)->default(1);
                $table->decimal('extra_weight_per_kg', 10, 2)->default(0);
                $table->decimal('pod_fee_fixed', 10, 2)->default(0);
                $table->decimal('pod_fee_percentage', 8, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['branch_id','service_type_id']);
            });
        }

        if (!Schema::hasTable('branch_transfer_lanes')) {
            Schema::create('branch_transfer_lanes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('from_branch_id');
                $table->unsignedBigInteger('to_branch_id');
                $table->unsignedBigInteger('service_type_id');
                $table->decimal('base_transfer_fee', 10, 2)->default(0);
                $table->decimal('per_kg_fee', 10, 2)->default(0);
                $table->unsignedInteger('estimated_hours')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->index(['from_branch_id','to_branch_id','service_type_id']);
            });
        }

        if (Schema::hasTable('merchant_api_keys')) {
            Schema::table('merchant_api_keys', function (Blueprint $table) {
                if (!Schema::hasColumn('merchant_api_keys', 'api_key_hash')) $table->string('api_key_hash')->nullable()->index();
                if (!Schema::hasColumn('merchant_api_keys', 'api_secret_hash')) $table->string('api_secret_hash')->nullable();
                if (!Schema::hasColumn('merchant_api_keys', 'last_used_at')) $table->timestamp('last_used_at')->nullable();
            });
        }

        if (!Schema::hasTable('pricing_quotes')) {
            Schema::create('pricing_quotes', function (Blueprint $table) {
                $table->id();
                $table->string('quote_number')->unique();
                $table->unsignedBigInteger('merchant_id')->nullable();
                $table->unsignedBigInteger('pickup_branch_id')->nullable();
                $table->unsignedBigInteger('delivery_branch_id')->nullable();
                $table->unsignedBigInteger('service_type_id')->nullable();
                $table->string('service_type')->default('standard');
                $table->string('pickup_address')->nullable();
                $table->decimal('pickup_latitude', 11, 8)->nullable();
                $table->decimal('pickup_longitude', 11, 8)->nullable();
                $table->string('delivery_address')->nullable();
                $table->decimal('delivery_latitude', 11, 8)->nullable();
                $table->decimal('delivery_longitude', 11, 8)->nullable();
                $table->decimal('parcel_weight', 10, 2)->default(0);
                $table->decimal('parcel_value', 12, 2)->default(0);
                $table->string('payment_type')->default('prepaid');
                $table->decimal('pod_amount', 12, 2)->default(0);
                $table->decimal('final_price', 12, 2)->default(0);
                $table->timestamp('sla_due_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->json('snapshot_json')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('shipments')) {
            Schema::table('shipments', function (Blueprint $table) {
                if (!Schema::hasColumn('shipments','quote_number')) $table->string('quote_number')->nullable();
                if (!Schema::hasColumn('shipments','pricing_quote_id')) $table->unsignedBigInteger('pricing_quote_id')->nullable();
                if (!Schema::hasColumn('shipments','merchant_order_id')) $table->string('merchant_order_id')->nullable();
                if (!Schema::hasColumn('shipments','delivery_fee')) $table->decimal('delivery_fee', 12, 2)->default(0);
                if (!Schema::hasColumn('shipments','pickup_branch_id')) $table->unsignedBigInteger('pickup_branch_id')->nullable();
                if (!Schema::hasColumn('shipments','delivery_branch_id')) $table->unsignedBigInteger('delivery_branch_id')->nullable();
                if (!Schema::hasColumn('shipments','service_type_id')) $table->unsignedBigInteger('service_type_id')->nullable();
                if (!Schema::hasColumn('shipments','service_type')) $table->string('service_type')->default('standard');
                if (!Schema::hasColumn('shipments','sla_due_at')) $table->timestamp('sla_due_at')->nullable();
                if (!Schema::hasColumn('shipments','confirmed_at')) $table->timestamp('confirmed_at')->nullable();
                if (!Schema::hasColumn('shipments','items_json')) $table->json('items_json')->nullable();
                if (!Schema::hasColumn('shipments','pricing_snapshot_json')) $table->json('pricing_snapshot_json')->nullable();
                if (!Schema::hasColumn('shipments','current_task_id')) $table->unsignedBigInteger('current_task_id')->nullable();
            });
        } else {
            Schema::create('shipments', function (Blueprint $table) {
                $table->id();
                $table->string('tracking_number')->unique();
                $table->string('quote_number')->nullable();
                $table->unsignedBigInteger('pricing_quote_id')->nullable();
                $table->unsignedBigInteger('merchant_id')->nullable();
                $table->string('merchant_order_id')->nullable();
                $table->string('customer_name')->nullable();
                $table->string('customer_phone')->nullable();
                $table->string('customer_email')->nullable();
                $table->string('pickup_address')->nullable();
                $table->decimal('pickup_latitude', 11, 8)->nullable();
                $table->decimal('pickup_longitude', 11, 8)->nullable();
                $table->string('delivery_address')->nullable();
                $table->decimal('delivery_latitude', 11, 8)->nullable();
                $table->decimal('delivery_longitude', 11, 8)->nullable();
                $table->decimal('parcel_weight', 10, 2)->default(0);
                $table->decimal('parcel_value', 12, 2)->default(0);
                $table->string('payment_type')->default('prepaid');
                $table->decimal('pod_amount', 12, 2)->default(0);
                $table->decimal('delivery_fee', 12, 2)->default(0);
                $table->unsignedBigInteger('pickup_branch_id')->nullable();
                $table->unsignedBigInteger('delivery_branch_id')->nullable();
                $table->unsignedBigInteger('service_type_id')->nullable();
                $table->string('service_type')->default('standard');
                $table->string('status')->default('pickup_pending');
                $table->timestamp('sla_due_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->json('items_json')->nullable();
                $table->json('pricing_snapshot_json')->nullable();
                $table->unsignedBigInteger('current_task_id')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('shipment_price_breakdowns')) {
            Schema::create('shipment_price_breakdowns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shipment_id');
                $table->unsignedBigInteger('pricing_quote_id')->nullable();
                $table->decimal('base_pickup_fee', 12, 2)->default(0);
                $table->decimal('base_delivery_fee', 12, 2)->default(0);
                $table->decimal('base_transfer_fee', 12, 2)->default(0);
                $table->decimal('pickup_extra_charge', 12, 2)->default(0);
                $table->decimal('delivery_extra_charge', 12, 2)->default(0);
                $table->decimal('weight_charge', 12, 2)->default(0);
                $table->decimal('pod_fee', 12, 2)->default(0);
                $table->decimal('discount', 12, 2)->default(0);
                $table->decimal('final_price', 12, 2)->default(0);
                $table->json('snapshot_json')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('shipment_tasks')) {
            Schema::create('shipment_tasks', function (Blueprint $table) {
                $table->id();
                $table->string('task_number')->nullable()->unique();
                $table->unsignedBigInteger('shipment_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('assigned_user_id')->nullable();
                $table->unsignedBigInteger('assigned_staff_id')->nullable();
                $table->unsignedBigInteger('assigned_rider_id')->nullable();
                $table->string('type');
                $table->string('status')->default('pending');
                $table->string('priority')->default('normal');
                $table->timestamp('due_at')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('shipment_status_logs')) {
            Schema::create('shipment_status_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shipment_id');
                $table->string('old_status')->nullable();
                $table->string('new_status');
                $table->string('changed_by')->nullable();
                $table->unsignedBigInteger('changed_by_id')->nullable();
                $table->text('note')->nullable();
                $table->json('meta_json')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('staff_notifications')) {
            Schema::create('staff_notifications', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->text('message')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('shipment_id')->nullable();
                $table->unsignedBigInteger('shipment_task_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('type')->default('info');
                $table->boolean('is_read')->default(false);
                $table->timestamp('read_at')->nullable();
                $table->json('data_json')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Safe migration for existing projects. Do not drop existing operational tables.
    }
};
