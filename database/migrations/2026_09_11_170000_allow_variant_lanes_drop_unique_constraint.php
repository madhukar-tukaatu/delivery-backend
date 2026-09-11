<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the (from_branch_id, to_branch_id, service_type) unique constraint so
 * that multiple variant lanes (e.g. KTM→PKR via Khaireni vs via Gorkha)
 * sharing the same origin/destination/service_type can coexist.
 *
 * Replace with (from_branch_id, to_branch_id, service_type, variant_name) unique index
 * to allow multiple variants but prevent true duplicates.
 *
 * This enables auto-generation of routes: when a direct lane is created,
 * a corresponding route is automatically created to avoid re-entry in routes page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_transfer_lanes', function (Blueprint $table): void {
            // Drop the old triplet unique constraint if it exists.
            try {
                $table->dropUnique('transfer_lane_direction_service_unique');
            } catch (\Throwable) {
                // Already dropped or never existed on this DB — safe to continue.
            }

            // Add new unique index scoped to (from, to, service_type, variant_name)
            // so two lanes with the same path but different variant names are allowed,
            // but true duplicates (same path + same variant) are still blocked.
            $table->unique(
                ['from_branch_id', 'to_branch_id', 'service_type', 'variant_name'],
                'transfer_lane_variant_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('branch_transfer_lanes', function (Blueprint $table): void {
            try {
                $table->dropUnique('transfer_lane_variant_unique');
            } catch (\Throwable) {}

            $table->unique(
                ['from_branch_id', 'to_branch_id', 'service_type'],
                'transfer_lane_direction_service_unique'
            );
        });
    }
};
