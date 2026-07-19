<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_cards', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('rate_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('destination_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('origin_city')->nullable()->index();
            $table->string('destination_city')->nullable()->index();
            $table->decimal('min_weight', 10, 2)->default(0);
            $table->decimal('max_weight', 10, 2)->default(1);
            $table->decimal('base_charge', 12, 2)->default(0);
            $table->decimal('extra_per_kg', 12, 2)->default(0);
            $table->decimal('pod_percent', 5, 2)->default(0);
            $table->decimal('pod_fixed', 12, 2)->default(0);
            $table->decimal('return_charge', 12, 2)->default(0);
            $table->string('estimated_delivery_time')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('merchant_rate_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rate_card_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_rate_cards');
        Schema::dropIfExists('rate_rules');
        Schema::dropIfExists('rate_cards');
    }
};
