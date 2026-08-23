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
            Schema::hasColumn('shipments', 'receiver_address') &&
            ! Schema::hasColumn('shipments', 'delivery_address')
        ) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->renameColumn(
                    'receiver_address',
                    'delivery_address'
                );
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('shipments') &&
            Schema::hasColumn('shipments', 'delivery_address') &&
            ! Schema::hasColumn('shipments', 'receiver_address')
        ) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->renameColumn(
                    'delivery_address',
                    'receiver_address'
                );
            });
        }
    }
};