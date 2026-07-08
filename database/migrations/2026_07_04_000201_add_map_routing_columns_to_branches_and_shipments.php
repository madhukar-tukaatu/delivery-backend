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
                $table->decimal('latitude', 10, 7)->nullable()->after('address');
            }
            if (!Schema::hasColumn('branches', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (!Schema::hasColumn('branches', 'coverage_radius_km')) {
                $table->decimal('coverage_radius_km', 8, 2)->default(10)->after('longitude');
            }
        });

        Schema::table('branch_service_areas', function (Blueprint $table) {
            if (!Schema::hasColumn('branch_service_areas', 'sub_branch_id')) {
                $table->foreignId('sub_branch_id')->nullable()->after('branch_id')->constrained('branches')->nullOnDelete();
            }
            if (!Schema::hasColumn('branch_service_areas', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('postal_code');
            }
            if (!Schema::hasColumn('branch_service_areas', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (!Schema::hasColumn('branch_service_areas', 'radius_km')) {
                $table->decimal('radius_km', 8, 2)->default(5)->after('longitude');
            }
            if (!Schema::hasColumn('branch_service_areas', 'service_type')) {
                $table->string('service_type')->default('both')->after('radius_km'); // pickup, delivery, both
            }
            if (!Schema::hasColumn('branch_service_areas', 'priority')) {
                $table->unsignedInteger('priority')->default(100)->after('service_type');
            }
        });

        Schema::table('shipments', function (Blueprint $table) {
            if (!Schema::hasColumn('shipments', 'pickup_lat')) {
                $table->decimal('pickup_lat', 10, 7)->nullable()->after('sender_area');
            }
            if (!Schema::hasColumn('shipments', 'pickup_lng')) {
                $table->decimal('pickup_lng', 10, 7)->nullable()->after('pickup_lat');
            }
            if (!Schema::hasColumn('shipments', 'delivery_lat')) {
                $table->decimal('delivery_lat', 10, 7)->nullable()->after('receiver_area');
            }
            if (!Schema::hasColumn('shipments', 'delivery_lng')) {
                $table->decimal('delivery_lng', 10, 7)->nullable()->after('delivery_lat');
            }
            if (!Schema::hasColumn('shipments', 'route_distance_km')) {
                $table->decimal('route_distance_km', 10, 2)->default(0)->after('delivery_lng');
            }
            if (!Schema::hasColumn('shipments', 'route_fee')) {
                $table->decimal('route_fee', 12, 2)->default(0)->after('route_distance_km');
            }
            if (!Schema::hasColumn('shipments', 'estimated_delivery_time')) {
                $table->string('estimated_delivery_time')->nullable()->after('route_fee');
            }
            if (!Schema::hasColumn('shipments', 'delivery_charge_breakdown')) {
                $table->json('delivery_charge_breakdown')->nullable()->after('estimated_delivery_time');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            foreach (['pickup_lat', 'pickup_lng', 'delivery_lat', 'delivery_lng', 'route_distance_km', 'route_fee', 'estimated_delivery_time', 'delivery_charge_breakdown'] as $column) {
                if (Schema::hasColumn('shipments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('branch_service_areas', function (Blueprint $table) {
            foreach (['sub_branch_id', 'latitude', 'longitude', 'radius_km', 'service_type', 'priority'] as $column) {
                if (Schema::hasColumn('branch_service_areas', $column)) {
                    if ($column === 'sub_branch_id') {
                        $table->dropConstrainedForeignId('sub_branch_id');
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });

        Schema::table('branches', function (Blueprint $table) {
            foreach (['latitude', 'longitude', 'coverage_radius_km'] as $column) {
                if (Schema::hasColumn('branches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
