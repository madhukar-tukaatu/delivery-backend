<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_settings', function (Blueprint $table) {
            $table->id();

            $table->decimal(
                'included_weight_kg',
                10,
                3
            )->default(1.500);

            $table->decimal(
                'same_branch_weight_rate',
                10,
                2
            )->default(20);

            $table->decimal(
                'other_branch_weight_rate',
                10,
                2
            )->default(30);

            $table->decimal(
                'included_delivery_distance_km',
                10,
                3
            )->default(5);

            $table->decimal(
                'extra_distance_rate_per_km',
                10,
                2
            )->default(6);

            $table->decimal(
                'fragile_multiplier',
                10,
                4
            )->default(1.05);

            $table->decimal(
                'same_branch_sdd_multiplier',
                10,
                4
            )->default(1.5);

            $table->decimal(
                'other_branch_sdd_multiplier',
                10,
                4
            )->default(2);

            $table->unsignedInteger(
                'minimum_pickup_packets'
            )->default(3);

            $table->decimal(
                'low_packet_pickup_charge',
                10,
                2
            )->default(50);

            $table->time(
                'same_day_cutoff_time'
            )->default('12:00:00');

            $table->boolean('vat_inclusive')
                ->default(true);

            $table->decimal(
                'vat_percentage',
                5,
                2
            )->default(13);

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_settings');
    }
};