<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-marketplace credentials Express sends when it asks that marketplace
 * to create a doorstep POD QR (X-Tukaatu-Key / X-Tukaatu-Secret).
 * Host stays on marketplaces.api_base_url. Not an .env setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplaces', function (Blueprint $table) {
            if (! Schema::hasColumn('marketplaces', 'api_key')) {
                $table->text('api_key')->nullable()->after('callback_secret');
            }
            if (! Schema::hasColumn('marketplaces', 'api_secret')) {
                $table->text('api_secret')->nullable()->after('api_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketplaces', function (Blueprint $table) {
            foreach (['api_secret', 'api_key'] as $col) {
                if (Schema::hasColumn('marketplaces', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
