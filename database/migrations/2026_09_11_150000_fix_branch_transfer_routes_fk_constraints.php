<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('branch_transfer_routes')) {
            return;
        }

        // Drop existing incorrect foreign keys if they exist
        $this->dropConstraintIfExists('branch_transfer_routes', 'branch_transfer_routes_destination_branch_id_foreign');
        $this->dropConstraintIfExists('branch_transfer_routes', 'branch_transfer_routes_origin_branch_id_foreign');

        // Add correct foreign keys to coverage_locations
        Schema::table('branch_transfer_routes', function (Blueprint $table) {
            if (Schema::hasColumn('branch_transfer_routes', 'destination_branch_id')
                && !$this->foreignKeyExists('branch_transfer_routes', 'destination_branch_id')) {
                $table->foreign('destination_branch_id')
                    ->references('id')->on('coverage_locations')
                    ->restrictOnDelete();
            }

            if (Schema::hasColumn('branch_transfer_routes', 'origin_branch_id')
                && !$this->foreignKeyExists('branch_transfer_routes', 'origin_branch_id')) {
                $table->foreign('origin_branch_id')
                    ->references('id')->on('coverage_locations')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('branch_transfer_routes', function (Blueprint $table) {
            $this->dropConstraintIfExists('branch_transfer_routes', 'branch_transfer_routes_destination_branch_id_foreign');
            $this->dropConstraintIfExists('branch_transfer_routes', 'branch_transfer_routes_origin_branch_id_foreign');
        });
    }

    private function dropConstraintIfExists(string $table, string $constraint): void
    {
        if (DB::getDriverName() === 'mysql') {
            $database = DB::getDatabaseName();
            $exists = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $database)
                ->where('CONSTRAINT_NAME', $constraint)
                ->exists();

            if ($exists) {
                Schema::table($table, function (Blueprint $t) use ($constraint) {
                    $t->dropForeign([$this->extractColumnFromConstraint($constraint)]);
                });
            }
        }
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }

    private function extractColumnFromConstraint(string $constraint): string
    {
        if (str_contains($constraint, 'destination')) {
            return 'destination_branch_id';
        }
        if (str_contains($constraint, 'origin')) {
            return 'origin_branch_id';
        }
        return 'branch_id';
    }
};
