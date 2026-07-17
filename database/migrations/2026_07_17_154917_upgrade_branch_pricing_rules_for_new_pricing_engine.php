<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('branch_pricing_rules')) {
            return;
        }

        Schema::table('branch_pricing_rules', function (Blueprint $table): void {
            if (!Schema::hasColumn('branch_pricing_rules', 'merchant_id')) {
                $table->unsignedBigInteger('merchant_id')
                    ->nullable()
                    ->after('service_type_id');

                $table->index(
                    'merchant_id',
                    'bpr_merchant_idx'
                );
            }

            if (!Schema::hasColumn('branch_pricing_rules', 'charge_type')) {
                $table->string('charge_type', 30)
                    ->nullable()
                    ->after('merchant_id');
            }

            if (!Schema::hasColumn('branch_pricing_rules', 'base_fee')) {
                $table->decimal('base_fee', 12, 2)
                    ->default(0);
            }

            if (
                !Schema::hasColumn(
                    'branch_pricing_rules',
                    'additional_distance_unit_km'
                )
            ) {
                $table->decimal(
                    'additional_distance_unit_km',
                    10,
                    3
                )->default(1);
            }

            if (
                !Schema::hasColumn(
                    'branch_pricing_rules',
                    'additional_distance_fee'
                )
            ) {
                $table->decimal(
                    'additional_distance_fee',
                    12,
                    2
                )->default(0);
            }

            if (
                !Schema::hasColumn(
                    'branch_pricing_rules',
                    'maximum_radius_km'
                )
            ) {
                $table->decimal(
                    'maximum_radius_km',
                    10,
                    3
                )->nullable();
            }
        });

        Schema::table('branch_pricing_rules', function (Blueprint $table): void {
            $table->index(
                [
                    'branch_id',
                    'service_type_id',
                    'merchant_id',
                    'charge_type',
                ],
                'bpr_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('branch_pricing_rules')) {
            return;
        }

        Schema::table('branch_pricing_rules', function (Blueprint $table): void {
            $table->dropIndex('bpr_lookup_idx');

            if (Schema::hasColumn('branch_pricing_rules', 'merchant_id')) {
                $table->dropIndex('bpr_merchant_idx');
            }

            $columns = [];

            foreach ([
                'merchant_id',
                'charge_type',
                'base_fee',
                'additional_distance_unit_km',
                'additional_distance_fee',
                'maximum_radius_km',
            ] as $column) {
                if (Schema::hasColumn('branch_pricing_rules', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};