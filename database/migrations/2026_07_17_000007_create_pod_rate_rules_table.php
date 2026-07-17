<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pod_rate_rules')) {
            return;
        }

        Schema::create(
            'pod_rate_rules',
            function (Blueprint $table): void {
                $table->id();

                $table->unsignedBigInteger(
                    'service_type_id'
                )->nullable();

                $table->unsignedBigInteger(
                    'merchant_id'
                )->nullable();

                $table->string(
                    'calculation_type',
                    50
                );

                $table->decimal(
                    'fixed_fee',
                    12,
                    2
                )->nullable();

                $table->decimal(
                    'percentage',
                    8,
                    4
                )->nullable();

                $table->decimal(
                    'minimum_fee',
                    12,
                    2
                )->nullable();

                $table->decimal(
                    'maximum_fee',
                    12,
                    2
                )->nullable();

                $table->boolean('is_active')
                    ->default(true);

                $table->timestamps();

                $table->index([
                    'service_type_id',
                    'merchant_id',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pod_rate_rules');
    }
};