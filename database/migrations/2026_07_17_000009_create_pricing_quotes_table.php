<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'pricing_quotes',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('checkout_quote_id')
                    ->nullable()
                    ->constrained('checkout_quotes')
                    ->cascadeOnDelete();

                $table->string('quote_number', 80)
                    ->unique();

                $table->foreignId('merchant_id')
                    ->nullable()
                    ->constrained('merchants')
                    ->nullOnDelete();

                /*
                 * Store table may be named stores in your current system.
                 */
                $table->unsignedBigInteger('store_id')
                    ->nullable()
                    ->index('pricing_quotes_store_idx');

                $table->unsignedBigInteger('api_client_id')
                    ->nullable()
                    ->index();

                $table->string('idempotency_key', 150)
                    ->nullable();

                $table->string('external_cart_id', 150)
                    ->nullable();

                $table->string('external_order_id', 150)
                    ->nullable()
                    ->index();

                $table->foreignId('pickup_branch_id')
                    ->constrained('branches')
                    ->restrictOnDelete();

                $table->foreignId('delivery_branch_id')
                    ->constrained('branches')
                    ->restrictOnDelete();

                $table->text('pickup_address');

                $table->decimal('pickup_latitude', 10, 7);
                $table->decimal('pickup_longitude', 10, 7);

                $table->text('delivery_address');

                $table->decimal('delivery_latitude', 10, 7);
                $table->decimal('delivery_longitude', 10, 7);

                $table->decimal('distance_km', 10, 3)
                    ->default(0);

                $table->decimal('parcel_weight', 10, 3);

                $table->decimal('volumetric_weight', 10, 3)
                    ->nullable();

                $table->decimal('chargeable_weight', 10, 3)
                    ->nullable();

                $table->decimal('parcel_value', 14, 2)
                    ->default(0);

                $table->unsignedInteger('packet_count')
                    ->default(1);

                $table->string('parcel_type', 50)
                    ->default('standard');

                $table->string('payment_type', 30)
                    ->default('prepaid');

                $table->decimal('pod_amount', 14, 2)
                    ->default(0);

                $table->string('service_type', 50);

                $table->foreignId('service_type_id')
                    ->nullable()
                    ->constrained('service_types')
                    ->nullOnDelete();

                $table->boolean('is_branch_transfer')
                    ->default(false);

                $table->unsignedInteger('transfer_count')
                    ->default(0);

                $table->decimal('base_price', 14, 2)
                    ->default(0);

                $table->decimal('weight_charge', 14, 2)
                    ->default(0);

                $table->decimal('distance_charge', 14, 2)
                    ->default(0);

                $table->decimal('transfer_charge', 14, 2)
                    ->default(0);

                $table->decimal('handling_charge', 14, 2)
                    ->default(0);

                $table->decimal('service_charge', 14, 2)
                    ->default(0);

                $table->decimal('pickup_charge', 14, 2)
                    ->default(0);

                $table->decimal('pod_charge', 14, 2)
                    ->default(0);

                $table->decimal('subtotal', 14, 2)
                    ->default(0);

                $table->decimal('discount_amount', 14, 2)
                    ->default(0);

                $table->decimal('tax_amount', 14, 2)
                    ->default(0);

                $table->decimal('final_price', 14, 2);

                $table->string('currency', 3)
                    ->default('NPR');

                $table->unsignedInteger('estimated_hours')
                    ->nullable();

                $table->timestamp('sla_due_at')
                    ->nullable();

                $table->timestamp('expires_at')
                    ->nullable()
                    ->index();

                /*
                 * Complete immutable pricing calculation.
                 */
                $table->json('snapshot_json');

                $table->json('request_json')
                    ->nullable();

                /*
                 * pending
                 * accepted
                 * expired
                 * cancelled
                 * rejected
                 */
                $table->string('status', 30)
                    ->default('pending')
                    ->index();

                $table->timestamp('accepted_at')
                    ->nullable();

                $table->timestamp('confirmed_at')
                    ->nullable();

                $table->timestamp('cancelled_at')
                    ->nullable();

                $table->timestamps();

                $table->unique([
                    'merchant_id',
                    'idempotency_key',
                ], 'pricing_quote_merchant_idempotency_unique');

                $table->index([
                    'checkout_quote_id',
                    'store_id',
                ], 'pricing_quote_checkout_store_index');

                $table->index([
                    'merchant_id',
                    'store_id',
                    'status',
                ], 'pricing_quote_merchant_store_status_index');

                $table->index([
                    'pickup_branch_id',
                    'delivery_branch_id',
                ], 'pricing_quote_branch_route_index');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_quotes');
    }
};
