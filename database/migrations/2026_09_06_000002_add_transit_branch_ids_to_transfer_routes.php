<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add transit_branch_ids JSON column to track which branches are transited for accounting/commission purposes.
     * This denormalization allows quick access for account reconciliation without joins.
     */
    public function up(): void
    {
        if (Schema::hasColumn('branch_transfer_routes', 'transit_branch_ids')) {
            return;
        }

        Schema::table('branch_transfer_routes', function (Blueprint $table): void {
            $table->json('transit_branch_ids')
                ->nullable()
                ->after('transit_count')
                ->comment('Array of transit branch IDs in order (for accounting/commissions)');
        });
    }

    public function down(): void
    {
        Schema::table('branch_transfer_routes', function (Blueprint $table): void {
            $table->dropColumn('transit_branch_ids');
        });
    }
};
