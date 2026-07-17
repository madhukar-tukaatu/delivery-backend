<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pricing_quote_items')) {
            return;
        }

        Schema::create(
            'pricing_quote_items',
            function (Blueprint $table): void {
                $table->id();

                $table->unsignedBigInteger(
                    'pricing_quote_id'
                );

                $table->unsignedBigInteger('store_id')
                    ->nullable();

                $table->unsignedBigInteger('product_id')
                    ->nullable();

                $table->string('product_name', 255);

                $table->string('sku', 100)
                    ->nullable();

                $table->unsignedInteger('quantity');

                $table->decimal(
                    'unit_weight',
                    10,
                    3
                );

                $table->decimal(
                    'total_weight',
                    10,
                    3
                );

                $table->decimal(
                    'unit_price',
                    14,
                    2
                );

                $table->decimal(
                    'total_price',
                    14,
                    2
                );

                $table->string('parcel_type', 30)
                    ->default('non_fragile');

                $table->timestamps();

                $table->index(
                    'pricing_quote_id',
                    'pqi_quote_idx'
                );

                $table->index(
                    'store_id',
                    'pqi_store_idx'
                );

                $table->index(
                    'product_id',
                    'pqi_product_idx'
                );

                $table->index(
                    [
                        'pricing_quote_id',
                        'store_id',
                    ],
                    'pqi_quote_store_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'pricing_quote_items'
        );
    }
};