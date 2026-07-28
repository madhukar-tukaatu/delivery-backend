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

        $columns = array_flip(
            Schema::getColumnListing('pricing_settings')
        );

        Schema::table(
            'pricing_settings',
            function (Blueprint $table) use ($columns): void {
                if (!isset($columns['scope_type'])) {
                    $table
                        ->string('scope_type', 30)
                        ->default('global')
                        ->after('id');
                }

                if (!isset($columns['branch_transfer_route_id'])) {
                    $table
                        ->foreignId('branch_transfer_route_id')
                        ->nullable()
                        ->after('scope_type')
                        ->constrained('branch_transfer_routes')
                        ->nullOnDelete();
                }
            }
        );

        DB::table('pricing_settings')
            ->whereNull('scope_type')
            ->update([
                'scope_type' => 'global',
                'branch_transfer_route_id' => null,
            ]);

        Schema::table(
            'pricing_settings',
            function (Blueprint $table): void {
                $table->index(
                    [
                        'scope_type',
                        'branch_transfer_route_id',
                        'is_active',
                    ],
                    'pricing_settings_scope_active_index'
                );
            }
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('pricing_settings')) {
            return;
        }

        Schema::table(
            'pricing_settings',
            function (Blueprint $table): void {
                $table->dropIndex(
                    'pricing_settings_scope_active_index'
                );

                $table->dropForeign([
                    'branch_transfer_route_id',
                ]);

                $table->dropColumn([
                    'scope_type',
                    'branch_transfer_route_id',
                ]);
            }
        );
    }
};
