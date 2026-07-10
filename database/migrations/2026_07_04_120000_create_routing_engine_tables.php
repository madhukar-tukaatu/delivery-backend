<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (!Schema::hasColumn('branches', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable();
            }

            if (!Schema::hasColumn('branches', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable();
            }

            if (!Schema::hasColumn('branches', 'coverage_radius_km')) {
                $table->decimal('coverage_radius_km', 8, 2)->default(5);
            }

            if (!Schema::hasColumn('branches', 'is_hub')) {
                $table->boolean('is_hub')->default(false);
            }
        });

        if (!Schema::hasTable('routing_service_areas')) {
            Schema::create('routing_service_areas', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('sub_branch_id')->nullable()->constrained('branches')->nullOnDelete();

                $table->string('province')->nullable()->index();
                $table->string('district')->nullable()->index();
                $table->string('city')->nullable()->index();
                $table->string('area')->nullable()->index();

                $table->decimal('latitude', 10, 7);
                $table->decimal('longitude', 10, 7);
                $table->decimal('radius_km', 8, 2)->default(3);

                $table->enum('type', ['pickup', 'delivery', 'both'])->default('both');
                $table->unsignedSmallInteger('priority')->default(100);
                $table->string('status')->default('active')->index();

                $table->timestamps();

                $table->index(['latitude', 'longitude']);
            });
        }

        if (!Schema::hasTable('delivery_routes')) {
            Schema::create('delivery_routes', function (Blueprint $table) {
                $table->id();

                $table->string('route_name');
                $table->foreignId('origin_branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('destination_branch_id')->constrained('branches')->cascadeOnDelete();

                $table->decimal('total_distance_km', 10, 2)->default(0);
                $table->decimal('base_route_fee', 12, 2)->default(0);
                $table->decimal('estimated_hours', 8, 2)->default(0);

                $table->string('status')->default('active')->index();
                $table->timestamps();

                $table->unique(
                    ['origin_branch_id', 'destination_branch_id'],
                    'delivery_routes_origin_destination_unique'
                );
            });
        }

        if (!Schema::hasTable('delivery_route_segments')) {
            Schema::create('delivery_route_segments', function (Blueprint $table) {
                $table->id();

                $table->foreignId('delivery_route_id')
                    ->nullable()
                    ->constrained('delivery_routes')
                    ->nullOnDelete();

                $table->unsignedSmallInteger('sequence')->default(1);

                $table->foreignId('from_branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('to_branch_id')->constrained('branches')->cascadeOnDelete();

                $table->decimal('distance_km', 10, 2)->default(0);
                $table->decimal('fee', 12, 2)->default(0);
                $table->decimal('estimated_hours', 8, 2)->default(0);

                $table->string('status')->default('active')->index();
                $table->timestamps();

                $table->index(['from_branch_id', 'to_branch_id']);
            });
        }

        if (!Schema::hasTable('tariff_rules_v2')) {
            Schema::create('tariff_rules_v2', function (Blueprint $table) {
                $table->id();

                $table->string('name');

                $table->foreignId('origin_branch_id')
                    ->nullable()
                    ->constrained('branches')
                    ->nullOnDelete();

                $table->foreignId('destination_branch_id')
                    ->nullable()
                    ->constrained('branches')
                    ->nullOnDelete();

                $table->decimal('min_weight', 8, 2)->default(0);
                $table->decimal('max_weight', 8, 2)->default(5);

                $table->decimal('base_charge', 12, 2)->default(0);
                $table->decimal('per_km_charge', 12, 2)->default(0);
                $table->decimal('per_kg_charge', 12, 2)->default(0);

                $table->decimal('cod_percent', 8, 2)->default(0);
                $table->decimal('cod_fixed', 12, 2)->default(0);

                $table->decimal('pickup_fee', 12, 2)->default(0);
                $table->decimal('delivery_fee', 12, 2)->default(0);
                $table->decimal('remote_area_fee', 12, 2)->default(0);
                $table->decimal('return_fee', 12, 2)->default(0);

                $table->string('status')->default('active')->index();
                $table->timestamps();

                $table->index(['origin_branch_id', 'destination_branch_id']);
            });
        }

        if (!Schema::hasTable('shipment_route_steps')) {
            Schema::create('shipment_route_steps', function (Blueprint $table) {
                $table->id();

                $table->foreignId('shipment_id')
                    ->constrained('shipments')
                    ->cascadeOnDelete();

                $table->unsignedSmallInteger('sequence');

                $table->foreignId('from_branch_id')
                    ->nullable()
                    ->constrained('branches')
                    ->nullOnDelete();

                $table->foreignId('to_branch_id')
                    ->nullable()
                    ->constrained('branches')
                    ->nullOnDelete();

                $table->decimal('distance_km', 10, 2)->default(0);
                $table->decimal('fee', 12, 2)->default(0);

                $table->string('status')->default('pending')->index();

                $table->foreignId('received_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('received_at')->nullable();

                $table->timestamps();

                $table->unique(['shipment_id', 'sequence']);
            });
        }

        Schema::table('shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('shipments', 'route_id')) {
                $table->foreignId('route_id')
                    ->nullable()
                    ->after('destination_sub_branch_id')
                    ->constrained('delivery_routes')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasColumn('shipments', 'route_id')) {
                $table->dropConstrainedForeignId('route_id');
            }
        });

        Schema::dropIfExists('shipment_route_steps');
        Schema::dropIfExists('tariff_rules_v2');
        Schema::dropIfExists('delivery_route_segments');
        Schema::dropIfExists('delivery_routes');
        Schema::dropIfExists('routing_service_areas');

        Schema::table('branches', function (Blueprint $table) {
            foreach (['latitude', 'longitude', 'coverage_radius_km', 'is_hub'] as $column) {
                if (Schema::hasColumn('branches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};