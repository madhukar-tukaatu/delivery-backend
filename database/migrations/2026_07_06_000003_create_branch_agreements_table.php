<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branch_agreements')) {
            return;
        }

        Schema::create('branch_agreements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->index();
            $table->string('agreement_type', 80)->index();
            $table->string('file_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('disk', 50)->default('local');
            $table->string('status', 50)->default('pending')->index();
            $table->date('signed_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->timestamps();

            $table->unique(['branch_id', 'agreement_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_agreements');
    }
};
