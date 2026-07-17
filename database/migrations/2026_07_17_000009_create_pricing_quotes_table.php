<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pricing_quotes')) {
            return;
        }

        Schema::create(
            'pricing_quotes',
            function (Blueprint $table): void {
                $table->id();

                $table->unsignedBigInteger(
                    'checkout_quote_id'
                )->nullable();

                $table->string(
                    'quote_number',
                    100
                )->unique();

                $table->unsignedBigInteger(
                    'merchant_id'
                )->nullable();

                $table->unsignedBigInteger(
                    'store_id'
                )->nullable();

                $table->unsignedBigInteger(
                    'pickup_branch_id'
                );

                $table->unsignedBigInteger(
                    'delivery_branch_id'
                );

                $table->string(
                    'pickup_address',
                    500
                );

                $table->decimal(
                    'pickup_latitude',
                    10,
                    7
                );

                $table->decimal(
                    'pickup_longitude',
                    10,
                    7
                );

                $table->string(
                    'delivery_address',
                    500
                );

                $table->decimal(
                    'delivery_latitude',
                    10,
                    7
                );

                $table->decimal(
                    'delivery_longitude',
                    10,
                    7
                );

                $table->decimal(
                    'parcel_weight',
                    10,
                    3
                );

                $table->decimal(
                    'parcel_value',
                    14,
                    2
                )->default(0);

                $table->string(
                    'parcel_type',
                    30
                );

                $table->string(
                    'payment_type',
                    30
                );

                $table->decimal(
                    'pod_amount',
                    14,
                    2
                )->default(0);

                $table->string(
                    'service_type',
                    50
                );

                $table->unsignedBigInteger(
                    'service_type_id'
                );

                $table->decimal(
                    'final_price',
                    14,
                    2
                );

                $table->string(
                    'currency',
                    10
                )->default('NPR');

                $table->unsignedInteger(
                    'estimated_hours'
                )->nullable();

                $table->timestamp(
                    'sla_due_at'
                )->nullable();

                $table->timestamp(
                    'expires_at'
                );

                $table->json(
                    'snapshot_json'
                );

                $table->string(
                    'status',
                    30
                )->default('pending');

                $table->timestamp(
                    'used_at'
                )->nullable();

                $table->timestamps();

                $table->index('checkout_quote_id');
                $table->index('store_id');
                $table->index('merchant_id');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_quotes');
    }
};