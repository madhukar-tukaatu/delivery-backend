<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates table for tracking merchant change requests with granular service suspension.
     * When a merchant requests to change specific details (location, documents, bank info),
     * only those related services are suspended, others remain active.
     */
    public function up(): void
    {
        Schema::create('merchant_change_requests', function (Blueprint $table) {
            $table->id();
            
            /*
            |--------------------------------------------------------------------------
            | Foreign Keys
            |--------------------------------------------------------------------------
            */
            $table->foreignId('merchant_id')
                ->constrained('merchants')
                ->cascadeOnDelete();
            
            $table->foreignId('requested_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            
            $table->foreignId('approved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            
            /*
            |--------------------------------------------------------------------------
            | Change Type & Affected Services
            |--------------------------------------------------------------------------
            */
            
            // Type of change being requested
            $table->enum('change_type', [
                'location',           // Address, coordinates
                'documents',          // Business registration, PAN, owner ID, bank proof
                'bank_details',       // Bank name, account number, etc.
                'business_profile',   // Business name, type, PAN/VAT, website
                'contact_info',       // Contact person, phone
                'multiple',           // Multiple changes at once
            ])->index();
            
            // JSON array of affected services that will be suspended
            // e.g., ['pickup', 'delivery', 'settlement']
            $table->json('affected_services')->nullable();
            
            /*
            |--------------------------------------------------------------------------
            | Location Change Details (if applicable)
            |--------------------------------------------------------------------------
            */
            
            $table->string('new_address')->nullable();
            $table->string('new_city')->nullable();
            $table->string('new_area')->nullable();
            $table->decimal('new_latitude', 10, 7)->nullable();
            $table->decimal('new_longitude', 10, 7)->nullable();
            
            $table->string('previous_address')->nullable();
            $table->string('previous_city')->nullable();
            $table->string('previous_area')->nullable();
            $table->decimal('previous_latitude', 10, 7)->nullable();
            $table->decimal('previous_longitude', 10, 7)->nullable();
            
            /*
            |--------------------------------------------------------------------------
            | Business Profile Change Details (if applicable)
            |--------------------------------------------------------------------------
            */
            
            $table->string('new_business_name')->nullable();
            $table->string('previous_business_name')->nullable();
            
            $table->string('new_business_type')->nullable();
            $table->string('previous_business_type')->nullable();
            
            $table->string('new_pan_vat_number')->nullable();
            $table->string('previous_pan_vat_number')->nullable();
            
            $table->string('new_website_url')->nullable();
            $table->string('previous_website_url')->nullable();
            
            /*
            |--------------------------------------------------------------------------
            | Bank Details Change (if applicable)
            |--------------------------------------------------------------------------
            */
            
            $table->string('new_bank_name')->nullable();
            $table->string('previous_bank_name')->nullable();
            
            $table->string('new_bank_account_name')->nullable();
            $table->string('previous_bank_account_name')->nullable();
            
            $table->string('new_bank_account_number')->nullable();
            $table->string('previous_bank_account_number')->nullable();
            
            $table->string('new_bank_branch')->nullable();
            $table->string('previous_bank_branch')->nullable();
            
            /*
            |--------------------------------------------------------------------------
            | Contact Info Change (if applicable)
            |--------------------------------------------------------------------------
            */
            
            $table->string('new_contact_person')->nullable();
            $table->string('previous_contact_person')->nullable();
            
            $table->string('new_phone')->nullable();
            $table->string('previous_phone')->nullable();
            
            /*
            |--------------------------------------------------------------------------
            | Document Changes (if applicable)
            |--------------------------------------------------------------------------
            */
            
            // JSON array of new document file paths
            $table->json('new_documents')->nullable();
            // JSON array of old/superseded document IDs
            $table->json('superseded_document_ids')->nullable();
            
            /*
            |--------------------------------------------------------------------------
            | Branch Information
            |--------------------------------------------------------------------------
            */
            
            // Auto-detected branches from new coordinates (location change)
            $table->foreignId('suggested_branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();
            
            $table->foreignId('suggested_sub_branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();
            
            // Admin-assigned branches after approval
            $table->foreignId('approved_branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();
            
            $table->foreignId('approved_sub_branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();
            
            /*
            |--------------------------------------------------------------------------
            | Status & Workflow
            |--------------------------------------------------------------------------
            */
            
            $table->enum('status', [
                'pending',           // Initial request submitted
                'under_review',      // Admin reviewing
                'approved',          // Approved by admin
                'rejected',          // Rejected by admin
                'cancelled',         // Merchant cancelled the request
            ])->default('pending')->index();
            
            /*
            |--------------------------------------------------------------------------
            | Additional Details
            |--------------------------------------------------------------------------
            */
            
            // Reason/description for the changes
            $table->text('reason')->nullable();
            
            // Admin notes/remarks (for approvals or rejections)
            $table->text('admin_remarks')->nullable();
            
            // Rejection reason (only when status = rejected)
            $table->text('rejection_reason')->nullable();
            
            /*
            |--------------------------------------------------------------------------
            | Timestamps
            |--------------------------------------------------------------------------
            */
            
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('decision_at')->nullable();
            $table->timestamps();
            
            /*
            |--------------------------------------------------------------------------
            | Indexes
            |--------------------------------------------------------------------------
            */
            
            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'change_type']);
            $table->index(['merchant_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_change_requests');
    }
};
