<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_branch_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('role', 16); // origin | transit | delivery
            $table->unsignedTinyInteger('position');
            $table->unsignedTinyInteger('branch_count');
            $table->decimal('percent', 7, 4)->default(0);
            $table->decimal('fare_amount', 12, 2)->default(0);
            $table->decimal('share_amount', 12, 2)->default(0);
            $table->decimal('transport_amount', 12, 2)->default(0);
            $table->decimal('extra_distance_amount', 12, 2)->default(0);
            $table->decimal('allocation_amount', 12, 2)->default(0);
            $table->decimal('hq_rate', 8, 4)->default(0);
            $table->decimal('hq_commission_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->foreignId('collecting_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            // merchant_invoice | company_invoice | customer
            $table->string('collection_mode', 24)->default('merchant_invoice');
            $table->unsignedBigInteger('statement_id')->nullable()->index();
            $table->string('status', 16)->default('pending')->index(); // pending | on_statement | settled
            $table->json('flags')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['shipment_id', 'branch_id', 'position'], 'sbs_shipment_branch_position_unique');
            $table->index(['branch_id', 'status']);
            $table->index(['collecting_branch_id', 'status']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'branch_share_status')) {
                // computed | pending_config | no_fare | failed
                $table->string('branch_share_status', 20)->nullable()->index();
            }
            if (! Schema::hasColumn('shipments', 'branch_share_note')) {
                $table->string('branch_share_note', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasColumn('shipments', 'branch_share_status')) {
                $table->dropIndex(['branch_share_status']);
                $table->dropColumn('branch_share_status');
            }
            if (Schema::hasColumn('shipments', 'branch_share_note')) {
                $table->dropColumn('branch_share_note');
            }
        });

        Schema::dropIfExists('shipment_branch_shares');
    }
};
