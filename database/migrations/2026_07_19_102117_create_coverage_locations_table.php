<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('coverage_locations')) {
            return;
        }

        Schema::create('coverage_locations', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('code')->unique();

            $table->enum('type', [
                'main_branch_zone',
                'sub_branch_zone',
            ]);

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('coverage_locations')
                ->nullOnDelete();

            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            $table->string('country')->default('Nepal');
            $table->string('province')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('area')->nullable();
            $table->string('street')->nullable();
            $table->text('address')->nullable();
            $table->string('landmark')->nullable();

            $table->decimal('latitude', 11, 7);
            $table->decimal('longitude', 11, 7);
            $table->decimal('coverage_radius_km', 8, 2)->default(5);

            $table->boolean('is_hq_managed')->default(true);
            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['parent_id', 'status']);
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_locations');
    }
};