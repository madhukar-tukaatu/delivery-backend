<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pod_payment_sessions')) {
            Schema::create('pod_payment_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
                $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
                $table->string('payment_session_id')->unique();
                $table->string('idempotency_key')->unique();
                $table->string('external_store_id')->index();
                $table->string('external_platform')->nullable();
                $table->string('merchant_order_id')->nullable()->index();
                $table->string('tracking_number')->index();
                $table->decimal('amount', 12, 2);
                $table->string('currency', 3)->default('NPR');
                $table->string('status')->default('pending')->index();
                $table->string('settlement_destination')->nullable();
                $table->string('provider_reference')->nullable();
                $table->text('qr_image_url')->nullable();
                $table->text('qr_payload')->nullable();
                $table->text('checkout_url')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('last_polled_at')->nullable();
                $table->string('last_event_id')->nullable()->index();
                $table->json('response_payload')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->index(['merchant_id', 'shipment_id', 'status']);
            });
        }

        foreach (['pod_records', 'pod_collections'] as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'payment_session_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('payment_session_id')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['pod_records', 'pod_collections'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'payment_session_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('payment_session_id');
                });
            }
        }

        Schema::dropIfExists('pod_payment_sessions');
    }
};
