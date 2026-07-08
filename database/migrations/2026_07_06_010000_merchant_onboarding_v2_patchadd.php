<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('merchant_documents')) {
            Schema::create('merchant_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
                $table->string('document_type');
                $table->string('file_path');
                $table->string('original_name')->nullable();
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->string('disk')->default('local');
                $table->string('status')->default('pending');
                $table->text('remarks')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();

                $table->unique(['merchant_id', 'document_type']);
            });

            return;
        }

        Schema::table('merchant_documents', function (Blueprint $table) {
            if (!Schema::hasColumn('merchant_documents', 'file_path')) {
                $table->string('file_path')->nullable()->after('document_type');
            }

            if (!Schema::hasColumn('merchant_documents', 'original_name')) {
                $table->string('original_name')->nullable()->after('file_path');
            }

            if (!Schema::hasColumn('merchant_documents', 'mime_type')) {
                $table->string('mime_type')->nullable()->after('original_name');
            }

            if (!Schema::hasColumn('merchant_documents', 'size_bytes')) {
                $table->unsignedBigInteger('size_bytes')->nullable()->after('mime_type');
            }

            if (!Schema::hasColumn('merchant_documents', 'disk')) {
                $table->string('disk')->default('local')->after('size_bytes');
            }

            if (!Schema::hasColumn('merchant_documents', 'status')) {
                $table->string('status')->default('pending')->after('disk');
            }

            if (!Schema::hasColumn('merchant_documents', 'remarks')) {
                $table->text('remarks')->nullable()->after('status');
            }

            if (!Schema::hasColumn('merchant_documents', 'verified_by')) {
                $table->foreignId('verified_by')->nullable()->after('remarks')->constrained('users')->nullOnDelete();
            }

            if (!Schema::hasColumn('merchant_documents', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('verified_by');
            }
        });
    }

    public function down(): void
    {
        //
    }
};