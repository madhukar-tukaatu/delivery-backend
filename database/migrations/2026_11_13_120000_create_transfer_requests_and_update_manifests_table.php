<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transfer_requests')) {
            Schema::create('transfer_requests', function (Blueprint $table) {
                $table->id();
                $table->string('request_number', 40)->unique();
                $table->foreignId('origin_branch_id')->constrained('branches')->cascadeOnDelete();
                $table->foreignId('destination_hub_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->unsignedInteger('parcel_count')->default(0);
                $table->decimal('total_weight_kg', 10, 2)->default(0.00);
                $table->string('requested_vehicle_type', 30)->default('van');
                $table->string('urgency', 30)->default('normal'); // normal, high, critical_capacity_exceeded
                $table->boolean('threshold_exceeded')->default(false);
                $table->string('status', 25)->default('pending')->index(); // pending, scheduled, dispatched, completed, cancelled, rejected
                $table->text('notes')->nullable();
                $table->foreignId('dispatch_manifest_id')->nullable()->constrained('dispatch_manifests')->nullOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('handled_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('dispatch_manifests', function (Blueprint $table) {
            if (! Schema::hasColumn('dispatch_manifests', 'max_parcels')) {
                $table->unsignedInteger('max_parcels')->nullable()->after('expected_count');
            }
            if (! Schema::hasColumn('dispatch_manifests', 'max_weight_kg')) {
                $table->decimal('max_weight_kg', 10, 2)->nullable()->after('max_parcels');
            }
            if (! Schema::hasColumn('dispatch_manifests', 'estimated_hours')) {
                $table->decimal('estimated_hours', 5, 2)->nullable()->after('max_weight_kg');
            }
            if (! Schema::hasColumn('dispatch_manifests', 'estimated_arrival_at')) {
                $table->timestamp('estimated_arrival_at')->nullable()->after('estimated_hours');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dispatch_manifests', function (Blueprint $table) {
            foreach (['max_parcels', 'max_weight_kg', 'estimated_hours', 'estimated_arrival_at'] as $col) {
                if (Schema::hasColumn('dispatch_manifests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('transfer_requests');
    }
};
