<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('merchant_api_keys')) {
            return;
        }

        $duplicates = DB::table('merchant_api_keys')
            ->select('merchant_id')
            ->whereNotNull('merchant_id')
            ->groupBy('merchant_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('merchant_id');

        foreach ($duplicates as $merchantId) {
            $keepId = DB::table('merchant_api_keys')
                ->where('merchant_id', $merchantId)
                ->orderBy('id')
                ->value('id');

            DB::table('merchant_api_keys')
                ->where('merchant_id', $merchantId)
                ->where('id', '!=', $keepId)
                ->delete();
        }

        Schema::table('merchant_api_keys', function ($table) {
            $table->unique('merchant_id', 'merchant_api_keys_merchant_id_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('merchant_api_keys')) {
            return;
        }

        Schema::table('merchant_api_keys', function ($table) {
            $table->dropUnique('merchant_api_keys_merchant_id_unique');
        });
    }
};