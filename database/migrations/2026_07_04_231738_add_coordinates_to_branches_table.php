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
                $table->decimal('coverage_radius_km', 8, 2)->default(10);
            }

            if (!Schema::hasColumn('branches', 'is_hub')) {
                $table->boolean('is_hub')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn([
                'latitude',
                'longitude',
                'coverage_radius_km',
                'is_hub',
            ]);
        });
    }
};