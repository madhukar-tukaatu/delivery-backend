<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table): void {
            if (!Schema::hasColumn('pricing_settings', 'name')) {
                $table->string('name')->nullable();
            }

            if (!Schema::hasColumn('pricing_settings', 'included_weight_kg')) {
                $table->decimal('included_weight_kg', 10, 3)->default(1.500);
            }

            if (!Schema::hasColumn('pricing_settings', 'same_branch_excess_weight_rate')) {
                $table->decimal('same_branch_excess_weight_rate', 10, 2)->default(20.00);
            }

            if (!Schema::hasColumn('pricing_settings', 'transfer_branch_excess_weight_rate')) {
                $table->decimal('transfer_branch_excess_weight_rate', 10, 2)->default(30.00);
            }

            if (!Schema::hasColumn('pricing_settings', 'included_delivery_distance_km')) {
                $table->decimal('included_delivery_distance_km', 10, 2)->default(5.00);
            }

            if (!Schema::hasColumn('pricing_settings', 'extra_distance_rate_per_km')) {
                $table->decimal('extra_distance_rate_per_km', 10, 2)->default(6.00);
            }

            if (!Schema::hasColumn('pricing_settings', 'fragile_multiplier')) {
                $table->decimal('fragile_multiplier', 8, 4)->default(1.0500);
            }

            if (!Schema::hasColumn('pricing_settings', 'same_day_same_branch_multiplier')) {
                $table->decimal('same_day_same_branch_multiplier', 8, 4)->default(1.5000);
            }

            if (!Schema::hasColumn('pricing_settings', 'same_day_transfer_branch_multiplier')) {
                $table->decimal('same_day_transfer_branch_multiplier', 8, 4)->default(2.0000);
            }

            if (!Schema::hasColumn('pricing_settings', 'same_day_cutoff_time')) {
                $table->string('same_day_cutoff_time', 5)->default('12:00');
            }

            if (!Schema::hasColumn('pricing_settings', 'minimum_pickup_packet_count')) {
                $table->unsignedInteger('minimum_pickup_packet_count')->default(3);
            }

            if (!Schema::hasColumn('pricing_settings', 'low_packet_pickup_charge')) {
                $table->decimal('low_packet_pickup_charge', 10, 2)->default(50.00);
            }

            if (!Schema::hasColumn('pricing_settings', 'vat_percentage')) {
                $table->decimal('vat_percentage', 8, 4)->default(13.0000);
            }

            if (!Schema::hasColumn('pricing_settings', 'vat_inclusive')) {
                $table->boolean('vat_inclusive')->default(true);
            }

            if (!Schema::hasColumn('pricing_settings', 'quote_validity_minutes')) {
                $table->unsignedInteger('quote_validity_minutes')->default(30);
            }

            if (!Schema::hasColumn('pricing_settings', 'effective_from')) {
                $table->timestamp('effective_from')->nullable();
            }

            if (!Schema::hasColumn('pricing_settings', 'effective_until')) {
                $table->timestamp('effective_until')->nullable();
            }

            if (!Schema::hasColumn('pricing_settings', 'change_reason')) {
                $table->text('change_reason')->nullable();
            }

            if (!Schema::hasColumn('pricing_settings', 'created_by')) {
                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('pricing_settings', 'updated_by')) {
                $table->foreignId('updated_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        $columns = [
            'name',
            'included_weight_kg',
            'same_branch_excess_weight_rate',
            'transfer_branch_excess_weight_rate',
            'included_delivery_distance_km',
            'extra_distance_rate_per_km',
            'fragile_multiplier',
            'same_day_same_branch_multiplier',
            'same_day_transfer_branch_multiplier',
            'same_day_cutoff_time',
            'minimum_pickup_packet_count',
            'low_packet_pickup_charge',
            'vat_percentage',
            'vat_inclusive',
            'quote_validity_minutes',
            'effective_from',
            'effective_until',
            'change_reason',
            'created_by',
            'updated_by',
        ];

        $existing = array_values(array_filter(
            $columns,
            static fn (string $column): bool =>
                Schema::hasColumn('pricing_settings', $column)
        ));

        if ($existing !== []) {
            Schema::table('pricing_settings', function (Blueprint $table) use ($existing): void {
                $table->dropColumn($existing);
            });
        }
    }
};
