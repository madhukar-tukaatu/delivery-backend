<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add FK constraints to transfer_batches and transfer_shipment_trackers
        // This is done after branch_transfer_routes is created

        if (Schema::hasTable('transfer_batches') && Schema::hasColumn('transfer_batches', 'branch_transfer_route_id')) {
            if (!$this->foreignKeyExists('transfer_batches', 'transfer_batches_branch_transfer_route_id_foreign')) {
                Schema::table('transfer_batches', function (Blueprint $table) {
                    $table->foreign('branch_transfer_route_id', 'tbatch_route_fk')
                        ->references('id')
                        ->on('branch_transfer_routes')
                        ->cascadeOnDelete();
                });
            }
        }

        if (Schema::hasTable('transfer_shipment_trackers') && Schema::hasColumn('transfer_shipment_trackers', 'branch_transfer_route_id')) {
            if (!$this->foreignKeyExists('transfer_shipment_trackers', 'transfer_shipment_trackers_branch_transfer_route_id_foreign')) {
                Schema::table('transfer_shipment_trackers', function (Blueprint $table) {
                    $table->foreign('branch_transfer_route_id', 'ttracker_route_fk')
                        ->references('id')
                        ->on('branch_transfer_routes')
                        ->cascadeOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('transfer_batches', function (Blueprint $table) {
            if ($this->foreignKeyExists('transfer_batches', 'tbatch_route_fk')) {
                $table->dropForeign('tbatch_route_fk');
            }
        });

        Schema::table('transfer_shipment_trackers', function (Blueprint $table) {
            if ($this->foreignKeyExists('transfer_shipment_trackers', 'ttracker_route_fk')) {
                $table->dropForeign('ttracker_route_fk');
            }
        });
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        $constraints = \DB::select("
            SELECT CONSTRAINT_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = '{$table}'
            AND CONSTRAINT_NAME = '{$constraint}'
        ");

        return !empty($constraints);
    }
};
