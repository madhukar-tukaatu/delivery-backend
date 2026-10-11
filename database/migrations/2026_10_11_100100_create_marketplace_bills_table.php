<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One bill a branch sends to one marketplace for a set of company
 * (marketplace free-delivery) invoices. Lines = invoices.marketplace_bill_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketplace_bills')) {
            return;
        }

        Schema::create('marketplace_bills', function (Blueprint $table) {
            $table->id();
            $table->string('bill_number', 64)->unique();
            $table->unsignedBigInteger('branch_id')->index();
            $table->unsignedBigInteger('marketplace_id')->index();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->unsignedInteger('invoice_count')->default(0);
            // draft | sent | paid | reopened | cancelled
            $table->string('status', 16)->default('draft')->index();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->string('sent_to_email', 191)->nullable();
            $table->unsignedInteger('send_count')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->string('paid_by_type', 16)->nullable(); // admin | branch | marketplace | hamropay
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->decimal('paid_amount', 12, 2)->nullable();
            $table->string('payment_method', 64)->nullable();
            $table->string('payment_reference', 191)->nullable();
            $table->string('payment_note', 500)->nullable();
            $table->string('payment_request_id', 191)->nullable(); // X-Tukaatu-Request-Id of the marketplace pay call
            $table->string('hamropay_txn_id', 64)->nullable();     // last MBPAY- session started
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->json('payment_history')->nullable(); // earlier payments that were reported not received
            $table->timestamps();

            $table->index(['branch_id', 'marketplace_id', 'status'], 'mb_branch_mp_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_bills');
    }
};
