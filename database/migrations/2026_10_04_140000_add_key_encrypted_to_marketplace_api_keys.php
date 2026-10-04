<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the full public marketplace API key encrypted so Express can
 * send it outbound (doorstep POD) while inbound auth still uses key_hash.
 * Existing rows stay null until an admin reissues the key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_api_keys')) {
            return;
        }

        if (Schema::hasColumn('marketplace_api_keys', 'key_encrypted')) {
            return;
        }

        Schema::table('marketplace_api_keys', function (Blueprint $table): void {
            $table->text('key_encrypted')->nullable()->after('key_hash');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('marketplace_api_keys')) {
            return;
        }

        if (! Schema::hasColumn('marketplace_api_keys', 'key_encrypted')) {
            return;
        }

        Schema::table('marketplace_api_keys', function (Blueprint $table): void {
            $table->dropColumn('key_encrypted');
        });
    }
};