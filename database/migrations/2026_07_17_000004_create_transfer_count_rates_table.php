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
            'transfer_count_rates',
            function (Blueprint $table): void {
                $table->id();

                $table->unsignedInteger('minimum_transfer_count')
                    ->default(1);

                $table->unsignedInteger('maximum_transfer_count')
                    ->nullable();

                $table->decimal('flat_charge', 12, 2)
                    ->default(0);

                $table->decimal('charge_per_transfer', 12, 2)
                    ->default(0);

                $table->decimal('price_multiplier', 10, 4)
                    ->default(1.0000);

                $table->dateTime('effective_from')
                    ->nullable();

                $table->dateTime('effective_to')
                    ->nullable();

                $table->boolean('is_active')
                    ->default(true);

                $table->text('notes')
                    ->nullable();

                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->foreignId('updated_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamps();

                $table->index([
                    'minimum_transfer_count',
                    'maximum_transfer_count',
                    'is_active',
                ], 'transfer_count_rate_lookup_index');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_count_rates');
    }
};