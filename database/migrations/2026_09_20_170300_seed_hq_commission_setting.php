<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $exists = DB::table('settings')->where('key', 'commission.hq.percent')->exists();
        if (! $exists) {
            DB::table('settings')->insert([
                'key' => 'commission.hq.percent',
                'value' => (string) env('HQ_COMMISSION_PERCENT', 5),
                'type' => 'number',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', 'commission.hq.percent')->delete();
        }
    }
};
