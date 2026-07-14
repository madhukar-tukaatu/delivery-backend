<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_api_keys', function (Blueprint $table) {
            if (!Schema::hasColumn('merchant_api_keys', 'api_secret_encrypted')) {
                $table->text('api_secret_encrypted')->nullable()->after('api_secret_hash');
            }

            if (!Schema::hasColumn('merchant_api_keys', 'secret_revealed_at')) {
                $table->timestamp('secret_revealed_at')->nullable()->after('last_used_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchant_api_keys', function (Blueprint $table) {
            if (Schema::hasColumn('merchant_api_keys', 'api_secret_encrypted')) {
                $table->dropColumn('api_secret_encrypted');
            }

            if (Schema::hasColumn('merchant_api_keys', 'secret_revealed_at')) {
                $table->dropColumn('secret_revealed_at');
            }
        });
    }
};