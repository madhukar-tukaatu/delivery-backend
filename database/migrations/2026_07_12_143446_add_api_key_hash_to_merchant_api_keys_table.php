<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchant_api_keys')) {
            Schema::table('merchant_api_keys', function (Blueprint $table) {
                if (!Schema::hasColumn('merchant_api_keys', 'merchant_id')) {
                    $table->unsignedBigInteger('merchant_id')->nullable()->index();
                }

                if (!Schema::hasColumn('merchant_api_keys', 'name')) {
                    $table->string('name')->nullable();
                }

                if (!Schema::hasColumn('merchant_api_keys', 'api_key_hash')) {
                    $table->string('api_key_hash')->nullable()->unique();
                }

                if (!Schema::hasColumn('merchant_api_keys', 'is_active')) {
                    $table->boolean('is_active')->default(true);
                }

                if (!Schema::hasColumn('merchant_api_keys', 'last_used_at')) {
                    $table->timestamp('last_used_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('merchant_api_keys')) {
            Schema::table('merchant_api_keys', function (Blueprint $table) {
                if (Schema::hasColumn('merchant_api_keys', 'api_key_hash')) {
                    $table->dropColumn('api_key_hash');
                }
            });
        }
    }
};