<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Remove per-route pricing settings — all pricing uses global only.
         */
        if (Schema::hasColumn('pricing_settings', 'scope_type')) {
            DB::table('pricing_settings')
                ->where('scope_type', 'transfer_route')
                ->delete();
        }

        Schema::table('pricing_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('pricing_settings', 'branch_transfer_route_id')) {
                $table->dropForeign(['branch_transfer_route_id']);
                $table->dropColumn('branch_transfer_route_id');
            }

            if (Schema::hasColumn('pricing_settings', 'scope_type')) {
                $table->dropColumn('scope_type');
            }
        });

        /*
         * Remove pricing columns from branch_transfer_routes.
         * The route is a logical path only — pricing comes from
         * branch_route_rates.base_rate + global pricing_settings.
         */
        Schema::table('branch_transfer_routes', function (Blueprint $table): void {
            if (Schema::hasColumn('branch_transfer_routes', 'base_rate')) {
                $table->dropColumn('base_rate');
            }

            if (Schema::hasColumn('branch_transfer_routes', 'currency')) {
                $table->dropColumn('currency');
            }
        });
    }

    public function down(): void
    {
        Schema::table('branch_transfer_routes', function (Blueprint $table): void {
            $table->decimal('base_rate', 12, 2)->default(0)->after('service_type');
            $table->char('currency', 3)->default('NPR')->after('base_rate');
        });

        Schema::table('pricing_settings', function (Blueprint $table): void {
            $table->string('scope_type', 30)->default('global')->after('id');
            $table->unsignedBigInteger('branch_transfer_route_id')->nullable()->after('scope_type');
        });

        DB::table('pricing_settings')->update(['scope_type' => 'global']);
    }
};
