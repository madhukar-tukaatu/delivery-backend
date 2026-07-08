<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('settlement_number')->unique();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->decimal('total_cod_collected', 12, 2)->default(0);
            $table->decimal('total_delivery_charges', 12, 2)->default(0);
            $table->decimal('total_cod_charges', 12, 2)->default(0);
            $table->decimal('return_charges', 12, 2)->default(0);
            $table->decimal('adjustments', 12, 2)->default(0);
            $table->decimal('final_payable_amount', 12, 2)->default(0);
            $table->string('status')->default('pending')->index();
            $table->string('payment_method')->nullable();
            $table->string('bank_reference_number')->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('merchant_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->decimal('cod_amount', 12, 2)->default(0);
            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->decimal('cod_charge', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_settlement_items');
        Schema::dropIfExists('merchant_settlements');
    }
};
