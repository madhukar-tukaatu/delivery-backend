<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('shipments')
            || ! Schema::hasColumn('shipments', 'delivery_lat')
            || ! Schema::hasColumn('shipments', 'delivery_lng')
            || ! Schema::hasColumn('shipments', 'receiver_latitude')
            || ! Schema::hasColumn('shipments', 'receiver_longitude')
        ) {
            return;
        }

        DB::table('shipments')
            ->whereNull('delivery_lat')
            ->whereNull('delivery_lng')
            ->whereNotNull('receiver_latitude')
            ->whereNotNull('receiver_longitude')
            ->update([
                'delivery_lat' => DB::raw('receiver_latitude'),
                'delivery_lng' => DB::raw('receiver_longitude'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // The values copied by this migration may also have been intentionally
        // written by a later shipment update, so they must not be cleared.
    }
};
