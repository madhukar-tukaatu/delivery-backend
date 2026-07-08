<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('type')->index(); // main_branch, branch, sub_branch
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('city')->nullable()->index();
            $table->string('area')->nullable()->index();
            $table->text('address')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });

        Schema::create('branch_service_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('city')->index();
            $table->string('area')->nullable()->index();
            $table->string('postal_code')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_service_areas');
        Schema::dropIfExists('branches');
    }
};
