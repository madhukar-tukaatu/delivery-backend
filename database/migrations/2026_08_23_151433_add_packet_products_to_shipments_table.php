<?php

use Illuminate\Database\Migrations\Migration;

/**
 * This file previously contained only the text
 * "fix_shipments_self_drop_and_packet_products" (no PHP), which breaks
 * `php artisan migrate` on a fresh database. The packet_products / self_drop
 * changes are applied idempotently by
 * 2026_08_23_163231_fix_shipments_self_drop_and_packet_products, so this
 * migration is intentionally a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        //
    }

    public function down(): void
    {
        //
    }
};
