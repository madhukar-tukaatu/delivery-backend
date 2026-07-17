<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inter_branch_transfer_counts')) {
            return;
        }

        Schema::create(
            'inter_branch_transfer_counts',
            function (Blueprint $table): void {
                $table->id();

                $table->unsignedBigInteger('from_branch_id');
                $table->unsignedBigInteger('to_branch_id');

                $table->unsignedInteger('transfer_count')
                    ->default(0);

                $table->boolean('is_bidirectional')
                    ->default(false);

                $table->boolean('is_active')
                    ->default(true);

                $table->timestamps();

                $table->unique(
                    [
                        'from_branch_id',
                        'to_branch_id',
                    ],
                    'ibtc_branch_pair_uq'
                );

                $table->index(
                    'from_branch_id',
                    'ibtc_from_idx'
                );

                $table->index(
                    'to_branch_id',
                    'ibtc_to_idx'
                );

                $table->index(
                    'transfer_count',
                    'ibtc_count_idx'
                );

                $table->index(
                    [
                        'from_branch_id',
                        'to_branch_id',
                        'is_active',
                    ],
                    'ibtc_lookup_idx'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'inter_branch_transfer_counts'
        );
    }
};