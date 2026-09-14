<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchants') && !Schema::hasColumn('merchants', 'payment_qr_code')) {
            Schema::table('merchants', function (Blueprint $table) {
                // Stores a merchant-owned QR image URL or data URI.
                $table->text('payment_qr_code')->nullable();
            });
        }

        foreach (['pod_records', 'pod_collections'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            if (!Schema::hasColumn($tableName, 'payment_method')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('payment_method')->nullable();
                });
            }

            if (!Schema::hasColumn($tableName, 'payment_destination')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('payment_destination')->nullable();
                });
            }

            if (!Schema::hasColumn($tableName, 'payment_reference')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('payment_reference')->nullable();
                });
            }

            if (!Schema::hasColumn($tableName, 'payment_paid_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->timestamp('payment_paid_at')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('merchants') && Schema::hasColumn('merchants', 'payment_qr_code')) {
            Schema::table('merchants', function (Blueprint $table) {
                $table->dropColumn('payment_qr_code');
            });
        }

        foreach (['pod_records', 'pod_collections'] as $tableName) {
            if (!Schema::hasTable($tableName)) {
                continue;
            }

            $columns = array_values(array_filter([
                Schema::hasColumn($tableName, 'payment_method') ? 'payment_method' : null,
                Schema::hasColumn($tableName, 'payment_destination') ? 'payment_destination' : null,
                Schema::hasColumn($tableName, 'payment_reference') ? 'payment_reference' : null,
                Schema::hasColumn($tableName, 'payment_paid_at') ? 'payment_paid_at' : null,
            ]));

            if ($columns) {
                Schema::table($tableName, function (Blueprint $table) use ($columns) {
                    $table->dropColumn($columns);
                });
            }
        }
    }
};
