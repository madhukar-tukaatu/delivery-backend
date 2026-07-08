<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_manifests', function (Blueprint $table) {
            $table->id();
            $table->string('manifest_number')->unique();
            $table->foreignId('from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('from_sub_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('to_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('to_sub_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('vehicle_number')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('seal_number')->nullable();
            $table->string('status')->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dispatch_manifest_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispatch_manifest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('added')->index();
            $table->timestamps();
            $table->unique(['dispatch_manifest_id', 'shipment_id'], 'manifest_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispatch_manifest_items');
        Schema::dropIfExists('dispatch_manifests');
    }
};
