<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pricing_settings')) {
            return;
        }

        Schema::table('pricing_settings', function (Blueprint $table): void {
            if (!Schema::hasColumn('pricing_settings', 'max_weight_kg')) {
                $table->decimal('max_weight_kg', 10, 2)
                    ->nullable()
                    ->default(30.00)
                    ->after('base_weight_kg');
            }

            if (!Schema::hasColumn('pricing_settings', 'weight_limit_enabled')) {
                $table->boolean('weight_limit_enabled')
                    ->default(false)
                    ->after('max_weight_kg');
            }

            if (!Schema::hasColumn('pricing_settings', 'min_delivery_charge')) {
                $table->decimal('min_delivery_charge', 10, 2)
                    ->default(0.00)
                    ->after('small_pickup_charge');
            }

            if (!Schema::hasColumn('pricing_settings', 'max_delivery_charge')) {
                $table->decimal('max_delivery_charge', 10, 2)
                    ->nullable()
                    ->default(null)
                    ->after('min_delivery_charge');
            }

            if (!Schema::hasColumn('pricing_settings', 'delivery_charge_bounds_enabled')) {
                $table->boolean('delivery_charge_bounds_enabled')
                    ->default(false)
                    ->after('max_delivery_charge');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('pricing_settings')) {
            return;
        }

        $columns = array_values(array_filter([
            'max_weight_kg',
            'weight_limit_enabled',
            'min_delivery_charge',
            'max_delivery_charge',
            'delivery_charge_bounds_enabled',
        ], static fn (string $column): bool => Schema::hasColumn('pricing_settings', $column)));

        if ($columns !== []) {
            Schema::table('pricing_settings', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
