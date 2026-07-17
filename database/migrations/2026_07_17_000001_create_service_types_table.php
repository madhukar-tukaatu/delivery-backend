<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_types')) {
            return;
        }

        Schema::create('service_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 150);
            $table->unsignedInteger('estimated_hours')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_types');
    }
};