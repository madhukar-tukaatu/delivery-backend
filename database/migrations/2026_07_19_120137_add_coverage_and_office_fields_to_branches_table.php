<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('branches')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            if (!Schema::hasColumn('branches', 'coverage_location_id')) {
                $table->foreignId('coverage_location_id')
                    ->nullable()
                    ->after('parent_id')
                    ->constrained('coverage_locations')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('branches', 'office_address')) {
                $table->text('office_address')->nullable()->after('coverage_radius_km');
            }

            if (!Schema::hasColumn('branches', 'office_city')) {
                $table->string('office_city')->nullable()->after('office_address');
            }

            if (!Schema::hasColumn('branches', 'office_area')) {
                $table->string('office_area')->nullable()->after('office_city');
            }

            if (!Schema::hasColumn('branches', 'office_street')) {
                $table->string('office_street')->nullable()->after('office_area');
            }

            if (!Schema::hasColumn('branches', 'office_landmark')) {
                $table->string('office_landmark')->nullable()->after('office_street');
            }

            if (!Schema::hasColumn('branches', 'office_latitude')) {
                $table->decimal('office_latitude', 11, 7)->nullable()->after('office_landmark');
            }

            if (!Schema::hasColumn('branches', 'office_longitude')) {
                $table->decimal('office_longitude', 11, 7)->nullable()->after('office_latitude');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('branches')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            if (Schema::hasColumn('branches', 'coverage_location_id')) {
                $table->dropForeign(['coverage_location_id']);
                $table->dropColumn('coverage_location_id');
            }

            foreach ([
                'office_longitude',
                'office_latitude',
                'office_landmark',
                'office_street',
                'office_area',
                'office_city',
                'office_address',
            ] as $column) {
                if (Schema::hasColumn('branches', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};