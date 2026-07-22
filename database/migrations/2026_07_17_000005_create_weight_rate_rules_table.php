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
            'weight_rate_rules',
            function (Blueprint $table): void {
                $table->id();

                /*
                 * local or transfer.
                 * NULL means the rule applies to both.
                 */
                $table->string('route_type', 30)
                    ->nullable()
                    ->index();

                $table->foreignId('service_type_id')
                    ->nullable()
                    ->constrained('service_types')
                    ->nullOnDelete();

                $table->decimal('minimum_weight_kg', 10, 3)
                    ->default(0);

                $table->decimal('maximum_weight_kg', 10, 3)
                    ->nullable();

                /*
                 * Applied for each chargeable unit.
                 */
                $table->decimal('rate_per_kg', 12, 2)
                    ->default(0);

                /*
                 * Optional fixed charge for this weight band.
                 */
                $table->decimal('flat_charge', 12, 2)
                    ->default(0);

                $table->decimal('weight_step_kg', 10, 3)
                    ->default(1.000);

                /*
                 * exact, ceil, floor, round
                 */
                $table->string('rounding_method', 20)
                    ->default('ceil');

                $table->dateTime('effective_from')
                    ->nullable();

                $table->dateTime('effective_to')
                    ->nullable();

                $table->boolean('is_active')
                    ->default(true);

                $table->unsignedInteger('priority')
                    ->default(0);

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
                    'route_type',
                    'service_type_id',
                    'minimum_weight_kg',
                    'maximum_weight_kg',
                    'is_active',
                ], 'weight_rate_lookup_index');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('weight_rate_rules');
    }
};