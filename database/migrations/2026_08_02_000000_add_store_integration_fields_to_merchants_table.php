<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            // Existing public merchants automatically remain public website merchants.
            $table->string('application_source', 50)
                ->default('public_website')
                ->index();

            $table->string('application_number', 150)
                ->nullable()
                ->unique();

            $table->string('external_store_id', 150)
                ->nullable()
                ->index();

            $table->string('external_platform', 100)
                ->nullable();

            $table->string('store_category', 150)
                ->nullable();

            $table->string('store_url', 500)
                ->nullable();

            $table->string('registration_number', 100)
                ->nullable()
                ->index();

            $table->json('requested_services')
                ->nullable();

            $table->json('approved_services')
                ->nullable();

            $table->json('integration_payload')
                ->nullable();

            $table->string('integration_status', 50)
                ->nullable()
                ->index();

            $table->text('integration_callback_url')
                ->nullable();

            $table->text('integration_callback_secret')
                ->nullable();

            $table->string('integration_callback_status', 50)
                ->nullable();

            $table->timestamp('submitted_at')
                ->nullable();

            $table->timestamp('integration_approved_at')
                ->nullable();

            $table->timestamp('integration_callback_sent_at')
                ->nullable();

            $table->text('integration_callback_error')
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn([
                'application_source',
                'application_number',
                'external_store_id',
                'external_platform',
                'store_category',
                'store_url',
                'registration_number',
                'requested_services',
                'approved_services',
                'integration_payload',
                'integration_status',
                'integration_callback_url',
                'integration_callback_secret',
                'integration_callback_status',
                'submitted_at',
                'integration_approved_at',
                'integration_callback_sent_at',
                'integration_callback_error',
            ]);
        });
    }
};
