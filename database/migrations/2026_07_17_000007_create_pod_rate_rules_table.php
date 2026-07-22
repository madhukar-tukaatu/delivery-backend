<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'pod_rate_rules',
            function (Blueprint $table): void {
                $table->id();

                $table->string('name', 100);

                /*
                 * Supported examples:
                 * prepaid
                 * pod
                 * cod
                 * partial
                 */
                $table->string('payment_type', 30)
                    ->default('pod')
                    ->index('prr_payment_type_idx');

                $table->decimal(
                    'minimum_amount',
                    14,
                    2
                )->default(0);

                $table->decimal(
                    'maximum_amount',
                    14,
                    2
                )->nullable();

                $table->decimal(
                    'flat_charge',
                    12,
                    2
                )->default(0);

                /*
                 * Example:
                 * 1.00 = 1%
                 * 1.50 = 1.5%
                 */
                $table->decimal(
                    'percentage_rate',
                    8,
                    4
                )->default(0);

                $table->decimal(
                    'minimum_charge',
                    12,
                    2
                )->nullable();

                $table->decimal(
                    'maximum_charge',
                    12,
                    2
                )->nullable();

                $table->unsignedInteger(
                    'settlement_days'
                )->default(0);

                $table->boolean('is_active')
                    ->default(true);

                $table->dateTime('effective_from')
                    ->nullable();

                $table->dateTime('effective_to')
                    ->nullable();

                $table->unsignedInteger('priority')
                    ->default(0);

                $table->text('notes')
                    ->nullable();

                $table->foreignId('created_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->foreignId('updated_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $table->timestamps();

                $table->index(
                    [
                        'payment_type',
                        'minimum_amount',
                        'maximum_amount',
                        'is_active',
                    ],
                    'prr_lookup_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pod_rate_rules');
    }
};