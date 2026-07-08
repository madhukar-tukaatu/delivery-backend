<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | delivery_route_segments
        |--------------------------------------------------------------------------
        */
        if (!Schema::hasTable('delivery_route_segments')) {
            Schema::create('delivery_route_segments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('from_branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('to_branch_id')->constrained('branches')->cascadeOnDelete();
                $table->string('route_name')->nullable();
                $table->decimal('distance_km', 10, 2)->default(0);
                $table->decimal('base_fee', 12, 2)->default(0);
                $table->decimal('per_kg_fee', 12, 2)->default(0);
                $table->unsignedInteger('estimated_hours')->default(24);
                $table->unsignedInteger('priority')->default(1);
                $table->string('status')->default('active');
                $table->timestamps();

                $table->index(['from_branch_id', 'to_branch_id']);
            });
        } else {
            Schema::table('delivery_route_segments', function (Blueprint $table) {
                if (!Schema::hasColumn('delivery_route_segments', 'route_name')) {
                    $table->string('route_name')->nullable();
                }

                if (!Schema::hasColumn('delivery_route_segments', 'distance_km')) {
                    $table->decimal('distance_km', 10, 2)->default(0);
                }

                if (!Schema::hasColumn('delivery_route_segments', 'base_fee')) {
                    $table->decimal('base_fee', 12, 2)->default(0);
                }

                if (!Schema::hasColumn('delivery_route_segments', 'per_kg_fee')) {
                    $table->decimal('per_kg_fee', 12, 2)->default(0);
                }

                if (!Schema::hasColumn('delivery_route_segments', 'estimated_hours')) {
                    $table->unsignedInteger('estimated_hours')->default(24);
                }

                if (!Schema::hasColumn('delivery_route_segments', 'priority')) {
                    $table->unsignedInteger('priority')->default(1);
                }

                if (!Schema::hasColumn('delivery_route_segments', 'status')) {
                    $table->string('status')->default('active');
                }
            });
        }

        /*
        |--------------------------------------------------------------------------
        | shipment_route_steps
        |--------------------------------------------------------------------------
        */
        if (!Schema::hasTable('shipment_route_steps')) {
            Schema::create('shipment_route_steps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
                $table->unsignedInteger('sequence');
                $table->foreignId('from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('to_branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->decimal('distance_km', 10, 2)->default(0);
                $table->decimal('fee', 12, 2)->default(0);
                $table->unsignedInteger('estimated_hours')->default(0);
                $table->string('status')->default('pending');
                $table->timestamp('departed_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamps();

                $table->index(['shipment_id', 'sequence']);
            });
        } else {
            Schema::table('shipment_route_steps', function (Blueprint $table) {
                if (!Schema::hasColumn('shipment_route_steps', 'sequence')) {
                    $table->unsignedInteger('sequence')->default(1);
                }

                if (!Schema::hasColumn('shipment_route_steps', 'distance_km')) {
                    $table->decimal('distance_km', 10, 2)->default(0);
                }

                if (!Schema::hasColumn('shipment_route_steps', 'fee')) {
                    $table->decimal('fee', 12, 2)->default(0);
                }

                if (!Schema::hasColumn('shipment_route_steps', 'estimated_hours')) {
                    $table->unsignedInteger('estimated_hours')->default(0);
                }

                if (!Schema::hasColumn('shipment_route_steps', 'status')) {
                    $table->string('status')->default('pending');
                }

                if (!Schema::hasColumn('shipment_route_steps', 'departed_at')) {
                    $table->timestamp('departed_at')->nullable();
                }

                if (!Schema::hasColumn('shipment_route_steps', 'received_at')) {
                    $table->timestamp('received_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // Do not drop tables here because an older routing migration may own them.
        // Keep this empty to avoid deleting existing route data.
    }
};