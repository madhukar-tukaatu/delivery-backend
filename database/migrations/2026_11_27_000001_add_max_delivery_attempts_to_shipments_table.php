<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table): void {
            if (!Schema::hasColumn('shipments', 'max_delivery_attempts')) {
                $table->unsignedInteger('max_delivery_attempts')
                    ->default(3)
                    ->after('failed_attempts');
            }

            if (!Schema::hasColumn('shipments', 'return_reason')) {
                $table->string('return_reason', 500)
                    ->nullable()
                    ->after('cancel_reason');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table): void {
            if (Schema::hasColumn('shipments', 'max_delivery_attempts')) {
                $table->dropColumn('max_delivery_attempts');
            }
            if (Schema::hasColumn('shipments', 'return_reason')) {
                $table->dropColumn('return_reason');
            }
        });
    }
};
