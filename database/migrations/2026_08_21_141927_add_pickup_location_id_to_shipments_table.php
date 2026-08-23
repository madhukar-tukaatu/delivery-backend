<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shipments', 'pickup_location_id')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->foreignId('pickup_location_id')
                    ->nullable()
                    ->after('merchant_id')
                    ->constrained('merchant_pickup_locations')
                    ->nullOnDelete();

                $table->index([
                    'merchant_id',
                    'pickup_location_id',
                ]);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shipments', 'pickup_location_id')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropForeign([
                    'pickup_location_id',
                ]);

                $table->dropColumn('pickup_location_id');
            });
        }
    }
};