<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_route_rates', function (Blueprint $table): void {
            $table->foreignId('branch_transfer_route_id')
                ->nullable()
                ->after('delivery_coverage_location_id')
                ->constrained('branch_transfer_routes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branch_route_rates', function (Blueprint $table): void {
            $table->dropForeign(['branch_transfer_route_id']);
            $table->dropColumn('branch_transfer_route_id');
        });
    }
};
