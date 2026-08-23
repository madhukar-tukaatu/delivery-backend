<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }

        if (!Schema::hasColumn('shipments', 'self_drop')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table) {
            $table->boolean('self_drop')
                ->nullable()
                ->default(null)
                ->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }

        if (!Schema::hasColumn('shipments', 'self_drop')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table) {
            $table->boolean('self_drop')
                ->default(false)
                ->nullable(false)
                ->change();
        });
    }
};