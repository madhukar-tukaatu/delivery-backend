<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchants')) {
            Schema::table('merchants', function (Blueprint $table) {
                $this->addColumnIfMissing($table, 'city', 'string', ['nullable' => true, 'after' => 'address']);
                $this->addColumnIfMissing($table, 'area', 'string', ['nullable' => true, 'after' => 'city']);
                $this->addColumnIfMissing($table, 'pickup_address', 'text', ['nullable' => true, 'after' => 'area']);
                $this->addColumnIfMissing($table, 'pickup_city', 'string', ['nullable' => true, 'after' => 'pickup_address']);
                $this->addColumnIfMissing($table, 'pickup_area', 'string', ['nullable' => true, 'after' => 'pickup_city']);
                $this->addColumnIfMissing($table, 'pickup_lat', 'decimal', ['nullable' => true, 'precision' => 10, 'scale' => 7, 'after' => 'pickup_area']);
                $this->addColumnIfMissing($table, 'pickup_lng', 'decimal', ['nullable' => true, 'precision' => 10, 'scale' => 7, 'after' => 'pickup_lat']);
                $this->addColumnIfMissing($table, 'suggested_branch_id', 'unsignedBigInteger', ['nullable' => true, 'after' => 'pickup_lng']);
                $this->addColumnIfMissing($table, 'suggested_sub_branch_id', 'unsignedBigInteger', ['nullable' => true, 'after' => 'suggested_branch_id']);
                $this->addColumnIfMissing($table, 'verification_status', 'string', ['nullable' => true, 'default' => 'profile_pending', 'after' => 'status']);
                $this->addColumnIfMissing($table, 'verified_by', 'unsignedBigInteger', ['nullable' => true, 'after' => 'verification_status']);
                $this->addColumnIfMissing($table, 'verified_at', 'timestamp', ['nullable' => true, 'after' => 'verified_by']);
                $this->addColumnIfMissing($table, 'rejected_reason', 'text', ['nullable' => true, 'after' => 'verified_at']);
                $this->addColumnIfMissing($table, 'more_info_message', 'text', ['nullable' => true, 'after' => 'rejected_reason']);
                $this->addColumnIfMissing($table, 'bank_branch', 'string', ['nullable' => true, 'after' => 'bank_account_number']);
            });
        }

        if (!Schema::hasTable('merchant_documents')) {
            Schema::create('merchant_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
                $table->string('document_type');
                $table->string('file_path');
                $table->string('original_name')->nullable();
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->string('status')->default('pending');
                $table->text('remarks')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
                $table->index(['merchant_id', 'document_type']);
                $table->index(['status']);
            });
        }

        if (Schema::hasTable('merchant_pickup_locations')) {
            Schema::table('merchant_pickup_locations', function (Blueprint $table) {
                $this->addColumnIfMissing($table, 'latitude', 'decimal', ['nullable' => true, 'precision' => 10, 'scale' => 7, 'after' => 'address']);
                $this->addColumnIfMissing($table, 'longitude', 'decimal', ['nullable' => true, 'precision' => 10, 'scale' => 7, 'after' => 'latitude']);
                $this->addColumnIfMissing($table, 'suggested_branch_id', 'unsignedBigInteger', ['nullable' => true, 'after' => 'longitude']);
                $this->addColumnIfMissing($table, 'suggested_sub_branch_id', 'unsignedBigInteger', ['nullable' => true, 'after' => 'suggested_branch_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_documents');
    }

    private function addColumnIfMissing(Blueprint $table, string $name, string $type, array $options = []): void
    {
        if (Schema::hasColumn($table->getTable(), $name)) {
            return;
        }

        $column = match ($type) {
            'text' => $table->text($name),
            'timestamp' => $table->timestamp($name),
            'decimal' => $table->decimal($name, $options['precision'] ?? 10, $options['scale'] ?? 2),
            'unsignedBigInteger' => $table->unsignedBigInteger($name),
            default => $table->string($name),
        };

        if (($options['nullable'] ?? false) === true) {
            $column->nullable();
        }

        if (array_key_exists('default', $options)) {
            $column->default($options['default']);
        }

        if (!empty($options['after']) && Schema::hasColumn($table->getTable(), $options['after'])) {
            $column->after($options['after']);
        }
    }
};
