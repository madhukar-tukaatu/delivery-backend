<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('weight_rate_rules')) {
            return;
        }

        Schema::create(
            'weight_rate_rules',
            function (Blueprint $table): void {
                $table->id();

                $table->unsignedBigInteger('service_type_id');

                $table->unsignedBigInteger('merchant_id')
                    ->nullable();

                $table->decimal('base_weight_kg', 10, 3);

                $table->decimal('base_weight_fee', 12, 2);

                $table->decimal(
                    'additional_weight_unit_kg',
                    10,
                    3
                )->default(1);

                $table->decimal(
                    'additional_weight_fee',
                    12,
                    2
                )->default(0);

                $table->decimal(
                    'maximum_weight_kg',
                    10,
                    3
                )->nullable();

                $table->boolean('is_active')
                    ->default(true);

                $table->timestamps();

                $table->index(
                    'service_type_id',
                    'wrr_service_idx'
                );

                $table->index(
                    'merchant_id',
                    'wrr_merchant_idx'
                );

                $table->index(
                    [
                        'service_type_id',
                        'merchant_id',
                        'is_active',
                    ],
                    'wrr_lookup_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'weight_rate_rules'
        );
    }
};