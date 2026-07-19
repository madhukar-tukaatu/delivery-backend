<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_number')->unique();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('merchant_order_id')->nullable();
            $table->string('source')->default('manual')->index();
            $table->foreignId('origin_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('origin_sub_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('destination_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('destination_sub_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('current_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('current_sub_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('sender_name')->nullable();
            $table->string('sender_phone')->nullable();
            $table->text('sender_address')->nullable();
            $table->string('sender_city')->nullable();
            $table->string('sender_area')->nullable();
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();

            $table->string('receiver_name');
            $table->string('receiver_phone')->index();
            $table->string('receiver_email')->nullable();
            $table->text('receiver_address');
            $table->string('receiver_city')->nullable()->index();
            $table->string('receiver_area')->nullable()->index();

            $table->decimal('delivery_lat', 10, 7)->nullable();
            $table->decimal('delivery_lng', 10, 7)->nullable();

            $table->string('parcel_type')->default('product');
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('weight', 10, 2)->default(1);
            $table->decimal('declared_value', 12, 2)->default(0);
            $table->boolean('fragile')->default(false);

            $table->string('payment_type')->default('pod')->index();
            $table->decimal('pod_amount', 12, 2)->default(0);

            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->decimal('pickup_fee', 12, 2)->default(0);
            $table->decimal('route_fee', 12, 2)->default(0);
            $table->decimal('last_mile_fee', 12, 2)->default(0);
            $table->decimal('weight_fee', 12, 2)->default(0);
            $table->decimal('pod_charge', 12, 2)->default(0);
            $table->decimal('return_charge', 12, 2)->default(0);

            $table->decimal('route_distance_km', 10, 2)->default(0);
            $table->json('delivery_charge_breakdown')->nullable();

            $table->decimal('total_collectable_amount', 12, 2)->default(0);
            $table->string('delivery_charge_paid_by')->default('customer');

            $table->string('status')->default('booked')->index();
            $table->string('merchant_status')->default('pending')->index();
            $table->string('pod_status')->default('pending')->index();
            $table->string('settlement_status')->default('not_ready')->index();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'merchant_order_id']);
        });

        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('weight', 10, 2)->default(0);
            $table->decimal('value', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('tracking_number')->index();
            $table->string('status')->index();
            $table->string('merchant_status')->nullable()->index();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('sub_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('location_text')->nullable();
            $table->text('description')->nullable();
            $table->string('visibility')->default('public'); // public, merchant, internal
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_events');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
    }
};
