<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('shipments') &&
            ! Schema::hasColumn('shipments', 'packet_products')
        ) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->json('packet_products')
                    ->nullable()
                    ->after('fragile');
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Make self_drop safe
        |--------------------------------------------------------------------------
        */

        if (
            Schema::hasTable('shipments') &&
            Schema::hasColumn('shipments', 'self_drop')
        ) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->boolean('self_drop')
                    ->default(false)
                    ->nullable(false)
                    ->change();
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('shipments') &&
            Schema::hasColumn('shipments', 'packet_products')
        ) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropColumn('packet_products');
            });
        }
    }
};