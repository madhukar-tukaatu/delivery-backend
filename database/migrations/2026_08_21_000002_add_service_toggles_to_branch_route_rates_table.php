<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_route_rates', function (Blueprint $table): void {
            $table->boolean('express_enabled')->default(true)->after('is_active');
            $table->boolean('same_day_enabled')->default(true)->after('express_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('branch_route_rates', function (Blueprint $table): void {
            $table->dropColumn(['express_enabled', 'same_day_enabled']);
        });
    }
};
