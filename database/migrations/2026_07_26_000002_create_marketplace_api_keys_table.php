<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('marketplace_api_keys')) {
            Schema::create('marketplace_api_keys', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('marketplace_id')
                    ->constrained('marketplaces')
                    ->cascadeOnDelete();
                $table->string('name')->default('Default pricing key');
                $table->string('key_prefix', 32)->index();
                $table->string('key_hash', 64)->unique();
                $table->text('secret_encrypted');
                $table->json('scopes')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_api_keys');
    }
};
