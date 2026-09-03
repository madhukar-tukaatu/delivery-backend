<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pickup_request_shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('pickup_request_shipments', 'collection_status')) {
                $table->string('collection_status', 40)
                    ->default('pending')
                    ->after('status');
            }

            if (! Schema::hasColumn('pickup_request_shipments', 'collected_at')) {
                $table->timestamp('collected_at')
                    ->nullable()
                    ->after('collection_status');
            }

            if (! Schema::hasColumn('pickup_request_shipments', 'collected_by')) {
                $table->foreignId('collected_by')
                    ->nullable()
                    ->after('collected_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            $table->index(
                ['pickup_request_id', 'collection_status'],
                'prs_pickup_req_collection_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('pickup_request_shipments', function (Blueprint $table) {
            $table->dropIndex('prs_pickup_req_collection_idx');

            if (Schema::hasColumn('pickup_request_shipments', 'collected_by')) {
                $table->dropConstrainedForeignId('collected_by');
            }

            if (Schema::hasColumn('pickup_request_shipments', 'collected_at')) {
                $table->dropColumn('collected_at');
            }

            if (Schema::hasColumn('pickup_request_shipments', 'collection_status')) {
                $table->dropColumn('collection_status');
            }
        });
    }
};
