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
            'parcel_handling_rates',
            function (Blueprint $table): void {
                $table->id();

                $table->string('name', 100);

                /*
                 * Examples:
                 * standard
                 * fragile
                 * liquid
                 * perishable
                 * oversized
                 * document
                 * electronics
                 */
                $table->string('parcel_type', 50)
                    ->unique();

                $table->text('description')
                    ->nullable();

                $table->decimal('flat_charge', 12, 2)
                    ->default(0);

                /*
                 * Example:
                 * 1.05 = add 5%
                 */
                $table->decimal('price_multiplier', 10, 4)
                    ->default(1.0000);

                $table->decimal('minimum_charge', 12, 2)
                    ->nullable();

                $table->decimal('maximum_charge', 12, 2)
                    ->nullable();

                $table->boolean('requires_special_handling')
                    ->default(false);

                $table->boolean('requires_manual_approval')
                    ->default(false);

                $table->boolean('is_active')
                    ->default(true);

                $table->unsignedInteger('sort_order')
                    ->default(0);

                $table->dateTime('effective_from')
                    ->nullable();

                $table->dateTime('effective_to')
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
                    'parcel_type',
                    'is_active',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('parcel_handling_rates');
    }
};