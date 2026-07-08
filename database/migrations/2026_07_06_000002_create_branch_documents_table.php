<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branch_documents')) {
            return;
        }

        Schema::create('branch_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();
            $table->string('document_type', 80)->index();
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('disk', 50)->default('local');
            $table->string('status', 50)->default('pending')->index();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable()->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_documents');
    }
};
