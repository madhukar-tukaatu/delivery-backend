<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table): void {
            $table->id();

            $table->string('name', 100);
            $table->string('code', 50)->unique();

            $table->text('description')->nullable();

            /*
             * Examples:
             * standard
             * same_day
             * express
             * scheduled
             */
            $table->string('category', 50)
                ->default('standard')
                ->index();

            $table->decimal('price_multiplier', 10, 4)
                ->default(1.0000);

            $table->unsignedInteger('estimated_hours')
                ->nullable();

            $table->time('cutoff_time')
                ->nullable();

            $table->boolean('same_day')
                ->default(false);

            $table->boolean('requires_branch_transfer')
                ->default(false);

            $table->boolean('available_for_local')
                ->default(true);

            $table->boolean('available_for_transfer')
                ->default(true);

            $table->boolean('available_for_public_api')
                ->default(true);

            $table->boolean('is_default')
                ->default(false);

            $table->boolean('is_active')
                ->default(true);

            $table->unsignedInteger('sort_order')
                ->default(0);

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
                'is_active',
                'available_for_public_api',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_types');
    }
};