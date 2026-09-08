<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Recreate branch_transfer_lanes table
        if (!Schema::hasTable('branch_transfer_lanes')) {
            Schema::create('branch_transfer_lanes', function (Blueprint $table): void {
                $table->id();

                $table->foreignId('from_branch_id')
                    ->constrained('coverage_locations', 'id')
                    ->cascadeOnDelete();

                $table->foreignId('to_branch_id')
                    ->constrained('coverage_locations', 'id')
                    ->cascadeOnDelete();

                $table->string('service_type', 40)
                    ->default('standard');

                $table->string('transport_mode', 50)
                    ->nullable();

                $table->decimal('distance_km', 10, 2)
                    ->nullable();

                $table->unsignedInteger('estimated_hours')
                    ->default(1);

                $table->unsignedInteger('priority')
                    ->default(100);

                $table->boolean('is_bidirectional')
                    ->default(false);

                $table->boolean('is_active')
                    ->default(true);

                $table->timestamps();

                $table->unique(
                    [
                        'from_branch_id',
                        'to_branch_id',
                        'service_type',
                    ],
                    'transfer_lane_direction_service_unique'
                );

                $table->index(['service_type', 'is_active']);
            });
        }

        // Recreate branch_transfer_route_lanes join table
        if (!Schema::hasTable('branch_transfer_route_lanes')) {
            Schema::create('branch_transfer_route_lanes', function (Blueprint $table): void {
                $table->id();

                $table->foreignId('branch_transfer_route_id')
                    ->constrained('branch_transfer_routes')
                    ->cascadeOnDelete();

                $table->foreignId('branch_transfer_lane_id')
                    ->constrained('branch_transfer_lanes')
                    ->restrictOnDelete();

                $table->unsignedTinyInteger('sequence_number');
                $table->timestamps();

                $table->unique(
                    [
                        'branch_transfer_route_id',
                        'sequence_number',
                    ],
                    'transfer_route_sequence_unique'
                );

                $table->unique(
                    [
                        'branch_transfer_route_id',
                        'branch_transfer_lane_id',
                    ],
                    'transfer_route_lane_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_transfer_route_lanes');
        Schema::dropIfExists('branch_transfer_lanes');
    }
};
