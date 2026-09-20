<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type'); // company | branch
            $table->unsignedBigInteger('owner_id')->nullable(); // null for company
            $table->string('gateway'); // hamropay | esewa | khalti | connectips
            $table->string('label')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->json('credentials')->nullable(); // encrypted JSON blob
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->unique(['owner_type', 'owner_id', 'gateway', 'label'], 'pga_owner_gateway_label_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_accounts');
    }
};
