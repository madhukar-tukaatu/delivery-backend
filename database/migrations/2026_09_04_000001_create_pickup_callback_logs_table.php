<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_callback_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pickup_request_id')
                ->constrained('pickup_requests')
                ->cascadeOnDelete();

            $table->foreignId('merchant_id')
                ->nullable()
                ->constrained('merchants')
                ->nullOnDelete();

            $table->foreignId('shipment_id')
                ->nullable()
                ->constrained('shipments')
                ->nullOnDelete();

            $table->string('event');
            $table->string('event_id')->nullable();

            $table->string('callback_url')->nullable();

            $table->json('payload')->nullable();

            $table->string('status')->default('pending');

            $table->unsignedInteger('attempt_count')->default(0);

            $table->integer('response_status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            $table->index(
                ['pickup_request_id', 'status'],
                'pickup_cb_pickup_status_idx'
            );

            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_callback_logs');
    }
};
