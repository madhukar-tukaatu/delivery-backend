<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add high-performance composite and covering indexes for scale:
 * - Shipments: branch scoping, status counts, date ranges, and fast search
 * - Line-haul transfers: dispatch manifests and manifest items
 * - Deliveries: delivery assignments, runs, and run items
 * - Pickups: pickup requests, runs, and items
 * - Transfers: transfer requests and routing
 * - POD & Settlements: transaction lookups
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. SHIPMENTS TABLE
        $this->addIndexIfMissing('shipments', ['origin_branch_id', 'status'], 'idx_shipments_origin_branch_status');
        $this->addIndexIfMissing('shipments', ['destination_branch_id', 'status'], 'idx_shipments_dest_branch_status');
        $this->addIndexIfMissing('shipments', ['current_branch_id', 'status'], 'idx_shipments_current_branch_status');
        $this->addIndexIfMissing('shipments', ['origin_sub_branch_id', 'status'], 'idx_shipments_origin_sub_status');
        $this->addIndexIfMissing('shipments', ['destination_sub_branch_id', 'status'], 'idx_shipments_dest_sub_status');
        $this->addIndexIfMissing('shipments', ['current_sub_branch_id', 'status'], 'idx_shipments_current_sub_status');
        $this->addIndexIfMissing('shipments', ['merchant_id', 'status'], 'idx_shipments_merchant_status');
        $this->addIndexIfMissing('shipments', ['status', 'created_at'], 'idx_shipments_status_created');
        $this->addIndexIfMissing('shipments', ['merchant_id', 'created_at'], 'idx_shipments_merchant_created');
        $this->addIndexIfMissing('shipments', ['created_at'], 'idx_shipments_created_at');
        $this->addIndexIfMissing('shipments', ['receiver_name'], 'idx_shipments_receiver_name');
        $this->addIndexIfMissing('shipments', ['order_reference'], 'idx_shipments_order_reference');
        $this->addIndexIfMissing('shipments', ['external_order_id'], 'idx_shipments_external_order_id');
        $this->addIndexIfMissing('shipments', ['merchant_order_id'], 'idx_shipments_merchant_order_id');

        // 2. DISPATCH MANIFESTS & ITEMS (LINE-HAUL TRANSFERS)
        $this->addIndexIfMissing('dispatch_manifests', ['from_branch_id', 'status'], 'idx_dm_from_branch_status');
        $this->addIndexIfMissing('dispatch_manifests', ['to_branch_id', 'status'], 'idx_dm_to_branch_status');
        $this->addIndexIfMissing('dispatch_manifests', ['status', 'dispatched_at'], 'idx_dm_status_dispatched');
        $this->addIndexIfMissing('dispatch_manifests', ['status', 'received_at'], 'idx_dm_status_received');

        $this->addIndexIfMissing('dispatch_manifest_items', ['shipment_id', 'status'], 'idx_dmi_shipment_status');
        $this->addIndexIfMissing('dispatch_manifest_items', ['dispatch_manifest_id', 'status'], 'idx_dmi_manifest_status');

        // 3. DELIVERY ASSIGNMENTS & RUNS
        $this->addIndexIfMissing('delivery_assignments', ['branch_id', 'status'], 'idx_da_branch_status');
        $this->addIndexIfMissing('delivery_assignments', ['sub_branch_id', 'status'], 'idx_da_sub_branch_status');
        $this->addIndexIfMissing('delivery_assignments', ['rider_id', 'status'], 'idx_da_rider_status');
        $this->addIndexIfMissing('delivery_assignments', ['shipment_id', 'status'], 'idx_da_shipment_status');
        $this->addIndexIfMissing('delivery_assignments', ['status', 'created_at'], 'idx_da_status_created');

        $this->addIndexIfMissing('delivery_run_items', ['delivery_id', 'status'], 'idx_dri_delivery_status');

        // 4. PICKUP REQUESTS & RUNS
        $this->addIndexIfMissing('pickup_requests', ['pickup_branch_id', 'status'], 'idx_pr_pickup_branch_status');
        $this->addIndexIfMissing('pickup_requests', ['branch_id', 'status'], 'idx_pr_branch_status');
        $this->addIndexIfMissing('pickup_requests', ['assigned_to', 'status'], 'idx_pr_assigned_status');
        $this->addIndexIfMissing('pickup_requests', ['merchant_id', 'status'], 'idx_pr_merchant_status');
        $this->addIndexIfMissing('pickup_requests', ['pickup_phone'], 'idx_pr_phone');
        $this->addIndexIfMissing('pickup_requests', ['store_reference'], 'idx_pr_store_ref');

        // 5. TRANSFER REQUESTS
        $this->addIndexIfMissing('transfer_requests', ['origin_branch_id', 'status'], 'idx_tr_origin_status');
        $this->addIndexIfMissing('transfer_requests', ['destination_hub_id', 'status'], 'idx_tr_dest_status');

        // 6. SETTLEMENTS & POD TRANSACTIONS
        $this->addIndexIfMissing('merchant_settlements', ['merchant_id', 'status'], 'idx_ms_merchant_status');
        $this->addIndexIfMissing('pod_transactions', ['status', 'id'], 'idx_pt_status_id');
        $this->addIndexIfMissing('pod_transactions', ['merchant_id', 'status'], 'idx_pt_merchant_status');
        $this->addIndexIfMissing('pod_transactions', ['shipment_id'], 'idx_pt_shipment_id');
        $this->addIndexIfMissing('pod_transactions', ['deposited_at'], 'idx_pt_deposited_at');
    }

    public function down(): void
    {
        // Safe index drops
        $this->dropIndexIfExists('shipments', 'idx_shipments_origin_branch_status');
        $this->dropIndexIfExists('shipments', 'idx_shipments_dest_branch_status');
        $this->dropIndexIfExists('shipments', 'idx_shipments_current_branch_status');
        $this->dropIndexIfExists('shipments', 'idx_shipments_origin_sub_status');
        $this->dropIndexIfExists('shipments', 'idx_shipments_dest_sub_status');
        $this->dropIndexIfExists('shipments', 'idx_shipments_current_sub_status');
        $this->dropIndexIfExists('shipments', 'idx_shipments_merchant_status');
        $this->dropIndexIfExists('shipments', 'idx_shipments_status_created');
        $this->dropIndexIfExists('shipments', 'idx_shipments_merchant_created');
        $this->dropIndexIfExists('shipments', 'idx_shipments_created_at');
        $this->dropIndexIfExists('shipments', 'idx_shipments_receiver_name');
        $this->dropIndexIfExists('shipments', 'idx_shipments_order_reference');
        $this->dropIndexIfExists('shipments', 'idx_shipments_external_order_id');
        $this->dropIndexIfExists('shipments', 'idx_shipments_merchant_order_id');

        $this->dropIndexIfExists('dispatch_manifests', 'idx_dm_from_branch_status');
        $this->dropIndexIfExists('dispatch_manifests', 'idx_dm_to_branch_status');
        $this->dropIndexIfExists('dispatch_manifests', 'idx_dm_status_dispatched');
        $this->dropIndexIfExists('dispatch_manifests', 'idx_dm_status_received');

        $this->dropIndexIfExists('dispatch_manifest_items', 'idx_dmi_shipment_status');
        $this->dropIndexIfExists('dispatch_manifest_items', 'idx_dmi_manifest_status');

        $this->dropIndexIfExists('delivery_assignments', 'idx_da_branch_status');
        $this->dropIndexIfExists('delivery_assignments', 'idx_da_sub_branch_status');
        $this->dropIndexIfExists('delivery_assignments', 'idx_da_rider_status');
        $this->dropIndexIfExists('delivery_assignments', 'idx_da_shipment_status');
        $this->dropIndexIfExists('delivery_assignments', 'idx_da_status_created');

        $this->dropIndexIfExists('delivery_run_items', 'idx_dri_delivery_status');

        $this->dropIndexIfExists('pickup_requests', 'idx_pr_pickup_branch_status');
        $this->dropIndexIfExists('pickup_requests', 'idx_pr_branch_status');
        $this->dropIndexIfExists('pickup_requests', 'idx_pr_assigned_status');
        $this->dropIndexIfExists('pickup_requests', 'idx_pr_merchant_status');
        $this->dropIndexIfExists('pickup_requests', 'idx_pr_phone');
        $this->dropIndexIfExists('pickup_requests', 'idx_pr_store_ref');

        $this->dropIndexIfExists('transfer_requests', 'idx_tr_origin_status');
        $this->dropIndexIfExists('transfer_requests', 'idx_tr_dest_status');

        $this->dropIndexIfExists('merchant_settlements', 'idx_ms_merchant_status');
        $this->dropIndexIfExists('pod_transactions', 'idx_pt_status_id');
        $this->dropIndexIfExists('pod_transactions', 'idx_pt_merchant_status');
        $this->dropIndexIfExists('pod_transactions', 'idx_pt_shipment_id');
        $this->dropIndexIfExists('pod_transactions', 'idx_pt_deposited_at');
    }

    private function addIndexIfMissing(string $table, array|string $columns, string $indexName): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $cols = (array) $columns;
        foreach ($cols as $col) {
            if (! Schema::hasColumn($table, $col)) {
                return;
            }
        }

        try {
            $driver = DB::getDriverName();
            if ($driver === 'mysql') {
                $indexes = collect(DB::select("SHOW INDEXES FROM `{$table}`"))->pluck('Key_name')->all();
                if (in_array($indexName, $indexes, true)) {
                    return;
                }
            }

            Schema::table($table, function (Blueprint $tableBlueprint) use ($columns, $indexName) {
                $tableBlueprint->index($columns, $indexName);
            });
        } catch (\Throwable $e) {
            // Silently continue if index already exists or cannot be created
        }
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        try {
            Schema::table($table, function (Blueprint $tableBlueprint) use ($indexName) {
                $tableBlueprint->dropIndex($indexName);
            });
        } catch (\Throwable $e) {
            // Silently continue if index does not exist
        }
    }
};
