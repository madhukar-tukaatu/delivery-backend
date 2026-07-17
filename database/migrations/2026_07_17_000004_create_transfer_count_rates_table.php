<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transfer_count_rates')) {
            return;
        }

        Schema::create(
            'transfer_count_rates',
            function (Blueprint $table): void {
                $table->id();

                $table->unsignedInteger('transfer_count');

                $table->decimal('rate', 12, 2);

                $table->unsignedBigInteger('service_type_id')
                    ->nullable();

                $table->unsignedBigInteger('merchant_id')
                    ->nullable();

                $table->boolean('is_active')
                    ->default(true);

                $table->timestamps();

                $table->index(
                    'transfer_count',
                    'tcr_count_idx'
                );

                $table->index(
                    'service_type_id',
                    'tcr_service_idx'
                );

                $table->index(
                    'merchant_id',
                    'tcr_merchant_idx'
                );

                $table->index(
                    [
                        'transfer_count',
                        'service_type_id',
                        'merchant_id',
                        'is_active',
                    ],
                    'tcr_lookup_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'transfer_count_rates'
        );
    }
};