<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // IMPORTANT: This migration safely handles existing tables from old migrations
        // It will NOT create duplicates or cause FK constraint errors

        // 1. Branch Transfer Lanes - Physical connections
        if (!Schema::hasTable('branch_transfer_lanes')) {
            Schema::create('branch_transfer_lanes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('from_branch_id')
                    ->constrained('coverage_locations', 'id')
                    ->cascadeOnDelete();
                $table->foreignId('to_branch_id')
                    ->constrained('coverage_locations', 'id')
                    ->cascadeOnDelete();
                $table->enum('service_type', ['standard', 'express', 'same_day', 'flight'])->default('standard');
                $table->enum('transport_mode', ['road', 'flight', 'rail'])->default('road');
                $table->decimal('distance_km', 8, 2);
                $table->decimal('estimated_hours', 8, 2);
                $table->integer('priority')->default(100);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                // Use shorter constraint name to avoid MySQL identifier length limit (64 chars)
                $table->unique(['from_branch_id', 'to_branch_id', 'service_type'], 'btl_from_to_service_unique');
                $table->index(['from_branch_id', 'service_type']);
                $table->index(['to_branch_id', 'service_type']);
            });
        }

        // 2. Branch Transfer Routes - Business routes
        if (!Schema::hasTable('branch_transfer_routes')) {
            Schema::create('branch_transfer_routes', function (Blueprint $table) {
                $table->id();
                $table->string('route_code', 100)->unique();
                $table->string('name', 255);
                $table->foreignId('branch_transfer_lane_id')
                    ->constrained('branch_transfer_lanes', 'id')
                    ->cascadeOnDelete();
                $table->string('service_type', 40)->default('standard');
                $table->decimal('base_rate', 12, 2)->default(0);
                $table->char('currency', 3)->default('NPR');
                $table->decimal('distance_km', 10, 2)->default(0);
                $table->unsignedInteger('estimated_hours')->default(0);
                $table->unsignedInteger('priority')->default(100);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['branch_transfer_lane_id', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        // Disable FK checks to allow dropping tables with external references
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        
        Schema::dropIfExists('branch_transfer_routes');
        Schema::dropIfExists('branch_transfer_lanes');
        
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }
};
