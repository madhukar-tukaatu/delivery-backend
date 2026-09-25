<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dispatch_manifests', function (Blueprint $table) {
            $table->foreignId('route_id')->nullable()->constrained('branch_transfer_routes')->nullOnDelete()->after('seal_number');
            $table->string('route_code')->nullable()->after('route_id');
            $table->boolean('is_multi_hop')->default(false)->after('route_code');
            $table->json('transit_branch_ids')->nullable()->after('is_multi_hop');
            $table->foreignId('final_destination_branch_id')->nullable()->constrained('branches')->nullOnDelete()->after('transit_branch_ids');
            $table->string('driver_phone')->nullable()->after('driver_name');
            $table->text('notes')->nullable()->after('driver_phone');
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_manifests', function (Blueprint $table) {
            $table->dropForeign(['route_id']);
            $table->dropColumn([
                'route_id',
                'route_code',
                'is_multi_hop',
                'transit_branch_ids',
                'final_destination_branch_id',
                'driver_phone',
                'notes',
            ]);
        });
    }
};