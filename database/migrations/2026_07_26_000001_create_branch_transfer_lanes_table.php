<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branch_transfer_lanes')) {
            return;
        }

        Schema::create(
            'branch_transfer_lanes',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('from_branch_id')
                    ->constrained('branches');

                $table->foreignId('to_branch_id')
                    ->constrained('branches');

                $table->string('service_type', 40)
                    ->default('standard');

                $table->string('transport_mode', 50)
                    ->nullable();

                $table->decimal('distance_km', 10, 2)
                    ->nullable();

                $table->unsignedInteger('estimated_hours')
                    ->default(1);

                /*
                 * Lower value wins when route duration and hop count tie.
                 */
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

                $table->index([
                    'service_type',
                    'is_active',
                ]);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_transfer_lanes');
    }
};
