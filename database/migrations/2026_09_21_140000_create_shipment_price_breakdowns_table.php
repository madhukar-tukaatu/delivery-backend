<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shipment_price_breakdowns')) {
            return;
        }

        Schema::create('shipment_price_breakdowns', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('shipment_id')
                ->constrained('shipments')
                ->cascadeOnDelete();

            $table->unsignedBigInteger('pricing_quote_id')
                ->nullable()
                ->index();

            $table->decimal('base_pickup_fee', 14, 2)->default(0);
            $table->decimal('base_delivery_fee', 14, 2)->default(0);
            $table->decimal('base_transfer_fee', 14, 2)->default(0);

            $table->decimal('pickup_distance_km', 10, 3)->nullable();
            $table->decimal('pickup_extra_km', 10, 3)->nullable();
            $table->decimal('pickup_extra_charge', 14, 2)->default(0);

            $table->decimal('delivery_distance_km', 10, 3)->nullable();
            $table->decimal('delivery_extra_km', 10, 3)->nullable();
            $table->decimal('delivery_extra_charge', 14, 2)->default(0);

            $table->decimal('weight_charge', 14, 2)->default(0);
            $table->decimal('pod_fee', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('final_price', 14, 2)->default(0);

            $table->json('snapshot_json')->nullable();

            $table->timestamps();

            $table->unique('shipment_id', 'shipment_price_breakdowns_shipment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_price_breakdowns');
    }
};