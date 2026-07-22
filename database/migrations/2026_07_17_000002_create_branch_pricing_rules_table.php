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
            'branch_pricing_rules',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('pickup_branch_id')
                    ->constrained('branches')
                    ->cascadeOnDelete();

                $table->foreignId('delivery_branch_id')
                    ->constrained('branches')
                    ->cascadeOnDelete();

                $table->foreignId('service_type_id')
                    ->nullable()
                    ->constrained('service_types')
                    ->nullOnDelete();

                /*
                 * local:
                 * Pickup and delivery handled by the same branch.
                 *
                 * transfer:
                 * Shipment moves between branches.
                 */
                $table->string('route_type', 30)
                    ->default('local')
                    ->index();

                $table->decimal('base_price', 12, 2);

                $table->decimal('included_weight_kg', 10, 3)
                    ->default(1.500);

                $table->decimal('included_distance_km', 10, 3)
                    ->default(5.000);

                $table->decimal('extra_weight_rate', 12, 2)
                    ->nullable();

                $table->decimal('extra_distance_rate', 12, 2)
                    ->nullable();

                $table->decimal('minimum_charge', 12, 2)
                    ->nullable();

                $table->decimal('maximum_charge', 12, 2)
                    ->nullable();

                $table->boolean('bidirectional')
                    ->default(false);

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
                    'pickup_branch_id',
                    'delivery_branch_id',
                    'service_type_id',
                    'is_active',
                ], 'branch_pricing_lookup_index');

                $table->unique([
                    'pickup_branch_id',
                    'delivery_branch_id',
                    'service_type_id',
                ], 'branch_pricing_unique_route');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_pricing_rules');
    }
};