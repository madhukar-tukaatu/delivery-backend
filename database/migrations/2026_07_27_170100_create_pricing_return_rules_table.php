<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_return_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('scenario_code', 80)->unique();
            $table->string('name');
            $table->decimal('base_rate_percentage', 8, 2)->default(0);
            $table->decimal('distance_rate_per_km', 10, 2)->default(0);
            $table->decimal('fixed_charge', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_return_rules');
    }
};
