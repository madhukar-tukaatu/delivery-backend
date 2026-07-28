<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pricing_settings')) {
            return;
        }

        if (
            !Schema::hasColumn(
                'pricing_settings',
                'scope_type'
            )
        ) {
            Schema::table(
                'pricing_settings',
                function (Blueprint $table): void {
                    $table
                        ->string('scope_type', 30)
                        ->default('global')
                        ->after('id');
                }
            );
        }

        if (
            !Schema::hasColumn(
                'pricing_settings',
                'branch_transfer_route_id'
            )
        ) {
            Schema::table(
                'pricing_settings',
                function (Blueprint $table): void {
                    $table
                        ->foreignId(
                            'branch_transfer_route_id'
                        )
                        ->nullable()
                        ->after('scope_type')
                        ->constrained(
                            'branch_transfer_routes'
                        )
                        ->nullOnDelete();

                    $table->index(
                        [
                            'scope_type',
                            'branch_transfer_route_id',
                            'is_active',
                        ],
                        'pricing_settings_route_scope_idx'
                    );
                }
            );
        }

        DB::table('pricing_settings')
            ->whereNull('scope_type')
            ->update([
                'scope_type' => 'global',
                'branch_transfer_route_id' => null,
            ]);
    }

    public function down(): void
    {
        /*
         * Keep pricing history during rollback.
         */
    }
};