<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'sent_at')) {
                $table->timestamp('sent_at')->nullable()->after('billed_to_email');
            }
            if (! Schema::hasColumn('invoices', 'manual_checked_at')) {
                $table->timestamp('manual_checked_at')->nullable()->after('sent_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'manual_checked_at')) {
                $table->dropColumn('manual_checked_at');
            }
            if (Schema::hasColumn('invoices', 'sent_at')) {
                $table->dropColumn('sent_at');
            }
        });
    }
};
