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
        // On a fresh database branch_transfer_routes is created later; the column
        // is then added by 2026_09_09_000050 / 2026_09_10.
        if (
            !Schema::hasTable('branch_transfer_routes')
            || Schema::hasColumn('branch_transfer_routes', 'transit_branch_ids')
        ) {
            return;
        }

        Schema::table('branch_transfer_routes', function (Blueprint $table): void {
            $column = $table->json('transit_branch_ids')
                ->nullable()
                ->comment('Array of transit branch IDs in order (for accounting/commissions)');

            if (Schema::hasColumn('branch_transfer_routes', 'transit_count')) {
                $column->after('transit_count');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branch_transfer_routes', function (Blueprint $table): void {
            $table->dropColumn('transit_branch_ids');
        });
    }
};
