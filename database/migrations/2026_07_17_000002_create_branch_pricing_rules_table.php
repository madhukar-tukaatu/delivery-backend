<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branch_pricing_rules')) {
            return;
        }

        Schema::create('branch_pricing_rules', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('service_type_id');
            $table->unsignedBigInteger('merchant_id')->nullable();

            $table->string('charge_type', 30);

            $table->decimal('base_radius_km', 10, 3);
            $table->decimal('base_fee', 12, 2);

            $table->decimal(
                'additional_distance_unit_km',
                10,
                3
            )->default(1);

            $table->decimal(
                'additional_distance_fee',
                12,
                2
            )->default(0);

            $table->decimal(
                'maximum_radius_km',
                10,
                3
            )->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(
                [
                    'branch_id',
                    'service_type_id',
                    'merchant_id',
                    'charge_type',
                ],
                'bpr_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_pricing_rules');
    }
};
