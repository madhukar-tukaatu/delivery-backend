<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('branch_transfer_routes')) {
            Schema::create(
                'branch_transfer_routes',
                function (Blueprint $table): void {
                    $table->id();
                    $table->string('route_code', 100)->unique();
                    $table->string('name', 255);

                    $table->foreignId('origin_branch_id')
                        ->constrained('branches')
                        ->cascadeOnUpdate()
                        ->restrictOnDelete();

                    $table->foreignId('destination_branch_id')
                        ->constrained('branches')
                        ->cascadeOnUpdate()
                        ->restrictOnDelete();

                    $table->string('service_type', 40)
                        ->default('standard');

                    /*
                     * Price of the complete route. It is independent from
                     * each direct branch_transfer_lanes segment.
                     */
                    $table->decimal('base_rate', 12, 2)
                        ->default(0);
                    $table->char('currency', 3)
                        ->default('NPR');

                    /*
                     * Kathmandu -> Pokhara -> Mustang
                     * transfer_count = 2, transit_count = 1.
                     */
                    $table->unsignedTinyInteger('transfer_count')
                        ->default(0);
                    $table->unsignedTinyInteger('transit_count')
                        ->default(0);

                    $table->decimal('total_distance_km', 10, 2)
                        ->default(0);
                    $table->unsignedInteger('total_estimated_hours')
                        ->default(0);

                    $table->unsignedInteger('priority')
                        ->default(100);
                    $table->boolean('is_default')
                        ->default(true);
                    $table->boolean('is_active')
                        ->default(true);
                    $table->text('notes')->nullable();
                    $table->timestamps();

                    $table->index(
                        [
                            'origin_branch_id',
                            'destination_branch_id',
                            'service_type',
                            'is_active',
                        ],
                        'branch_transfer_route_lookup'
                    );
                }
            );
        }

        if (!Schema::hasTable('branch_transfer_route_lanes')) {
            Schema::create(
                'branch_transfer_route_lanes',
                function (Blueprint $table): void {
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
                }
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_transfer_route_lanes');
        Schema::dropIfExists('branch_transfer_routes');
    }
};
