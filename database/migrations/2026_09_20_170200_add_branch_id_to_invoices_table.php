<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->after('merchant_id')->constrained('branches')->nullOnDelete();
            }
            if (! Schema::hasColumn('invoices', 'payer_type')) {
                $table->string('payer_type')->nullable()->after('type'); // merchant | branch
            }
            if (! Schema::hasColumn('invoices', 'payee_type')) {
                $table->string('payee_type')->nullable()->after('payer_type'); // branch | company
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }
            foreach (['payer_type', 'payee_type'] as $col) {
                if (Schema::hasColumn('invoices', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
