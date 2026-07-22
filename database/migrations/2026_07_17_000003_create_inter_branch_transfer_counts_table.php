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
            'inter_branch_transfer_counts',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('pickup_branch_id')
                    ->constrained('branches')
                    ->cascadeOnDelete();

                $table->foreignId('delivery_branch_id')
                    ->constrained('branches')
                    ->cascadeOnDelete();

                /*
                 * Number of branch transfers between pickup and delivery.
                 *
                 * Kathmandu -> Pokhara direct = 1
                 * Kathmandu -> Central Hub -> Biratnagar = 2
                 */
                $table->unsignedInteger('transfer_count')
                    ->default(1);

                $table->json('transfer_path')
                    ->nullable();

                $table->decimal('estimated_transfer_hours', 10, 2)
                    ->nullable();

                $table->boolean('bidirectional')
                    ->default(false);

                $table->boolean('is_active')
                    ->default(true);

                $table->dateTime('effective_from')
                    ->nullable();

                $table->dateTime('effective_to')
                    ->nullable();

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

                $table->unique([
                    'pickup_branch_id',
                    'delivery_branch_id',
                ], 'inter_branch_transfer_unique_route');

                $table->index([
                    'pickup_branch_id',
                    'delivery_branch_id',
                    'is_active',
                ], 'inter_branch_transfer_lookup_index');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'inter_branch_transfer_counts'
        );
    }
};