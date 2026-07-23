<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'branch_route_rates',
            function (Blueprint $table) {
                $table->id();

                $table->foreignId(
                    'pickup_branch_id'
                )
                    ->constrained('branches')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->foreignId(
                    'delivery_branch_id'
                )
                    ->constrained('branches')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->decimal(
                    'base_rate',
                    10,
                    2
                );

                $table->boolean('is_active')
                    ->default(true);

                $table->timestamps();

                $table->unique(
                    [
                        'pickup_branch_id',
                        'delivery_branch_id',
                    ],
                    'branch_route_unique'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'branch_route_rates'
        );
    }
};