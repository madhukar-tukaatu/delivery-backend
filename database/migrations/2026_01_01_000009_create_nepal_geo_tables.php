<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nepal_provinces', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->string('capital')->nullable();
            $table->timestamps();
        });

        Schema::create('nepal_districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('province_id')->constrained('nepal_provinces')->cascadeOnDelete();
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->string('headquarter')->nullable();
            $table->timestamps();
        });

        Schema::create('nepal_municipalities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('district_id')->constrained('nepal_districts')->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('municipality');
            $table->timestamps();
            $table->unique(['district_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nepal_municipalities');
        Schema::dropIfExists('nepal_districts');
        Schema::dropIfExists('nepal_provinces');
    }
};
