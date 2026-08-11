<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_pickup_locations', function (Blueprint $table) {
            $table->string('name', 255)->nullable()->change();

            $table->text('address')->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Existing NULL values must be handled before
         * restoring NOT NULL constraints.
         */
        \DB::table('merchant_pickup_locations')
            ->whereNull('name')
            ->update([
                'name' => 'Pickup Location',
            ]);

        \DB::table('merchant_pickup_locations')
            ->whereNull('address')
            ->update([
                'address' => '',
            ]);

        Schema::table('merchant_pickup_locations', function (Blueprint $table) {
            $table->string('name', 255)->nullable(false)->change();

            $table->text('address')->nullable(false)->change();
        });
    }
};