<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pricing_quote_transfer_lanes')) {
            return;
        }

        Schema::create(
            'pricing_quote_transfer_lanes',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('pricing_quote_id')
                    ->constrained('pricing_quotes')
                    ->cascadeOnDelete();

                $table->foreignId('branch_transfer_lane_id')
                    ->constrained('branch_transfer_lanes');

                $table->unsignedInteger('sequence_number');

                $table->foreignId('from_branch_id')
                    ->constrained('branches');

                $table->foreignId('to_branch_id')
                    ->constrained('branches');

                $table->string('service_type', 40)
                    ->nullable();

                $table->string('transport_mode', 50)
                    ->nullable();

                $table->decimal('distance_km', 10, 2)
                    ->nullable();

                $table->unsignedInteger('estimated_hours')
                    ->nullable();

                $table->boolean('is_reverse_direction')
                    ->default(false);

                $table->timestamps();

                $table->unique(
                    ['pricing_quote_id', 'sequence_number'],
                    'pq_transfer_lanes_quote_seq_unique'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_quote_transfer_lanes');
    }
};