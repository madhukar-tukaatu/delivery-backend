<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Transport Vehicles
        if (!Schema::hasTable('transport_vehicles')) {
            Schema::create('transport_vehicles', function (Blueprint $table) {
                $table->id();
                $table->string('vehicle_number', 60)->unique()->comment('Plate Number e.g. BA 2 KHA 4521');
                $table->string('vehicle_type', 40)->default('pickup')->comment('bike, van, pickup, mini_truck_14ft, heavy_truck_22ft, bus_cargo, other');
                $table->string('ownership_type', 30)->default('contractor')->comment('owned, contractor, on_demand');
                
                $table->string('owner_name', 120)->nullable();
                $table->string('owner_phone', 40)->nullable();
                $table->string('owner_pan_vat', 40)->nullable();
                
                $table->string('driver_name', 120)->nullable();
                $table->string('driver_phone', 40)->nullable();
                $table->string('driver_license_number', 60)->nullable();
                
                $table->decimal('capacity_kg', 10, 2)->default(1000.00);
                $table->decimal('capacity_cbm', 8, 2)->nullable()->comment('Cubic meters capacity');
                $table->unsignedInteger('max_parcels')->default(500);
                
                $table->unsignedBigInteger('current_branch_id')->nullable()->index();
                $table->decimal('fixed_rate_per_trip', 10, 2)->nullable();
                $table->decimal('rate_per_km', 10, 2)->nullable();
                
                $table->string('status', 30)->default('available')->index()->comment('available, on_trip, maintenance, inactive');
                $table->boolean('is_active')->default(true)->index();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        // 2. Transport Drivers
        if (!Schema::hasTable('transport_drivers')) {
            Schema::create('transport_drivers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('name', 120);
                $table->string('phone', 40)->unique();
                $table->string('license_number', 60)->nullable();
                $table->string('citizenship_number', 60)->nullable();
                $table->unsignedBigInteger('assigned_vehicle_id')->nullable()->index();
                $table->unsignedBigInteger('base_branch_id')->nullable()->index();
                $table->string('status', 30)->default('available')->index()->comment('available, on_trip, off_duty, inactive');
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        // 3. Transport Trips (Consolidated Line-haul Highway Runs)
        if (!Schema::hasTable('transport_trips')) {
            Schema::create('transport_trips', function (Blueprint $table) {
                $table->id();
                $table->string('trip_number', 50)->unique()->comment('TRIP-YYYYMMDD-XXXX');
                $table->unsignedBigInteger('from_branch_id')->index();
                $table->unsignedBigInteger('to_branch_id')->index();
                $table->unsignedBigInteger('route_id')->nullable()->index()->comment('branch_transfer_routes.id');
                
                $table->unsignedBigInteger('vehicle_id')->nullable()->index();
                $table->unsignedBigInteger('driver_id')->nullable()->index();
                $table->string('vehicle_number', 60)->nullable();
                $table->string('vehicle_type', 40)->nullable();
                $table->string('driver_name', 120)->nullable();
                $table->string('driver_phone', 40)->nullable();
                
                $table->string('status', 30)->default('scheduled')->index()->comment('scheduled, loading, dispatched, in_transit, arrived, completed, cancelled');
                $table->string('departure_seal_number', 60)->nullable();
                $table->string('arrival_seal_status', 30)->nullable()->comment('ok, mismatch, tampered');
                
                $table->decimal('total_weight_kg', 10, 2)->default(0);
                $table->unsignedInteger('total_manifests_count')->default(0)->comment('Count of TR bags/manifests');
                $table->unsignedInteger('total_parcels_count')->default(0)->comment('Total items inside all TR bags');
                
                $table->timestamp('scheduled_departure_at')->nullable();
                $table->timestamp('departed_at')->nullable();
                $table->timestamp('arrived_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                
                $table->decimal('freight_cost', 10, 2)->default(0);
                $table->string('cost_mode', 30)->default('per_trip')->comment('per_trip, per_km, per_kg, free_internal');
                $table->string('payment_status', 30)->default('unpaid')->comment('unpaid, approved, paid');
                
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        // 4. Trip Manifest Items (Mapping TR manifests to Transport Trips)
        if (!Schema::hasTable('transport_trip_manifests')) {
            Schema::create('transport_trip_manifests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('transport_trip_id')->index();
                $table->unsignedBigInteger('dispatch_manifest_id')->index()->comment('dispatch_manifests.id (TR)');
                $table->string('status', 30)->default('loaded')->index()->comment('loaded, in_transit, received, removed');
                $table->timestamps();

                $table->unique(['transport_trip_id', 'dispatch_manifest_id'], 'trip_manifest_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_trip_manifests');
        Schema::dropIfExists('transport_trips');
        Schema::dropIfExists('transport_drivers');
        Schema::dropIfExists('transport_vehicles');
    }
};

