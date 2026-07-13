<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('service_types')) {
            Schema::create('service_types', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique(); // standard, express, same_day
                $table->string('name');
                $table->text('description')->nullable();

                $table->decimal('price_multiplier', 8, 2)->default(1);
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
                if (!Schema::hasColumn('branches', 'latitude')) {
                    $table->decimal('latitude', 10, 7)->nullable();
                }

                if (!Schema::hasColumn('branches', 'longitude')) {
                    $table->decimal('longitude', 10, 7)->nullable();
                }

                if (!Schema::hasColumn('branches', 'is_active')) {
                    $table->boolean('is_active')->default(true);
                }
            });
        }

        if (Schema::hasTable('branch_pricing_rules')) {
            Schema::table('branch_pricing_rules', function (Blueprint $table) {
                if (!Schema::hasColumn('branch_pricing_rules', 'service_type_id')) {
                    $table->unsignedBigInteger('service_type_id')->nullable()->after('branch_id')->index();
                }

                if (!Schema::hasColumn('branch_pricing_rules', 'base_radius_km')) {
                    $table->decimal('base_radius_km', 8, 2)->default(5);
                }

                if (!Schema::hasColumn('branch_pricing_rules', 'base_pickup_fee')) {
                    $table->decimal('base_pickup_fee', 10, 2)->default(0);
                }

                if (!Schema::hasColumn('branch_pricing_rules', 'base_delivery_fee')) {
                    $table->decimal('base_delivery_fee', 10, 2)->default(0);
                }

                if (!Schema::hasColumn('branch_pricing_rules', 'pickup_extra_per_km')) {
                    $table->decimal('pickup_extra_per_km', 10, 2)->default(0);
                }

                if (!Schema::hasColumn('branch_pricing_rules', 'delivery_extra_per_km')) {
                    $table->decimal('delivery_extra_per_km', 10, 2)->default(0);
                }

                if (!Schema::hasColumn('branch_pricing_rules', 'max_pickup_distance_km')) {
                    $table->decimal('max_pickup_distance_km', 8, 2)->nullable();
                }

                if (!Schema::hasColumn('branch_pricing_rules', 'max_delivery_distance_km')) {
                    $table->decimal('max_delivery_distance_km', 8, 2)->nullable();
                }

                if (!Schema::hasColumn('branch_pricing_rules', 'base_weight_kg')) {
                    $table->decimal('base_weight_kg', 8, 2)->default(1);
                }

                if (!Schema::hasColumn('branch_pricing_rules', 'extra_weight_per_kg')) {
                    $table->decimal('extra_weight_per_kg', 10, 2)->default(0);
                }

                if (!Schema::hasColumn('branch_pricing_rules', 'cod_fee_fixed')) {
                    $table->decimal('cod_fee_fixed', 10, 2)->default(0);
                }

                if (!Schema::hasColumn('branch_pricing_rules', 'cod_fee_percentage')) {
                    $table->decimal('cod_fee_percentage', 8, 2)->default(0);
                }

                if (!Schema::hasColumn('branch_pricing_rules', 'is_active')) {
                    $table->boolean('is_active')->default(true);
                }
            });
        }

        if (Schema::hasTable('branch_transfer_lanes')) {
            Schema::table('branch_transfer_lanes', function (Blueprint $table) {
                if (!Schema::hasColumn('branch_transfer_lanes', 'service_type_id')) {
                    $table->unsignedBigInteger('service_type_id')->nullable()->after('to_branch_id')->index();
                }
            });
        }

        if (Schema::hasTable('pricing_quotes')) {
            Schema::table('pricing_quotes', function (Blueprint $table) {
                if (!Schema::hasColumn('pricing_quotes', 'service_type_id')) {
                    $table->unsignedBigInteger('service_type_id')->nullable()->index();
                }

                if (!Schema::hasColumn('pricing_quotes', 'sla_due_at')) {
                    $table->timestamp('sla_due_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('shipments')) {
            Schema::table('shipments', function (Blueprint $table) {
                if (!Schema::hasColumn('shipments', 'quote_number')) {
                    $table->string('quote_number')->nullable()->index();
                }

                if (!Schema::hasColumn('shipments', 'merchant_order_id')) {
                    $table->string('merchant_order_id')->nullable()->index();
                }

                if (!Schema::hasColumn('shipments', 'delivery_fee')) {
                    $table->decimal('delivery_fee', 12, 2)->default(0);
                }

                if (!Schema::hasColumn('shipments', 'pickup_branch_id')) {
                    $table->unsignedBigInteger('pickup_branch_id')->nullable()->index();
                }

                if (!Schema::hasColumn('shipments', 'delivery_branch_id')) {
                    $table->unsignedBigInteger('delivery_branch_id')->nullable()->index();
                }

                if (!Schema::hasColumn('shipments', 'service_type_id')) {
                    $table->unsignedBigInteger('service_type_id')->nullable()->index();
                }

                if (!Schema::hasColumn('shipments', 'sla_due_at')) {
                    $table->timestamp('sla_due_at')->nullable();
                }

                if (!Schema::hasColumn('shipments', 'confirmed_at')) {
                    $table->timestamp('confirmed_at')->nullable();
                }

                if (!Schema::hasColumn('shipments', 'pricing_snapshot_json')) {
                    $table->json('pricing_snapshot_json')->nullable();
                }

                if (!Schema::hasColumn('shipments', 'current_task_id')) {
                    $table->unsignedBigInteger('current_task_id')->nullable();
                }
            });
        }

        if (!Schema::hasTable('shipment_price_breakdowns')) {
            Schema::create('shipment_price_breakdowns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shipment_id')->nullable()->index();
                $table->unsignedBigInteger('pricing_quote_id')->nullable()->index();

                $table->decimal('base_pickup_fee', 12, 2)->default(0);
                $table->decimal('base_delivery_fee', 12, 2)->default(0);
                $table->decimal('base_transfer_fee', 12, 2)->default(0);

                $table->decimal('pickup_distance_km', 10, 2)->default(0);
                $table->decimal('pickup_extra_km', 10, 2)->default(0);
                $table->decimal('pickup_extra_charge', 12, 2)->default(0);

                $table->decimal('delivery_distance_km', 10, 2)->default(0);
                $table->decimal('delivery_extra_km', 10, 2)->default(0);
                $table->decimal('delivery_extra_charge', 12, 2)->default(0);

                $table->decimal('weight_charge', 12, 2)->default(0);
                $table->decimal('cod_fee', 12, 2)->default(0);
                $table->decimal('discount', 12, 2)->default(0);
                $table->decimal('final_price', 12, 2)->default(0);

                $table->json('snapshot_json')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('shipment_tasks')) {
            Schema::create('shipment_tasks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shipment_id')->index();

                $table->string('task_type'); 
                // pickup, branch_transfer, delivery

                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('from_branch_id')->nullable()->index();
                $table->unsignedBigInteger('to_branch_id')->nullable()->index();

                $table->unsignedBigInteger('assigned_staff_id')->nullable()->index();
                $table->unsignedBigInteger('assigned_rider_id')->nullable()->index();

                $table->string('status')->default('pending');
                // pending, assigned, accepted, in_progress, completed, failed, cancelled

                $table->string('priority')->default('normal');
                // normal, high, urgent

                $table->string('address')->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();

                $table->timestamp('due_at')->nullable();
                $table->timestamp('assigned_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();

                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('shipment_status_logs')) {
            Schema::create('shipment_status_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shipment_id')->index();
                $table->unsignedBigInteger('task_id')->nullable()->index();

                $table->string('status');
                $table->string('title')->nullable();
                $table->text('description')->nullable();

                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('staff_id')->nullable()->index();
                $table->unsignedBigInteger('rider_id')->nullable()->index();

                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('staff_notifications')) {
            Schema::create('staff_notifications', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('staff_id')->nullable()->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('shipment_id')->nullable()->index();
                $table->unsignedBigInteger('task_id')->nullable()->index();

                $table->string('title');
                $table->text('message')->nullable();
                $table->string('type')->default('shipment');
                $table->boolean('is_read')->default(false);

                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        DB::table('service_types')->updateOrInsert(
            ['code' => 'standard'],
            [
                'name' => 'Standard',
                'description' => 'Normal delivery service',
                'price_multiplier' => 1,
                'fixed_addon_fee' => 0,
                'estimated_min_hours' => 48,
                'estimated_max_hours' => 72,
                'pickup_cutoff_time' => null,
                'same_day_only' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('service_types')->updateOrInsert(
            ['code' => 'express'],
            [
                'name' => 'Express',
                'description' => 'Priority delivery service',
                'price_multiplier' => 1.35,
                'fixed_addon_fee' => 30,
                'estimated_min_hours' => 24,
                'estimated_max_hours' => 48,
                'pickup_cutoff_time' => null,
                'same_day_only' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('service_types')->updateOrInsert(
            ['code' => 'same_day'],
            [
                'name' => 'Same Day',
                'description' => 'Same day delivery service',
                'price_multiplier' => 1.80,
                'fixed_addon_fee' => 80,
                'estimated_min_hours' => 4,
                'estimated_max_hours' => 12,
                'pickup_cutoff_time' => '14:00:00',
                'same_day_only' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_notifications');
        Schema::dropIfExists('shipment_status_logs');
        Schema::dropIfExists('shipment_tasks');
        Schema::dropIfExists('shipment_price_breakdowns');
        Schema::dropIfExists('service_types');
    }
};