<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shipments')) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | 1. Ensure packet_products exists
        |--------------------------------------------------------------------------
        */

        if (! Schema::hasColumn('shipments', 'packet_products')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->json('packet_products')
                    ->nullable()
                    ->after('fragile');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Ensure self_drop exists
        |--------------------------------------------------------------------------
        */

        if (! Schema::hasColumn('shipments', 'self_drop')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->boolean('self_drop')
                    ->default(false)
                    ->nullable(false)
                    ->after('delivery_charge_paid_by');
            });

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Convert existing NULL self_drop values to false
        |--------------------------------------------------------------------------
        |
        | This MUST happen before changing the column to NOT NULL.
        |
        */

        DB::table('shipments')
            ->whereNull('self_drop')
            ->update([
                'self_drop' => 0,
            ]);

        /*
        |--------------------------------------------------------------------------
        | 4. Make self_drop NOT NULL DEFAULT 0
        |--------------------------------------------------------------------------
        */

        Schema::table('shipments', function (Blueprint $table) {
            $table->boolean('self_drop')
                ->default(false)
                ->nullable(false)
                ->change();
        });
    }

    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Do not remove packet_products or self_drop
        |--------------------------------------------------------------------------
        |
        | Both columns are now part of the active shipment schema.
        | Rolling this migration back should not destroy application data.
        |
        */
    }
};