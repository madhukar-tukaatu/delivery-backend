<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_commission_bills', function (Blueprint $table) {
            $table->id();
            $table->string('bill_number')->unique();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
            $table->decimal('delivery_charge_base', 12, 2)->default(0);
            $table->decimal('commission_rate', 8, 4)->default(0); // e.g. 5.0000 = 5%
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->string('currency', 8)->default('NPR');
            $table->string('status')->default('unpaid')->index(); // unpaid | processing | paid | waived
            $table->foreignId('settlement_id')->nullable(); // branch_commission_settlements later
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['shipment_id']); // one HQ commission line per delivery
            $table->index(['branch_id', 'status']);
        });

        Schema::create('branch_commission_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_number')->unique();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->decimal('total_commission', 12, 2)->default(0);
            $table->decimal('adjustments', 12, 2)->default(0);
            $table->decimal('final_payable_amount', 12, 2)->default(0);
            $table->string('status')->default('pending')->index(); // pending | paid
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->string('gateway')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('branch_commission_bills', function (Blueprint $table) {
            $table->foreign('settlement_id')
                ->references('id')
                ->on('branch_commission_settlements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('branch_commission_bills', function (Blueprint $table) {
            $table->dropForeign(['settlement_id']);
        });
        Schema::dropIfExists('branch_commission_bills');
        Schema::dropIfExists('branch_commission_settlements');
    }
};
