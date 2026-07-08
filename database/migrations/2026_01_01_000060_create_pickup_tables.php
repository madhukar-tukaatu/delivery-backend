<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pickup_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('pickup_sub_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pickup_name');
            $table->string('pickup_phone');
            $table->text('pickup_address');
            $table->string('pickup_city')->nullable();
            $table->string('pickup_area')->nullable();
            $table->dateTime('preferred_pickup_at')->nullable();
            $table->unsignedInteger('parcel_quantity')->default(1);
            $table->string('status')->default('requested')->index();
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('pickup_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pickup_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_attempts');
        Schema::dropIfExists('pickup_requests');
    }
};
