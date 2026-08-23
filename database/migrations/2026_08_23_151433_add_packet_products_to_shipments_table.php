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
        | 1. Add packet_products
        |--------------------------------------------------------------------------
        |
        | Stores the products belonging to the packet as JSON.
        |
        | Example:
        |
        | [
        |     {
        |         "product_id": "TSHIRT-001",
        |         "name": "Test T-Shirt",
        |         "quantity": 1,
        |         "unit_price": 1500,
        |         "unit_weight": 1.2,
        |         "parcel_type": "non_fragile"
        |     }
        | ]
        |
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
        | 2. Make self_drop safe
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        | Existing rows may contain NULL.
        |
        | We must first convert NULL -> 0.
        | Only after that can MySQL safely change the column
        | to NOT NULL DEFAULT 0.
        |
        */

        if (Schema::hasColumn('shipments', 'self_drop')) {

            DB::table('shipments')
                ->whereNull('self_drop')
                ->update([
                    'self_drop' => 0,
                ]);

            /*
            |--------------------------------------------------------------------------
            | Change column to:
            |
            | BOOLEAN
            | NOT NULL
            | DEFAULT FALSE
            |--------------------------------------------------------------------------
            */

            Schema::table('shipments', function (Blueprint $table) {
                $table->boolean('self_drop')
                    ->default(false)
                    ->nullable(false)
                    ->change();
            });
        } else {

            /*
            |--------------------------------------------------------------------------
            | If self_drop does not exist at all, create it safely.
            |--------------------------------------------------------------------------
            */

            Schema::table('shipments', function (Blueprint $table) {
                $table->boolean('self_drop')
                    ->default(false)
                    ->nullable(false)
                    ->after('delivery_charge_paid_by');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('shipments')) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Remove packet_products
        |--------------------------------------------------------------------------
        */

        if (Schema::hasColumn('shipments', 'packet_products')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropColumn('packet_products');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Do NOT remove self_drop here.
        |--------------------------------------------------------------------------
        |
        | self_drop may have existed before this migration.
        | This migration only makes it safe.
        |
        */
    }
};
