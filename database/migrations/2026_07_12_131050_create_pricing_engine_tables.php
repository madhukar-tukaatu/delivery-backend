<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        if (!Schema::hasTable('branch_pricing_rules')) {
            Schema::create('branch_pricing_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id');

                $table->decimal('base_radius_km', 8, 2)->default(5);

                $table->decimal('base_pickup_fee', 10, 2)->default(0);
                $table->decimal('base_delivery_fee', 10, 2)->default(0);

                $table->decimal('pickup_extra_per_km', 10, 2)->default(0);
                $table->decimal('delivery_extra_per_km', 10, 2)->default(0);

                $table->decimal('max_pickup_distance_km', 8, 2)->nullable();
                $table->decimal('max_delivery_distance_km', 8, 2)->nullable();

                $table->decimal('base_weight_kg', 8, 2)->default(1);
                $table->decimal('extra_weight_per_kg', 10, 2)->default(0);

                $table->decimal('cod_fee_fixed', 10, 2)->default(0);
                $table->decimal('cod_fee_percentage', 8, 2)->default(0);

                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('branch_id');
            });
        }

        if (!Schema::hasTable('branch_transfer_lanes')) {
            Schema::create('branch_transfer_lanes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('from_branch_id');
                $table->unsignedBigInteger('to_branch_id');

                $table->decimal('base_transfer_fee', 10, 2)->default(0);
                $table->decimal('per_kg_fee', 10, 2)->default(0);

                $table->unsignedInteger('estimated_hours')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['from_branch_id', 'to_branch_id']);
            });
        }

        if (!Schema::hasTable('merchant_api_keys')) {
            Schema::create('merchant_api_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('merchant_id')->nullable();
                $table->string('name')->nullable();
                $table->string('api_key_hash')->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->index('merchant_id');
            });
        }

        if (!Schema::hasTable('pricing_quotes')) {
            Schema::create('pricing_quotes', function (Blueprint $table) {
                $table->id();
                $table->string('quote_number')->unique();

                $table->unsignedBigInteger('merchant_id')->nullable();
                $table->unsignedBigInteger('pickup_branch_id')->nullable();
                $table->unsignedBigInteger('delivery_branch_id')->nullable();

                $table->string('pickup_address')->nullable();
                $table->decimal('pickup_latitude', 10, 7)->nullable();
                $table->decimal('pickup_longitude', 10, 7)->nullable();

                $table->string('delivery_address')->nullable();
                $table->decimal('delivery_latitude', 10, 7)->nullable();
                $table->decimal('delivery_longitude', 10, 7)->nullable();

                $table->decimal('parcel_weight', 10, 2)->default(0);
                $table->decimal('parcel_value', 12, 2)->default(0);

                $table->string('payment_type')->default('prepaid');
                $table->decimal('cod_amount', 12, 2)->default(0);

                $table->string('service_type')->default('standard');
                $table->decimal('final_price', 12, 2)->default(0);

                $table->timestamp('expires_at')->nullable();
                $table->json('snapshot_json')->nullable();
                $table->timestamps();

                $table->index('merchant_id');
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
                    $table->unsignedBigInteger('pickup_branch_id')->nullable();
                }

                if (!Schema::hasColumn('shipments', 'delivery_branch_id')) {
                    $table->unsignedBigInteger('delivery_branch_id')->nullable();
                }

                if (!Schema::hasColumn('shipments', 'pricing_snapshot_json')) {
                    $table->json('pricing_snapshot_json')->nullable();
                }
            });
        }

        if (!Schema::hasTable('shipment_price_breakdowns')) {
            Schema::create('shipment_price_breakdowns', function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('shipment_id')->nullable();
                $table->unsignedBigInteger('pricing_quote_id')->nullable();

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

                $table->index('shipment_id');
                $table->index('pricing_quote_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_price_breakdowns');
        Schema::dropIfExists('pricing_quotes');
        Schema::dropIfExists('merchant_api_keys');
        Schema::dropIfExists('branch_transfer_lanes');
        Schema::dropIfExists('branch_pricing_rules');
    }
};