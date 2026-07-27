<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'branch_transfer_rate_tiers',
            function (Blueprint $table): void {
                $table->id();

                $table->string(
                    'service_type',
                    50
                )->default('standard');

                /*
                 * Number of physical transfer lanes:
                 *
                 * 0 = same responsible branch
                 * 1 = direct branch transfer
                 * 2 = one intermediate branch
                 * 3 = two intermediate branches
                 */
                $table->unsignedTinyInteger(
                    'transfer_count'
                );

                $table->decimal(
                    'base_rate',
                    10,
                    2
                );

                $table->boolean(
                    'is_active'
                )->default(true);

                $table->timestamps();

                $table->unique(
                    [
                        'service_type',
                        'transfer_count',
                    ],
                    'transfer_rate_service_count_unique'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'branch_transfer_rate_tiers'
        );
    }
};