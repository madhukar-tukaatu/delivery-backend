<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('checkout_quotes')) {
            return;
        }

        Schema::create(
            'checkout_quotes',
            function (Blueprint $table): void {
                $table->id();

                $table->string('quote_number', 100)
                    ->unique();

                $table->unsignedBigInteger('merchant_id')
                    ->nullable();

                $table->string('delivery_address', 500);

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

                $table->string('service_type', 50);

                $table->unsignedBigInteger(
                    'service_type_id'
                );

                $table->string('payment_type', 30);

                $table->decimal(
                    'products_total',
                    14,
                    2
                )->default(0);

                $table->decimal(
                    'pod_total',
                    14,
                    2
                )->default(0);

                $table->decimal(
                    'delivery_total',
                    14,
                    2
                )->default(0);

                $table->decimal(
                    'grand_total',
                    14,
                    2
                )->default(0);

                $table->string('currency', 10)
                    ->default('NPR');

                $table->unsignedInteger('store_count')
                    ->default(1);

                $table->string('status', 30)
                    ->default('pending');

                $table->timestamp('expires_at');

                $table->timestamp('used_at')
                    ->nullable();

                $table->json('snapshot_json')
                    ->nullable();

                $table->timestamps();

                $table->index(
                    'merchant_id',
                    'cq_merchant_idx'
                );

                $table->index(
                    'service_type_id',
                    'cq_service_idx'
                );

                $table->index(
                    'status',
                    'cq_status_idx'
                );

                $table->index(
                    'expires_at',
                    'cq_expiry_idx'
                );

                $table->index(
                    [
                        'merchant_id',
                        'status',
                        'expires_at',
                    ],
                    'cq_lookup_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_quotes');
    }
};