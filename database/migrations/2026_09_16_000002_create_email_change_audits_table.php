<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_change_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->onDelete('set null');
            // Old email address
            $table->string('old_email');
            // New email address
            $table->string('new_email');
            // Status: pending_verification, verified, rejected
            $table->string('status')->default('pending_verification')->index();
            // Who initiated the change (admin user ID)
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            // Verification token
            $table->string('verification_token')->nullable()->unique();
            // When verification expires
            $table->timestamp('verification_expires_at')->nullable();
            // When verification was completed
            $table->timestamp('verified_at')->nullable();
            // When the email change was actually applied
            $table->timestamp('applied_at')->nullable();
            // Reason for rejection if rejected
            $table->text('rejection_reason')->nullable();
            // IP address of the change requester
            $table->string('ip_address')->nullable();
            // User agent of the change requester
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_change_audits');
    }
};
