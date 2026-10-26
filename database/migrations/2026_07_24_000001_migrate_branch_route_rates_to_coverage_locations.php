<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function hasColumn(string $column): bool
    {
        return Schema::hasColumn('branch_route_rates', $column);
    }

    private function hasIndex(string $keyName): bool
    {
        return collect(Schema::getIndexes('branch_route_rates'))->contains(fn ($i) => $i['name'] === $keyName);
    }

    public function up(): void
    {
        if ($this->hasColumn('pickup_branch_id')) {
            Schema::table('branch_route_rates', function (Blueprint $table) {
                $table->dropForeign(['pickup_branch_id']);
                $table->dropForeign(['delivery_branch_id']);

                if ($this->hasIndex('branch_route_unique')) {
                    $table->dropUnique('branch_route_unique');
                }

                $table->dropColumn(['pickup_branch_id', 'delivery_branch_id']);
            });
        }

        if (!$this->hasColumn('pickup_coverage_location_id')) {
            Schema::table('branch_route_rates', function (Blueprint $table) {
                $table->foreignId('pickup_coverage_location_id')
                    ->after('id')
                    ->constrained('coverage_locations')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->foreignId('delivery_coverage_location_id')
                    ->after('pickup_coverage_location_id')
                    ->constrained('coverage_locations')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->unique(
                    ['pickup_coverage_location_id', 'delivery_coverage_location_id'],
                    'coverage_route_unique'
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->hasColumn('pickup_coverage_location_id')) {
            Schema::table('branch_route_rates', function (Blueprint $table) {
                $table->dropForeign(['pickup_coverage_location_id']);
                $table->dropForeign(['delivery_coverage_location_id']);

                if ($this->hasIndex('coverage_route_unique')) {
                    $table->dropUnique('coverage_route_unique');
                }

                $table->dropColumn(['pickup_coverage_location_id', 'delivery_coverage_location_id']);
            });
        }

        if (!$this->hasColumn('pickup_branch_id')) {
            Schema::table('branch_route_rates', function (Blueprint $table) {
                $table->foreignId('pickup_branch_id')
                    ->after('id')
                    ->constrained('branches')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->foreignId('delivery_branch_id')
                    ->after('pickup_branch_id')
                    ->constrained('branches')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->unique(
                    ['pickup_branch_id', 'delivery_branch_id'],
                    'branch_route_unique'
                );
            });
        }
    }
};
