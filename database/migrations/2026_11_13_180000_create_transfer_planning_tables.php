<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transfer_corridors')) {
            Schema::create('transfer_corridors', function (Blueprint $table) {
                $table->id();
                $table->string('code', 64)->unique();
                $table->string('name');
                $table->string('highway')->nullable();
                $table->unsignedSmallInteger('avg_speed_kmh')->default(35);
                $table->unsignedSmallInteger('dwell_minutes')->default(20);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('priority')->default(100);
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('transfer_corridor_stops')) {
            Schema::create('transfer_corridor_stops', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transfer_corridor_id')->constrained('transfer_corridors')->cascadeOnDelete();
                $table->unsignedSmallInteger('sequence');
                $table->string('branch_code', 64);
                $table->string('alias')->nullable();
                $table->decimal('distance_from_prev_km', 8, 1)->nullable();
                $table->decimal('hours_from_prev', 5, 2)->nullable();
                $table->timestamps();
                $table->unique(['transfer_corridor_id', 'sequence'], 'tcs_corridor_seq_unique');
                $table->index('branch_code');
            });
        }

        if (! Schema::hasTable('transfer_runs')) {
            Schema::create('transfer_runs', function (Blueprint $table) {
                $table->id();
                $table->string('run_number', 40)->unique();
                $table->string('status', 32)->default('planned')->index();
                $table->unsignedBigInteger('origin_branch_id')->index();
                $table->string('vehicle_type', 32)->nullable();
                $table->string('vehicle_number')->nullable();
                $table->unsignedBigInteger('rider_user_id')->nullable()->index();
                $table->string('driver_name')->nullable();
                $table->string('driver_phone')->nullable();
                $table->string('seal_number')->nullable();
                $table->timestamp('planned_departure_at')->nullable();
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->string('cancel_reason')->nullable();
                $table->string('corridor_code', 64)->nullable()->index();
                $table->string('tour_code', 64)->nullable()->index();
                $table->string('leg_role', 24)->nullable();
                $table->string('recommendation_key')->nullable();
                $table->text('why')->nullable();
                $table->decimal('estimated_km', 8, 1)->nullable();
                $table->decimal('estimated_hours', 6, 2)->nullable();
                $table->unsignedSmallInteger('current_stop_sequence')->default(0);
                $table->decimal('transport_cost', 10, 2)->nullable();
                $table->unsignedInteger('expected_count')->default(0);
                $table->decimal('expected_weight_kg', 10, 2)->default(0);
                $table->unsignedInteger('max_parcels')->nullable();
                $table->decimal('max_weight_kg', 10, 2)->nullable();
                $table->json('service_types')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('transfer_run_stops')) {
            Schema::create('transfer_run_stops', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transfer_run_id')->constrained('transfer_runs')->cascadeOnDelete();
                $table->unsignedSmallInteger('sequence');
                $table->unsignedBigInteger('branch_id')->index();
                $table->string('kind', 24)->default('drop');
                $table->string('status', 24)->default('pending');
                $table->timestamp('planned_arrival_at')->nullable();
                $table->timestamp('arrived_at')->nullable();
                $table->timestamp('departed_at')->nullable();
                $table->unsignedInteger('expected_parcels')->default(0);
                $table->unsignedInteger('received_parcels')->default(0);
                $table->unsignedBigInteger('dispatch_manifest_id')->nullable()->index();
                $table->timestamps();
                $table->unique(['transfer_run_id', 'sequence'], 'trs_run_seq_unique');
            });
        }

        if (! Schema::hasTable('transfer_run_items')) {
            Schema::create('transfer_run_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('transfer_run_id')->constrained('transfer_runs')->cascadeOnDelete();
                $table->unsignedBigInteger('shipment_id')->index();
                $table->unsignedBigInteger('drop_stop_branch_id')->nullable()->index();
                $table->string('service_type', 32)->nullable();
                $table->string('status', 24)->default('planned');
                $table->timestamps();
                $table->unique(['transfer_run_id', 'shipment_id'], 'tri_run_shipment_unique');
            });
        }

        if (Schema::hasTable('dispatch_manifests')) {
            Schema::table('dispatch_manifests', function (Blueprint $table) {
                if (! Schema::hasColumn('dispatch_manifests', 'planned_departure_at')) {
                    $table->timestamp('planned_departure_at')->nullable()->after('dispatched_at');
                }
                if (! Schema::hasColumn('dispatch_manifests', 'estimated_arrival_at')) {
                    $table->timestamp('estimated_arrival_at')->nullable();
                }
                if (! Schema::hasColumn('dispatch_manifests', 'estimated_hours')) {
                    $table->decimal('estimated_hours', 6, 2)->nullable();
                }
                if (! Schema::hasColumn('dispatch_manifests', 'estimated_km')) {
                    $table->decimal('estimated_km', 8, 1)->nullable();
                }
                if (! Schema::hasColumn('dispatch_manifests', 'max_parcels')) {
                    $table->unsignedInteger('max_parcels')->nullable();
                }
                if (! Schema::hasColumn('dispatch_manifests', 'max_weight_kg')) {
                    $table->decimal('max_weight_kg', 10, 2)->nullable();
                }
                if (! Schema::hasColumn('dispatch_manifests', 'transfer_run_id')) {
                    $table->unsignedBigInteger('transfer_run_id')->nullable()->index();
                }
                if (! Schema::hasColumn('dispatch_manifests', 'corridor_code')) {
                    $table->string('corridor_code', 64)->nullable();
                }
                if (! Schema::hasColumn('dispatch_manifests', 'recommendation_why')) {
                    $table->text('recommendation_why')->nullable();
                }
            });
        }

        if (class_exists(\Database\Seeders\TransferCorridorSeeder::class)) {
            (new \Database\Seeders\TransferCorridorSeeder())->run();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dispatch_manifests')) {
            Schema::table('dispatch_manifests', function (Blueprint $table) {
                foreach ([
                    'planned_departure_at', 'estimated_arrival_at', 'estimated_hours', 'estimated_km',
                    'max_parcels', 'max_weight_kg', 'transfer_run_id', 'corridor_code', 'recommendation_why',
                ] as $col) {
                    if (Schema::hasColumn('dispatch_manifests', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::dropIfExists('transfer_run_items');
        Schema::dropIfExists('transfer_run_stops');
        Schema::dropIfExists('transfer_runs');
        Schema::dropIfExists('transfer_corridor_stops');
        Schema::dropIfExists('transfer_corridors');
    }
};
