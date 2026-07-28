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

        $existing = array_flip(
            Schema::getColumnListing('pricing_settings')
        );

        Schema::table(
            'pricing_settings',
            function (Blueprint $table) use ($existing): void {
                if (!isset($existing['name'])) {
                    $table->string('name')->nullable();
                }

                if (!isset($existing['base_weight_kg'])) {
                    $table
                        ->decimal('base_weight_kg', 10, 2)
                        ->default(1.50);
                }

                if (!isset($existing['base_distance_km'])) {
                    $table
                        ->decimal('base_distance_km', 10, 2)
                        ->default(5.00);
                }

                if (!isset($existing['local_extra_weight_rate'])) {
                    $table
                        ->decimal('local_extra_weight_rate', 10, 2)
                        ->default(20.00);
                }

                if (!isset($existing['transfer_extra_weight_rate'])) {
                    $table
                        ->decimal('transfer_extra_weight_rate', 10, 2)
                        ->default(30.00);
                }

                if (!isset($existing['extra_distance_rate'])) {
                    $table
                        ->decimal('extra_distance_rate', 10, 2)
                        ->default(6.00);
                }

                if (!isset($existing['fragile_multiplier'])) {
                    $table
                        ->decimal('fragile_multiplier', 8, 4)
                        ->default(1.0500);
                }

                if (!isset($existing['local_same_day_multiplier'])) {
                    $table
                        ->decimal('local_same_day_multiplier', 8, 4)
                        ->default(1.5000);
                }

                if (!isset($existing['transfer_same_day_multiplier'])) {
                    $table
                        ->decimal('transfer_same_day_multiplier', 8, 4)
                        ->default(2.0000);
                }

                if (!isset($existing['same_day_cutoff_time'])) {
                    $table
                        ->string('same_day_cutoff_time', 5)
                        ->default('12:00');
                }

                if (!isset($existing['minimum_free_pickup_packets'])) {
                    $table
                        ->unsignedInteger('minimum_free_pickup_packets')
                        ->default(3);
                }

                if (!isset($existing['small_pickup_charge'])) {
                    $table
                        ->decimal('small_pickup_charge', 10, 2)
                        ->default(50.00);
                }

                if (!isset($existing['vat_percentage'])) {
                    $table
                        ->decimal('vat_percentage', 8, 2)
                        ->default(13.00);
                }

                if (!isset($existing['vat_inclusive'])) {
                    $table
                        ->boolean('vat_inclusive')
                        ->default(true);
                }

                if (!isset($existing['weight_rounding'])) {
                    $table
                        ->string('weight_rounding', 20)
                        ->default('none');
                }

                if (!isset($existing['distance_rounding'])) {
                    $table
                        ->string('distance_rounding', 20)
                        ->default('none');
                }

                if (!isset($existing['money_rounding'])) {
                    $table
                        ->string('money_rounding', 20)
                        ->default('round');
                }

                if (!isset($existing['fragile_enabled'])) {
                    $table
                        ->boolean('fragile_enabled')
                        ->default(true);
                }

                if (!isset($existing['same_day_enabled'])) {
                    $table
                        ->boolean('same_day_enabled')
                        ->default(true);
                }

                if (!isset($existing['pickup_charge_enabled'])) {
                    $table
                        ->boolean('pickup_charge_enabled')
                        ->default(true);
                }

                if (!isset($existing['vat_enabled'])) {
                    $table
                        ->boolean('vat_enabled')
                        ->default(true);
                }

                if (!isset($existing['is_active'])) {
                    $table
                        ->boolean('is_active')
                        ->default(false);
                }

                if (!isset($existing['created_by'])) {
                    $table
                        ->unsignedBigInteger('created_by')
                        ->nullable();
                }

                if (!isset($existing['updated_by'])) {
                    $table
                        ->unsignedBigInteger('updated_by')
                        ->nullable();
                }
            }
        );
    }

    public function down(): void
    {
        /*
         * Intentionally non-destructive.
         *
         * Pricing history should not be deleted automatically
         * during a rollback.
         */
    }
};