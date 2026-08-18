<?php

namespace Database\Seeders\System;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    protected string $guardName = 'web';

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissionGroups() as $groupName => $permissions) {
            foreach ($permissions as $permissionName => $displayName) {
                $permission = Permission::query()->firstOrCreate([
                    'name'       => $permissionName,
                    'guard_name' => $this->guardName,
                ]);

                $updates = [];

                if (Schema::hasColumn('permissions', 'display_name')) {
                    $updates['display_name'] = $displayName;
                }
                if (Schema::hasColumn('permissions', 'label')) {
                    $updates['label'] = $displayName;
                }
                if (Schema::hasColumn('permissions', 'description')) {
                    $updates['description'] = $displayName;
                }
                if (Schema::hasColumn('permissions', 'group')) {
                    $updates['group'] = $groupName;
                }
                if (Schema::hasColumn('permissions', 'group_name')) {
                    $updates['group_name'] = $groupName;
                }
                if (Schema::hasColumn('permissions', 'module')) {
                    $updates['module'] = $groupName;
                }
                if (Schema::hasColumn('permissions', 'is_active')) {
                    $updates['is_active'] = true;
                }

                if (!empty($updates)) {
                    $permission->update($updates);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Permissions seeded successfully.');
    }

    private function permissionGroups(): array
    {
        return [
            // ─────────────────────────────────────────────────────────────
            // Core
            // ─────────────────────────────────────────────────────────────
            'Dashboard' => [
                'dashboard.view' => 'Dashboard View',
            ],

            'Coverage Locations' => [
                'coverage_locations.view'   => 'Coverage Locations View',
                'coverage_locations.create' => 'Coverage Locations Create',
                'coverage_locations.edit'   => 'Coverage Locations Edit',
                'coverage_locations.delete' => 'Coverage Locations Delete',
            ],

            'Branches' => [
                'branches.view'     => 'Branches View',
                'branches.create'   => 'Branches Create',
                'branches.edit'     => 'Branches Edit',
                'branches.delete'   => 'Branches Delete',
                'branches.approve'  => 'Branches Approve',
                'branches.reject'   => 'Branches Reject',
                'branches.suspend'  => 'Branches Suspend',
                'branches.activate' => 'Branches Activate',

                'branches.documents.view'   => 'Branch Documents View',
                'branches.documents.manage' => 'Branch Documents Manage',

                'branches.agreements.view'   => 'Branch Agreements View',
                'branches.agreements.manage' => 'Branch Agreements Manage',

                'branches.team.view'        => 'Branch Team View',
                'branches.team.manage'      => 'Branch Team Manage',
                'branches.team.credentials' => 'Branch Team Credentials',
            ],

            'Merchants' => [
                'merchants.view'             => 'Merchants View',
                'merchants.create'           => 'Merchants Create',
                'merchants.edit'             => 'Merchants Edit',
                'merchants.delete'           => 'Merchants Delete',
                'merchants.approve'          => 'Merchants Approve',
                'merchants.reject'           => 'Merchants Reject',
                'merchants.suspend'          => 'Merchants Suspend',
                'merchants.request_more_info'=> 'Merchants Request More Info',

                'merchants.documents.view'   => 'Merchant Documents View',
                'merchants.documents.verify' => 'Merchant Documents Verify',

                'merchants.locations.view'   => 'Merchant Locations View',
                'merchants.locations.verify' => 'Merchant Locations Verify',
            ],

            'Merchant Onboarding' => [
                'merchant.onboarding'          => 'Merchant Onboarding',
                'merchant.profile'             => 'Merchant Profile',
                'merchant.documents'           => 'Merchant Documents',
                'merchant.locations'           => 'Merchant Locations',
                'merchant.bank_details'        => 'Merchant Bank Details',
                'merchant.submit_verification' => 'Merchant Submit Verification',
            ],

            'Merchant Portal' => [
                'merchant.dashboard'        => 'Merchant Dashboard',
                'merchant.shipments'        => 'Merchant Shipments',
                'merchant.pickups'          => 'Merchant Pickups',
                'merchant.pickup_locations' => 'Merchant Pickup Locations',
                'merchant.customers'        => 'Merchant Customers',
                'merchant.rates'            => 'Merchant Rates',
                'merchant.pod'              => 'Merchant POD',
                'merchant.settlements'      => 'Merchant Settlements',
                'merchant.invoices'         => 'Merchant Invoices',
                'merchant.api_keys'         => 'Merchant API Keys',
                'merchant.api_logs'         => 'Merchant API Logs',
                'merchant.webhooks'         => 'Merchant Webhooks',
                'merchant.webhook_logs'     => 'Merchant Webhook Logs',
                'merchant.support'          => 'Merchant Support',
            ],

            'Customers' => [
                'customers.view'   => 'Customers View',
                'customers.create' => 'Customers Create',
                'customers.edit'   => 'Customers Edit',
                'customers.delete' => 'Customers Delete',
            ],

            // ─────────────────────────────────────────────────────────────
            // Operations
            // ─────────────────────────────────────────────────────────────
            'Shipments' => [
                'shipments.view'            => 'Shipments View',
                'shipments.create'          => 'Shipments Create',
                'shipments.edit'            => 'Shipments Edit',
                'shipments.delete'          => 'Shipments Delete',
                'shipments.cancel'          => 'Shipments Cancel',
                'shipments.status'          => 'Shipments Status',
                'shipments.quote'           => 'Shipments Quote',
                'shipments.assign_pickup'   => 'Shipments Assign Pickup',
                'shipments.assign_delivery' => 'Shipments Assign Delivery',
                'shipments.lifecycle'       => 'Shipments Lifecycle',
                'shipments.invoice'         => 'Shipments Invoice',
                'shipments.print_label'     => 'Shipments Print Label',
                'shipments.export'          => 'Shipments Export',
            ],

            'Shipment Tasks' => [
                'shipment_tasks.view'   => 'Shipment Tasks View',
                'shipment_tasks.assign' => 'Shipment Tasks Assign',
                'shipment_tasks.status' => 'Shipment Tasks Status',
            ],

            'Pickups' => [
                'pickups.view'      => 'Pickups View',
                'pickups.create'    => 'Pickups Create',
                'pickups.assign'    => 'Pickups Assign',
                'pickups.status'    => 'Pickups Status',
                'pickups.accept'    => 'Pickups Accept',
                'pickups.picked_up' => 'Pickups Picked Up',
                'pickups.failed'    => 'Pickups Failed',
                'pickups.reschedule'=> 'Pickups Reschedule',
            ],

            'Deliveries' => [
                'deliveries.view'             => 'Deliveries View',
                'deliveries.assign'           => 'Deliveries Assign',
                'deliveries.status'           => 'Deliveries Status',
                'deliveries.accept'           => 'Deliveries Accept',
                'deliveries.out_for_delivery' => 'Deliveries Out For Delivery',
                'deliveries.delivered'        => 'Deliveries Delivered',
                'deliveries.failed'           => 'Deliveries Failed',
            ],

            'Dispatches' => [
                'dispatches.view'             => 'Dispatches View',
                'dispatches.create'           => 'Dispatches Create',
                'dispatches.receive'          => 'Dispatches Receive',
                'dispatches.dispatch'         => 'Dispatches Dispatch',
                'dispatches.transfer_batches' => 'Dispatches Transfer Batches',
                'dispatches.route_workflow'   => 'Dispatches Route Workflow',
            ],

            'POD' => [
                'pod.view'         => 'POD View',
                'pod.collect'      => 'POD Collect',
                'pod.confirm'      => 'POD Confirm',
                'pod.deposit'      => 'POD Deposit',
                'pod.rider_deposit'=> 'POD Rider Deposit',
                'pod.collections'  => 'POD Collections',
                'pod.settle'       => 'POD Settle',
            ],

            // ─────────────────────────────────────────────────────────────
            // Pricing  (all granular — no legacy rates.* needed)
            // ─────────────────────────────────────────────────────────────
            'Pricing' => [
                'pricing.view'                  => 'Pricing View',
                'pricing.calculate'             => 'Pricing Calculate',
                'pricing.settings.manage'       => 'Pricing Settings Manage',
                'pricing.service_types.manage'  => 'Pricing Service Types Manage',
                'pricing.branch_route_rates.view'   => 'Pricing Branch Route Rates View',
                'pricing.branch_route_rates.manage' => 'Pricing Branch Route Rates Manage',
                'pricing.transfer_lanes.manage' => 'Pricing Transfer Lanes Manage',
                'pricing.transfer_routes.manage'=> 'Pricing Transfer Routes Manage',
                'pricing.coverage_health.view'  => 'Pricing Coverage Health View',
                'pricing.network_health.view'   => 'Pricing Network Health View',
                'pricing.audit.view'            => 'Pricing Audit View',
            ],

            'Pricing Transfer Lanes' => [
                'pricing.transfer_lanes.view'   => 'Pricing Transfer Lanes View',
                'pricing.transfer_lanes.create' => 'Pricing Transfer Lanes Create',
                'pricing.transfer_lanes.update' => 'Pricing Transfer Lanes Update',
                'pricing.transfer_lanes.status' => 'Pricing Transfer Lanes Status',
                'pricing.transfer_lanes.delete' => 'Pricing Transfer Lanes Delete',
            ],

            'Pricing Transfer Routes' => [
                'pricing.transfer_routes.view'   => 'Pricing Transfer Routes View',
                'pricing.transfer_routes.create' => 'Pricing Transfer Routes Create',
                'pricing.transfer_routes.update' => 'Pricing Transfer Routes Update',
                'pricing.transfer_routes.status' => 'Pricing Transfer Routes Status',
                'pricing.transfer_routes.delete' => 'Pricing Transfer Routes Delete',
            ],

            'Pricing Administration' => [
                'pricing.settings.view'   => 'Pricing Settings View',
                'pricing.settings.create' => 'Pricing Settings Create',
                'pricing.settings.update' => 'Pricing Settings Update',
                'pricing.settings.activate'=> 'Pricing Settings Activate',
                'pricing.settings.delete' => 'Pricing Settings Delete',

                'pricing.service_types.view'   => 'Pricing Service Types View',
                'pricing.service_types.create' => 'Pricing Service Types Create',
                'pricing.service_types.update' => 'Pricing Service Types Update',
                'pricing.service_types.status' => 'Pricing Service Types Status',
                'pricing.service_types.delete' => 'Pricing Service Types Delete',

                'pricing.branch_rates.view'   => 'Pricing Branch Rates View',
                'pricing.branch_rates.create' => 'Pricing Branch Rates Create',
                'pricing.branch_rates.update' => 'Pricing Branch Rates Update',
                'pricing.branch_rates.status' => 'Pricing Branch Rates Status',
                'pricing.branch_rates.delete' => 'Pricing Branch Rates Delete',

                'pricing.simulator.use'  => 'Pricing Simulator Use',
                'pricing.quotes.view'    => 'Pricing Quotes View',
                'pricing.quotes.delete'  => 'Pricing Quotes Delete',
            ],

            // ─────────────────────────────────────────────────────────────
            // Finance
            // ─────────────────────────────────────────────────────────────
            'Billing' => [
                'invoices.view'   => 'Invoices View',
                'invoices.create' => 'Invoices Create',
                'receipts.view'   => 'Receipts View',
                'receipts.create' => 'Receipts Create',
            ],

            'Settlements' => [
                'settlements.view'   => 'Settlements View',
                'settlements.create' => 'Settlements Create',
                'settlements.pay'    => 'Settlements Pay',

                'merchant_settlements.view'   => 'Merchant Settlements View',
                'merchant_settlements.create' => 'Merchant Settlements Create',
                'merchant_settlements.pay'    => 'Merchant Settlements Pay',
            ],

            // ─────────────────────────────────────────────────────────────
            // Integrations / Logs
            // ─────────────────────────────────────────────────────────────
            'API Keys' => [
                'api_keys.view'   => 'API Keys View',
                'api_keys.manage' => 'API Keys Manage',
            ],

            'Webhooks' => [
                'webhooks.view'   => 'Webhooks View',
                'webhooks.manage' => 'Webhooks Manage',
                'webhooks.retry'  => 'Webhooks Retry',
                'webhooks.test'   => 'Webhooks Test',
            ],

            'Logs' => [
                'api_logs.view'     => 'API Logs View',
                'audit_logs.view'   => 'Audit Logs View',
                'sms_logs.view'     => 'SMS Logs View',
                'email_logs.view'   => 'Email Logs View',
                'webhook_logs.view' => 'Webhook Logs View',
            ],

            // ─────────────────────────────────────────────────────────────
            // Communication / Reporting
            // ─────────────────────────────────────────────────────────────
            'Notifications' => [
                'notifications.view'      => 'Notifications View',
                'notifications.manage'    => 'Notifications Manage',
                'notifications.mark_sent' => 'Notifications Mark Sent',
            ],

            'Reports' => [
                'reports.view'      => 'Reports View',
                'reports.export'    => 'Reports Export',
                'reports.branches'  => 'Reports Branches',
                'reports.pod'       => 'Reports POD',
                'reports.merchants' => 'Reports Merchants',
                'reports.revenue'   => 'Reports Revenue',
                'reports.shipments' => 'Reports Shipments',
                'reports.staff'     => 'Reports Staff',
            ],

            'Support' => [
                'support.view'   => 'Support View',
                'support.manage' => 'Support Manage',
                'support.delete' => 'Support Delete',
            ],

            // ─────────────────────────────────────────────────────────────
            // Staff Portal
            // ─────────────────────────────────────────────────────────────
            'Staff Portal' => [
                'staff.dashboard'      => 'Staff Dashboard',
                'staff.pickups'        => 'Staff Pickups',
                'staff.deliveries'     => 'Staff Deliveries',
                'staff.pod'            => 'Staff POD',
                'staff.rider_location' => 'Staff Rider Location',
            ],

            // ─────────────────────────────────────────────────────────────
            // System Administration
            // ─────────────────────────────────────────────────────────────
            'Users' => [
                'users.view'   => 'Users View',
                'users.manage' => 'Users Manage',
            ],

            'Roles' => [
                'roles.view'   => 'Roles View',
                'roles.manage' => 'Roles Manage',
                'menus.view'   => 'Menus View',
                'menus.manage' => 'Menus Manage',
            ],

            'Settings' => [
                'settings.view'   => 'Settings View',
                'settings.manage' => 'Settings Manage',
            ],
        ];
    }
}
