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
                    'inter_branch_transfer_pair_unique'
                );

                $table->index('transfer_count');
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