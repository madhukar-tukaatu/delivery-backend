<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. High-Performance Indexes for Shipments (1M+ rows scale)
        if (Schema::hasTable('shipments')) {
            $this->addIndexSafely('shipments', ['origin_branch_id', 'status'], 'idx_shipments_origin_status');
            $this->addIndexSafely('shipments', ['destination_branch_id', 'status'], 'idx_shipments_dest_status');
            $this->addIndexSafely('shipments', ['status', 'created_at'], 'idx_shipments_status_created');
            $this->addIndexSafely('shipments', ['created_at'], 'idx_shipments_created_at');
            $this->addIndexSafely('shipments', ['merchant_id', 'status'], 'idx_shipments_merchant_status');
        }

        // 2. High-Performance Indexes for POD Records
        if (Schema::hasTable('pod_records')) {
            $this->addIndexSafely('pod_records', ['status', 'pod_amount'], 'idx_pod_records_status_amount');
            $this->addIndexSafely('pod_records', ['created_at'], 'idx_pod_records_created_at');
        }

        // 3. High-Performance Indexes for Delivery Assignments
        if (Schema::hasTable('delivery_assignments')) {
            $this->addIndexSafely('delivery_assignments', ['rider_id', 'status'], 'idx_deliv_assign_rider_status');
            $this->addIndexSafely('delivery_assignments', ['branch_id', 'status'], 'idx_deliv_assign_branch_status');
        }
    }

    public function down(): void
    {
        $this->dropIndexSafely('shipments', 'idx_shipments_origin_status');
        $this->dropIndexSafely('shipments', 'idx_shipments_dest_status');
        $this->dropIndexSafely('shipments', 'idx_shipments_status_created');
        $this->dropIndexSafely('shipments', 'idx_shipments_created_at');
        $this->dropIndexSafely('shipments', 'idx_shipments_merchant_status');

        $this->dropIndexSafely('pod_records', 'idx_pod_records_status_amount');
        $this->dropIndexSafely('pod_records', 'idx_pod_records_created_at');

        $this->dropIndexSafely('delivery_assignments', 'idx_deliv_assign_rider_status');
        $this->dropIndexSafely('delivery_assignments', 'idx_deliv_assign_branch_status');
    }

    private function addIndexSafely(string $tableName, array $columns, string $indexName): void
    {
        try {
            $existing = collect(DB::select("SHOW INDEX FROM `{$tableName}` WHERE Key_name = ?", [$indexName]));
            if ($existing->isEmpty()) {
                Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
                    $table->index($columns, $indexName);
                });
            }
        } catch (\Throwable $e) {
            // Index might already exist or table column differs
        }
    }

    private function dropIndexSafely(string $tableName, string $indexName): void
    {
        try {
            if (Schema::hasTable($tableName)) {
                $existing = collect(DB::select("SHOW INDEX FROM `{$tableName}` WHERE Key_name = ?", [$indexName]));
                if ($existing->isNotEmpty()) {
                    Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                        $table->dropIndex($indexName);
                    });
                }
            }
        } catch (\Throwable $e) {
            // Ignore if already dropped
        }
    }
};
