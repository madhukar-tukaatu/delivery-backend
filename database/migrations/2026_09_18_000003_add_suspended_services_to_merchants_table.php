<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds granular service suspension fields to merchants table.
     * Instead of suspending all services, we track which specific services
     * are suspended based on what details are pending approval.
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            // JSON array of suspended services
            // e.g., ['pickup', 'delivery', 'settlement', 'compliance']
            $table->json('suspended_services')
                ->default('[]')
                ->after('status')
                ->index();
            
            // Reference to the active change request
            $table->foreignId('pending_change_request_id')
                ->nullable()
                ->constrained('merchant_change_requests')
                ->nullOnDelete()
                ->after('suspended_services');
            
            // Timestamp when services were first suspended for current request
            $table->timestamp('services_suspended_at')
                ->nullable()
                ->after('pending_change_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropForeign(['pending_change_request_id']);
            $table->dropColumn([
                'suspended_services',
                'pending_change_request_id',
                'services_suspended_at',
            ]);
        });
    }
};
