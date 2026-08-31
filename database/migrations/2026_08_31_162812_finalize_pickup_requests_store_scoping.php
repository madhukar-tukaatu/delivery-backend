<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pickup_requests', function (Blueprint $table) {
            /*
             * Remove the previous merchant + request_number
             * uniqueness rule.
             */
            $table->dropUnique(
                'pickup_requests_merchant_request_number_unique'
            );

            /*
             * request_number belongs to Tukaatu.
             *
             * It must therefore be globally unique.
             */
            $table->unique(
                'request_number',
                'pickup_requests_request_number_unique'
            );

            /*
             * Store's own pickup/container reference.
             *
             * Example:
             *
             * Store 1 -> PR-001
             * Store 2 -> PR-001
             *
             * These are allowed because they have different
             * pickup locations.
             */
            if (! Schema::hasColumn('pickup_requests', 'store_reference')) {
                $table->string('store_reference', 100)
                    ->after('pickup_location_id');
            }

            /*
             * The same store/location cannot submit the same
             * PR reference twice.
             */
            $table->unique(
                [
                    'merchant_id',
                    'pickup_location_id',
                    'store_reference',
                ],
                'pickup_requests_store_reference_unique'
            );

            /*
             * Useful for branch-manager dashboards.
             */
            $table->index(
                [
                    'pickup_branch_id',
                    'status',
                ],
                'pickup_requests_branch_status_index'
            );

            $table->index(
                [
                    'merchant_id',
                    'pickup_location_id',
                    'status',
                ],
                'pickup_requests_merchant_location_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('pickup_requests', function (Blueprint $table) {
            $table->dropUnique(
                'pickup_requests_request_number_unique'
            );

            $table->dropUnique(
                'pickup_requests_store_reference_unique'
            );

            $table->dropIndex(
                'pickup_requests_branch_status_index'
            );

            $table->dropIndex(
                'pickup_requests_merchant_location_status_index'
            );

            $table->dropColumn('store_reference');

            /*
             * Restore the previous uniqueness rule.
             */
            $table->unique(
                ['merchant_id', 'request_number'],
                'pickup_requests_merchant_request_number_unique'
            );
        });
    }
};