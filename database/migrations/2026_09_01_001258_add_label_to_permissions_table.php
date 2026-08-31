<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('permissions', 'label')) {
            Schema::table('permissions', function (Blueprint $table): void {
                $table->string('label')->nullable()->after('group');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('permissions', 'label')) {
            Schema::table('permissions', function (Blueprint $table): void {
                $table->dropColumn('label');
            });
        }
    }
};