<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('merchant_settlements')) {
            return;
        }

        Schema::table('merchant_settlements', function (Blueprint $table) {
            if (! Schema::hasColumn('merchant_settlements', 'cash_path')) {
                $table->string('cash_path', 32)->default('after_deposit')->after('status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('merchant_settlements')) {
            return;
        }

        Schema::table('merchant_settlements', function (Blueprint $table) {
            if (Schema::hasColumn('merchant_settlements', 'cash_path')) {
                $table->dropColumn('cash_path');
            }
        });
    }
};
