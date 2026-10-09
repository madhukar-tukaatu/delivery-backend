<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inter_branch_statements', function (Blueprint $table) {
            $table->id();
            $table->string('statement_number')->unique();
            // from = collecting / paying branch, to = branch receiving its shares
            $table->foreignId('from_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->unsignedInteger('line_count')->default(0);
            $table->string('status', 16)->default('draft')->index(); // draft | issued | paid | received
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_method', 32)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_reference')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['from_branch_id', 'to_branch_id', 'period_start', 'period_end'], 'ibs_pair_period_index');
        });

        Schema::create('inter_branch_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_id')->constrained('inter_branch_statements')->cascadeOnDelete();
            $table->foreignId('shipment_branch_share_id')->constrained('shipment_branch_shares')->cascadeOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->string('role', 16);
            $table->decimal('share_amount', 12, 2)->default(0);
            $table->decimal('transport_amount', 12, 2)->default(0);
            $table->decimal('extra_distance_amount', 12, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0); // allocation owed to to_branch
            $table->timestamps();

            $table->unique('shipment_branch_share_id', 'ibsl_share_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inter_branch_statement_lines');
        Schema::dropIfExists('inter_branch_statements');
    }
};
