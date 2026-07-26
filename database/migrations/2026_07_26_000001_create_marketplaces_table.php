<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('marketplaces')) {
            Schema::create('marketplaces', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('code', 100)->unique();
                $table->string('email')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplaces');
    }
};
