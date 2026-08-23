<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Remove the old one-shipment relationship only if it exists.
         *
         * Do not automatically drop shipment_id if your existing
         * application still depends on it elsewhere.
         *
         * We simply stop using it for the new workflow.
         */

        Schema::create('pickup_request_shipments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pickup_request_id')
                ->constrained('pickup_requests')
                ->cascadeOnDelete();

            $table->foreignId('shipment_id')
                ->constrained('shipments')
                ->cascadeOnDelete();

            $table->timestamp('added_at')->nullable();

            $table->foreignId('added_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('removed_at')->nullable();

            $table->foreignId('removed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('status', 40)
                ->default('pending');

            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->unique([
                'pickup_request_id',
                'shipment_id',
            ]);

            $table->index([
                'shipment_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_request_shipments');
    }
};