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
            'checkout_quotes',
            function (Blueprint $table): void {
                $table->id();

                $table->string('quote_number', 80)
                    ->unique();

                $table->foreignId('merchant_id')
                    ->nullable()
                    ->constrained('merchants')
                    ->nullOnDelete();

                /*
                 * Optional external API client reference.
                 * Kept unsigned instead of constrained so this migration
                 * does not depend on merchant_api_clients migration order.
                 */
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

                $table->unsignedInteger('store_count')
                    ->default(0);

                $table->decimal('products_total', 14, 2)
                    ->default(0);

                $table->decimal('delivery_total', 14, 2)
                    ->default(0);

                $table->decimal('pod_total', 14, 2)
                    ->default(0);

                $table->decimal('discount_total', 14, 2)
                    ->default(0);

                $table->decimal('tax_total', 14, 2)
                    ->default(0);

                $table->decimal('grand_total', 14, 2)
                    ->default(0);

                $table->string('currency', 3)
                    ->default('NPR');

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

                $table->json('request_snapshot_json')
                    ->nullable();

                $table->json('pricing_snapshot_json')
                    ->nullable();

                $table->timestamp('expires_at')
                    ->nullable()
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
                ], 'checkout_quote_merchant_idempotency_unique');

                $table->index([
                    'merchant_id',
                    'status',
                    'expires_at',
                ], 'checkout_quote_lookup_index');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('checkout_quotes');
    }
};