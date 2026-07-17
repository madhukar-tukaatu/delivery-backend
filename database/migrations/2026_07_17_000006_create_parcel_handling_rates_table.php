<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('parcel_handling_rates')) {
            return;
        }

        Schema::create(
            'parcel_handling_rates',
            function (Blueprint $table): void {
                $table->id();

                $table->string('handling_type', 30);

                $table->string(
                    'calculation_type',
                    50
                );

                $table->unsignedBigInteger('service_type_id')
                    ->nullable();

                $table->unsignedBigInteger('merchant_id')
                    ->nullable();

                $table->decimal('fixed_fee', 12, 2)
                    ->nullable();

                $table->decimal('percentage', 8, 4)
                    ->nullable();

                $table->decimal('minimum_fee', 12, 2)
                    ->nullable();

                $table->decimal('per_kg_fee', 12, 2)
                    ->nullable();

                $table->boolean('is_active')
                    ->default(true);

                $table->timestamps();

                $table->index(
                    'handling_type',
                    'phr_type_idx'
                );

                $table->index(
                    'service_type_id',
                    'phr_service_idx'
                );

                $table->index(
                    'merchant_id',
                    'phr_merchant_idx'
                );

                $table->index(
                    [
                        'handling_type',
                        'service_type_id',
                        'merchant_id',
                        'is_active',
                    ],
                    'phr_lookup_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'parcel_handling_rates'
        );
    }
};