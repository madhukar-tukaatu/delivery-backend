<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Store pending email during verification
            $table->string('pending_email')->nullable()->after('email');
            // Token for email verification link
            $table->string('email_change_token')->nullable()->unique()->after('pending_email');
            // Expiration time for the verification token
            $table->timestamp('email_change_token_expires_at')->nullable()->after('email_change_token');
            // Track when the last email change occurred
            $table->timestamp('last_email_changed_at')->nullable()->after('email_change_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'pending_email',
                'email_change_token',
                'email_change_token_expires_at',
                'last_email_changed_at',
            ]);
        });
    }
};
