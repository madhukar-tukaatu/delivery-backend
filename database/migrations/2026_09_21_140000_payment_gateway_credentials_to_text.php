<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_gateway_accounts')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE payment_gateway_accounts MODIFY credentials TEXT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE payment_gateway_accounts ALTER COLUMN credentials TYPE TEXT');
        }
        // sqlite: leave as-is (affinity is flexible)
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_gateway_accounts')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE payment_gateway_accounts MODIFY credentials JSON NULL');
        }
    }
};
