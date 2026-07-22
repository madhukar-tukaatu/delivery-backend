<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'pricing_quote_items',
            function (Blueprint $table): void {
                $table->id();

                $table->foreignId('pricing_quote_id')
                    ->constrained('pricing_quotes')
                    ->cascadeOnDelete();

                /*
                 * Examples:
                 * base_price
                 * weight_charge
                 * distance_charge
                 * transfer_charge
                 * fragile_charge
                 * same_day_charge
                 * pickup_charge
                 * pod_charge
                 * tax
                 * discount
                 */
                $table->string('item_type', 50)
                    ->index();

                $table->string('code', 80)
                    ->nullable();

                $table->string('label', 150);

                $table->text('description')
                    ->nullable();

                $table->decimal('quantity', 14, 4)
                    ->default(1);

                $table->decimal('unit_rate', 14, 4)
                    ->default(0);

                $table->decimal('multiplier', 10, 4)
                    ->default(1);

                $table->decimal('amount', 14, 2)
                    ->default(0);

                $table->string('currency', 3)
                    ->default('NPR');

                /*
                 * References the rule that generated this item.
                 *
                 * Examples:
                 * branch_pricing_rule
                 * weight_rate_rule
                 * parcel_handling_rate
                 * pod_rate_rule
                 */
                $table->string('rule_type', 100)
                    ->nullable();

                $table->unsignedBigInteger('rule_id')
                    ->nullable();

                $table->json('metadata_json')
                    ->nullable();

                $table->unsignedInteger('sort_order')
                    ->default(0);

                $table->timestamps();

                $table->index([
                    'pricing_quote_id',
                    'item_type',
                ], 'pricing_quote_item_lookup_index');

                $table->index([
                    'rule_type',
                    'rule_id',
                ], 'pricing_quote_item_rule_index');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_quote_items');
    }
};