<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table): void {
            $table->decimal('local_express_multiplier', 10, 4)->default(1.2000)->after('local_same_day_multiplier');
            $table->decimal('transfer_express_multiplier', 10, 4)->default(1.3000)->after('transfer_same_day_multiplier');
            $table->boolean('express_enabled')->default(true)->after('same_day_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table): void {
            $table->dropColumn(['local_express_multiplier', 'transfer_express_multiplier', 'express_enabled']);
        });
    }
};
