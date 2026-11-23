<?php

namespace Database\Seeders\System;

use Modules\Access\Enums\SystemRole;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    protected string $guardName = 'web';

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->call(PermissionSeeder::class);

        $allPermissions = Permission::query()
            ->where('guard_name', $this->guardName)
            ->pluck('name')
            ->toArray();

        $roleMetadata = [
            SystemRole::SuperAdmin->value => [
                'label' => 'Super Admin',
                'description' => 'Unrestricted super administrator with system-wide privileges',
            ],
            SystemRole::MainAdmin->value => [
                'label' => 'Main Admin',
                'description' => 'Full operational access across all modules',
            ],
            SystemRole::HqAdmin->value => [
                'label' => 'HQ Admin',
                'description' => 'Headquarter Administrator with full operational management permissions',
            ],
            SystemRole::HqManager->value => [
                'label' => 'HQ Manager',
                'description' => 'Headquarter Manager overseeing overall operations and approvals',
            ],
            SystemRole::HqAccountsStaff->value => [
                'label' => 'HQ Accounts Staff',
                'description' => 'Headquarter Accounts and Finance Staff',
            ],
            SystemRole::HqOperationsStaff->value => [
                'label' => 'HQ Operations Staff',
                'description' => 'Headquarter Operations Staff managing central dispatch, transfers, and routing',
            ],
            SystemRole::BranchManager->value => [
                'label' => 'Branch Manager',
                'description' => 'Manages branch operations, staff team, and local shipments',
            ],
            SystemRole::SubBranchManager->value => [
                'label' => 'Sub Branch Manager',
                'description' => 'Manages sub-branch local operations',
            ],
            SystemRole::BookingStaff->value => [
                'label' => 'Booking Staff',
                'description' => 'Creates bookings, quotes, and manages customer shipments',
            ],
            SystemRole::PickupStaff->value => [
                'label' => 'Pickup Staff',
                'description' => 'Handles parcel pickups from merchants and stores',
            ],
            SystemRole::DispatchStaff->value => [
                'label' => 'Dispatch Staff',
                'description' => 'Manages branch transfers, dispatches, and route workflow',
            ],
            SystemRole::DeliveryStaff->value => [
                'label' => 'Delivery Staff',
                'description' => 'Delivers packages to customers',
            ],
            SystemRole::WarehouseStaff->value => [
                'label' => 'Warehouse Staff',
                'description' => 'Receives shipments and handles warehouse sorting',
            ],
            SystemRole::Rider->value => [
                'label' => 'Rider',
                'description' => 'Field rider performing pickup, delivery, and cash collection (POD)',
            ],
            SystemRole::AccountsStaff->value => [
                'label' => 'Branch Accounts Staff',
                'description' => 'Branch finance, collections, and settlement records',
            ],
            SystemRole::SupportStaff->value => [
                'label' => 'Support Staff',
                'description' => 'Customer support, ticket resolution, and inquiry tracking',
            ],
            SystemRole::BranchStaff->value => [
                'label' => 'Branch Staff',
                'description' => 'General branch staff for local handling',
            ],
            SystemRole::Merchant->value => [
                'label' => 'Merchant',
                'description' => 'Merchant user with access to merchant portal',
            ],
        ];

        $roleMap = $this->rolePermissionMap($allPermissions);

        foreach ($roleMap as $roleName => $permissions) {
            $role = Role::query()->firstOrCreate([
                'name'       => $roleName,
                'guard_name' => $this->guardName,
            ]);

            $updates = [];
            $meta = $roleMetadata[$roleName] ?? [];

            if (Schema::hasColumn('roles', 'label')) {
                $updates['label'] = $meta['label'] ?? ucwords(str_replace('_', ' ', $roleName));
            }
            if (Schema::hasColumn('roles', 'display_name')) {
                $updates['display_name'] = $meta['label'] ?? ucwords(str_replace('_', ' ', $roleName));
            }
            if (Schema::hasColumn('roles', 'description')) {
                $updates['description'] = $meta['description'] ?? ucwords(str_replace('_', ' ', $roleName));
            }
            if (Schema::hasColumn('roles', 'is_system')) {
                $updates['is_system'] = true;
            }
            if (Schema::hasColumn('roles', 'is_active')) {
                $updates['is_active'] = true;
            }

            if (!empty($updates)) {
                $role->update($updates);
            }

            $valid   = $this->onlyExisting($permissions, $allPermissions);
            $missing = array_values(array_diff($permissions, $valid));

            $role->syncPermissions($valid);

            if (!empty($missing)) {
                $this->command?->warn($roleName . ' — skipped missing: ' . implode(', ', $missing));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Roles and role permissions seeded successfully.');
    }

    private function onlyExisting(array $permissions, array $all): array
    {
        return array_values(array_unique(array_intersect($permissions, $all)));
    }

    private function rolePermissionMap(array $all): array
    {
        return [

            // ═══════════════════════════════════════════════════════════
            // SUPER ADMIN — everything
            // ═══════════════════════════════════════════════════════════
            SystemRole::SuperAdmin->value => $all,

            // ═══════════════════════════════════════════════════════════
            // HQ ADMIN
            // Headquarter Administrator with full operational management permissions.
            // ═══════════════════════════════════════════════════════════
            SystemRole::HqAdmin->value => [
                'dashboard.view',

                // Branches — full management
                'branches.view', 'branches.create', 'branches.edit',
                'branches.approve', 'branches.reject', 'branches.suspend', 'branches.activate',
                'branches.documents.view', 'branches.documents.manage',
                'branches.agreements.view', 'branches.agreements.manage',
                'branches.team.view', 'branches.team.manage', 'branches.team.credentials',

                'coverage_locations.view', 'coverage_locations.create',
                'coverage_locations.edit', 'coverage_locations.delete',

                // Merchants
                'merchants.view', 'merchants.create', 'merchants.edit',
                'merchants.approve', 'merchants.reject', 'merchants.suspend',
                'merchants.request_more_info',
                'merchants.documents.view', 'merchants.documents.verify',
                'merchants.locations.view', 'merchants.locations.verify',

                // Marketplaces
                'marketplaces.view', 'marketplaces.create', 'marketplaces.update',
                'marketplaces.hamropay', 'marketplaces.stores',

                // Customers
                'customers.view', 'customers.create', 'customers.edit',

                // Shipments
                'shipments.view', 'shipments.create', 'shipments.edit',
                'shipments.cancel', 'shipments.status', 'shipments.quote',
                'shipments.assign_pickup', 'shipments.assign_delivery',
                'shipments.lifecycle', 'shipments.invoice',
                'shipments.print_label', 'shipments.export',

                'shipment_tasks.view', 'shipment_tasks.assign', 'shipment_tasks.status',

                // Pickups / Deliveries / Dispatches
                'pickups.view', 'pickups.create', 'pickups.assign', 'pickups.assignable_staff', 'pickups.status',
                'pickups.accept', 'pickups.start', 'pickups.arrive', 'pickups.collect',
                'pickups.complete', 'pickups.picked_up', 'pickups.failed', 'pickups.reschedule',
                'pickups.receive', 'pickups.transfer',

                'deliveries.view', 'deliveries.assign', 'deliveries.status',
                'deliveries.accept', 'deliveries.out_for_delivery',
                'deliveries.delivered', 'deliveries.failed',

                'dispatches.view', 'dispatches.create', 'dispatches.receive',
                'dispatches.dispatch', 'dispatches.transfer_batches', 'dispatches.route_workflow',

                // Transfers page (TRs between branches)
                'transfers.view', 'transfers.dispatch', 'transfers.receive', 'transfers.receive_transit',
                'transfers.repair',

                // POD
                'pod.view', 'pod.collect', 'pod.confirm', 'pod.deposit',
                'pod.rider_deposit', 'pod.collections', 'pod.settle',

                // Pricing — full
                'pricing.view', 'pricing.calculate',
                'pricing.settings.manage', 'pricing.service_types.manage',
                'pricing.branch_route_rates.view', 'pricing.branch_route_rates.manage',
                'pricing.transfer_lanes.manage', 'pricing.transfer_routes.manage',
                'pricing.coverage_health.view', 'pricing.network_health.view',
                'pricing.audit.view',
                'pricing.transfer_lanes.view', 'pricing.transfer_lanes.create',
                'pricing.transfer_lanes.update', 'pricing.transfer_lanes.status',
                'pricing.transfer_routes.view', 'pricing.transfer_routes.create',
                'pricing.transfer_routes.update', 'pricing.transfer_routes.status',
                'pricing.settings.view', 'pricing.settings.create',
                'pricing.settings.update', 'pricing.settings.activate',
                'pricing.service_types.view', 'pricing.service_types.create',
                'pricing.service_types.update', 'pricing.service_types.status',
                'pricing.branch_rates.view', 'pricing.branch_rates.create',
                'pricing.branch_rates.update', 'pricing.branch_rates.status',
                'pricing.simulator.use', 'pricing.quotes.view',

                // Finance
                'invoices.view', 'invoices.create',
                'receipts.view', 'receipts.create',
                'settlements.view', 'settlements.create', 'settlements.pay',
                'merchant_settlements.view', 'merchant_settlements.create', 'merchant_settlements.pay',
                'hq-commissions.bills', 'hq-commissions.settings', 'hq-commissions.settlements',
                'payment-gateways.accounts', 'payment-gateways.catalog',

                // Integrations
                'api_keys.view', 'api_keys.manage',
                'webhooks.view', 'webhooks.manage', 'webhooks.retry', 'webhooks.test',

                // Logs
                'api_logs.view', 'audit_logs.view',
                'sms_logs.view', 'email_logs.view', 'webhook_logs.view',

                // Notifications / Reports / Support
                'notifications.view', 'notifications.manage', 'notifications.mark_sent',
                'reports.view', 'reports.export', 'reports.branches', 'reports.pod',
                'reports.merchants', 'reports.revenue', 'reports.shipments', 'reports.staff',
                'support.view', 'support.manage',

                // Users & Staff management
                'users.view', 'users.manage',
                'staff.view', 'staff.create', 'staff.edit', 'staff.status',
                'roles.view',
                'menus.view',
                'settings.view',
            ],

            // ═══════════════════════════════════════════════════════════
            // HQ MANAGER
            // Oversees business operations, approvals, network & performance.
            // ═══════════════════════════════════════════════════════════
            SystemRole::HqManager->value => [
                'dashboard.view',

                // Branches — review & team oversight
                'branches.view', 'branches.approve', 'branches.reject',
                'branches.documents.view', 'branches.agreements.view', 'branches.team.view',
                'coverage_locations.view',

                // Merchants
                'merchants.view', 'merchants.approve', 'merchants.reject',
                'merchants.request_more_info',
                'merchants.documents.view', 'merchants.documents.verify',
                'merchants.locations.view', 'merchants.locations.verify',
                'marketplaces.view',

                // Customers
                'customers.view', 'customers.create', 'customers.edit',

                // Shipments & Operations
                'shipments.view', 'shipments.create', 'shipments.edit',
                'shipments.status', 'shipments.quote', 'shipments.lifecycle',
                'shipments.print_label', 'shipments.export',
                'shipment_tasks.view', 'shipment_tasks.status',

                'pickups.view', 'pickups.status',
                'deliveries.view', 'deliveries.status',
                'dispatches.view', 'dispatches.transfer_batches', 'dispatches.route_workflow',
                'transfers.view', 'transfers.receive_transit',

                // POD
                'pod.view', 'pod.collections',

                // Pricing overview
                'pricing.view', 'pricing.calculate',
                'pricing.coverage_health.view', 'pricing.network_health.view',
                'pricing.audit.view', 'pricing.simulator.use', 'pricing.quotes.view',
                'pricing.branch_rates.view', 'pricing.service_types.view',
                'pricing.transfer_lanes.view', 'pricing.transfer_routes.view',

                // Finance overview
                'invoices.view', 'settlements.view', 'merchant_settlements.view',
                'hq-commissions.bills',

                // Reports
                'reports.view', 'reports.export', 'reports.branches', 'reports.pod',
                'reports.merchants', 'reports.revenue', 'reports.shipments', 'reports.staff',

                // Support & Notifications
                'support.view', 'support.manage',
                'notifications.view', 'notifications.manage',

                // Staff & Users view
                'staff.view', 'users.view', 'audit_logs.view',
            ],

            // ═══════════════════════════════════════════════════════════
            // HQ ACCOUNTS STAFF
            // Headquarter Finance & Accounts management (billing, COD, settlements).
            // ═══════════════════════════════════════════════════════════
            SystemRole::HqAccountsStaff->value => [
                'dashboard.view',

                'merchants.view', 'merchants.documents.view',
                'customers.view',

                'shipments.view', 'shipments.invoice', 'shipments.export',

                // POD full cycle
                'pod.view', 'pod.collect', 'pod.confirm', 'pod.deposit',
                'pod.rider_deposit', 'pod.collections', 'pod.settle',

                // Billing & Invoices
                'invoices.view', 'invoices.create',
                'receipts.view', 'receipts.create',

                // Settlements
                'settlements.view', 'settlements.create', 'settlements.pay',
                'merchant_settlements.view', 'merchant_settlements.create', 'merchant_settlements.pay',

                // HQ Commissions & Gateways
                'hq-commissions.bills', 'hq-commissions.settings', 'hq-commissions.settlements',
                'payment-gateways.accounts', 'payment-gateways.catalog',
                'marketplaces.view',

                // Pricing view
                'pricing.view', 'pricing.calculate',
                'pricing.branch_rates.view', 'pricing.service_types.view', 'pricing.quotes.view',

                // Reports
                'reports.view', 'reports.export',
                'reports.revenue', 'reports.pod', 'reports.merchants', 'reports.branches', 'reports.shipments',

                'notifications.view',
            ],

            // ═══════════════════════════════════════════════════════════
            // HQ OPERATIONS STAFF
            // Central dispatch, transfers, routing & shipment monitoring.
            // ═══════════════════════════════════════════════════════════
            SystemRole::HqOperationsStaff->value => [
                'dashboard.view',

                'coverage_locations.view',
                'branches.view',
                'merchants.view',
                'customers.view',

                // Shipments & Tasks
                'shipments.view', 'shipments.create', 'shipments.edit',
                'shipments.cancel', 'shipments.status', 'shipments.quote',
                'shipments.assign_pickup', 'shipments.assign_delivery',
                'shipments.lifecycle', 'shipments.print_label', 'shipments.export',

                'shipment_tasks.view', 'shipment_tasks.assign', 'shipment_tasks.status',

                // Pickups & Deliveries
                'pickups.view', 'pickups.create', 'pickups.assign', 'pickups.assignable_staff', 'pickups.status',
                'pickups.accept', 'pickups.reschedule',
                'deliveries.view', 'deliveries.assign', 'deliveries.status', 'deliveries.accept',

                // Central Dispatches & Inter-Branch Transfers
                'dispatches.view', 'dispatches.create', 'dispatches.receive',
                'dispatches.dispatch', 'dispatches.transfer_batches', 'dispatches.route_workflow',

                'transfers.view', 'transfers.dispatch', 'transfers.receive',
                'transfers.receive_transit', 'transfers.repair',

                // Routing & Lanes View
                'pricing.view',
                'pricing.transfer_lanes.view',
                'pricing.transfer_routes.view',
                'pricing.simulator.use',

                // Reports & Support
                'reports.view', 'reports.shipments', 'reports.branches',
                'support.view',
                'notifications.view',
            ],

            // ═══════════════════════════════════════════════════════════
            // MAIN ADMIN
            // Full operational access. No system role/menu/delete control.
            // ═══════════════════════════════════════════════════════════
            SystemRole::MainAdmin->value => [
                'dashboard.view',

                // Branches — full management
                'branches.view', 'branches.create', 'branches.edit',
                'branches.approve', 'branches.reject', 'branches.suspend', 'branches.activate',
                'branches.documents.view', 'branches.documents.manage',
                'branches.agreements.view', 'branches.agreements.manage',
                'branches.team.view', 'branches.team.manage', 'branches.team.credentials',

                'coverage_locations.view', 'coverage_locations.create',
                'coverage_locations.edit', 'coverage_locations.delete',

                // Merchants
                'merchants.view', 'merchants.create', 'merchants.edit',
                'merchants.approve', 'merchants.reject', 'merchants.suspend',
                'merchants.request_more_info',
                'merchants.documents.view', 'merchants.documents.verify',
                'merchants.locations.view', 'merchants.locations.verify',

                // Customers
                'customers.view', 'customers.create', 'customers.edit',

                // Shipments
                'shipments.view', 'shipments.create', 'shipments.edit',
                'shipments.cancel', 'shipments.status', 'shipments.quote',
                'shipments.assign_pickup', 'shipments.assign_delivery',
                'shipments.lifecycle', 'shipments.invoice',
                'shipments.print_label', 'shipments.export',

                'shipment_tasks.view', 'shipment_tasks.assign', 'shipment_tasks.status',

                // Pickups / Deliveries / Dispatches
                'pickups.view', 'pickups.create', 'pickups.assign', 'pickups.status',
                'pickups.accept', 'pickups.picked_up', 'pickups.failed', 'pickups.reschedule',

                'deliveries.view', 'deliveries.assign', 'deliveries.status',
                'deliveries.accept', 'deliveries.out_for_delivery',
                'deliveries.delivered', 'deliveries.failed',

                'dispatches.view', 'dispatches.create', 'dispatches.receive',
                'dispatches.dispatch', 'dispatches.transfer_batches', 'dispatches.route_workflow',

                // Transfers page (TRs between branches)
                'transfers.view', 'transfers.dispatch', 'transfers.receive', 'transfers.receive_transit',
                'transfers.repair',

                // POD
                'pod.view', 'pod.collect', 'pod.confirm', 'pod.deposit',
                'pod.rider_deposit', 'pod.collections', 'pod.settle',

                // Pricing — full (no delete)
                'pricing.view', 'pricing.calculate',
                'pricing.settings.manage', 'pricing.service_types.manage',
                'pricing.branch_route_rates.view', 'pricing.branch_route_rates.manage',
                'pricing.transfer_lanes.manage', 'pricing.transfer_routes.manage',
                'pricing.coverage_health.view', 'pricing.network_health.view',
                'pricing.audit.view',
                'pricing.transfer_lanes.view', 'pricing.transfer_lanes.create',
                'pricing.transfer_lanes.update', 'pricing.transfer_lanes.status',
                'pricing.transfer_routes.view', 'pricing.transfer_routes.create',
                'pricing.transfer_routes.update', 'pricing.transfer_routes.status',
                'pricing.settings.view', 'pricing.settings.create',
                'pricing.settings.update', 'pricing.settings.activate',
                'pricing.service_types.view', 'pricing.service_types.create',
                'pricing.service_types.update', 'pricing.service_types.status',
                'pricing.branch_rates.view', 'pricing.branch_rates.create',
                'pricing.branch_rates.update', 'pricing.branch_rates.status',
                'pricing.simulator.use', 'pricing.quotes.view',

                // Finance
                'invoices.view', 'invoices.create',
                'receipts.view', 'receipts.create',
                'settlements.view', 'settlements.create', 'settlements.pay',
                'merchant_settlements.view', 'merchant_settlements.create', 'merchant_settlements.pay',

                // Integrations
                'api_keys.view', 'api_keys.manage',
                'webhooks.view', 'webhooks.manage', 'webhooks.retry', 'webhooks.test',

                // Logs
                'api_logs.view', 'audit_logs.view',
                'sms_logs.view', 'email_logs.view', 'webhook_logs.view',

                // Notifications / Reports / Support
                'notifications.view', 'notifications.manage', 'notifications.mark_sent',
                'reports.view', 'reports.export', 'reports.branches', 'reports.pod',
                'reports.merchants', 'reports.revenue', 'reports.shipments', 'reports.staff',
                'support.view', 'support.manage',

                // Users
                'users.view', 'users.manage',
                'settings.view',
            ],

            // ═══════════════════════════════════════════════════════════
            // PRICING MANAGER
            // Owns all pricing config. Read-only on branches.
            // ═══════════════════════════════════════════════════════════
            'pricing_manager' => [
                'dashboard.view',
                'branches.view',

                // Full pricing access
                'pricing.view', 'pricing.calculate',
                'pricing.settings.manage', 'pricing.service_types.manage',
                'pricing.branch_route_rates.view', 'pricing.branch_route_rates.manage',
                'pricing.transfer_lanes.manage', 'pricing.transfer_routes.manage',
                'pricing.coverage_health.view', 'pricing.network_health.view',
                'pricing.audit.view',
                'pricing.transfer_lanes.view', 'pricing.transfer_lanes.create',
                'pricing.transfer_lanes.update', 'pricing.transfer_lanes.status', 'pricing.transfer_lanes.delete',
                'pricing.transfer_routes.view', 'pricing.transfer_routes.create',
                'pricing.transfer_routes.update', 'pricing.transfer_routes.status', 'pricing.transfer_routes.delete',
                'pricing.settings.view', 'pricing.settings.create',
                'pricing.settings.update', 'pricing.settings.activate', 'pricing.settings.delete',
                'pricing.service_types.view', 'pricing.service_types.create',
                'pricing.service_types.update', 'pricing.service_types.status', 'pricing.service_types.delete',
                'pricing.branch_rates.view', 'pricing.branch_rates.create',
                'pricing.branch_rates.update', 'pricing.branch_rates.status', 'pricing.branch_rates.delete',
                'pricing.simulator.use',
                'pricing.quotes.view', 'pricing.quotes.delete',

                'audit_logs.view',
                'reports.view', 'reports.revenue',
            ],

            // ═══════════════════════════════════════════════════════════
            // BRANCH MANAGER
            // Manages one branch: operations + team. No system pricing config.
            // No top-level branches list. View-only on pricing.
            // ═══════════════════════════════════════════════════════════
            SystemRole::BranchManager->value => [
                'dashboard.view',

                // Own branch team only — NOT branches.view (hides Branches menu)
                'branches.team.view', 'branches.team.manage', 'branches.team.credentials',

                // Customers
                'customers.view', 'customers.create', 'customers.edit',

                // Shipments
                'shipments.view', 'shipments.create', 'shipments.edit',
                'shipments.status', 'shipments.quote',
                'shipments.assign_pickup', 'shipments.assign_delivery',
                'shipments.lifecycle', 'shipments.print_label',

                'shipment_tasks.view', 'shipment_tasks.assign', 'shipment_tasks.status',

                // Pickups / Deliveries / Dispatches
                'pickups.view', 'pickups.create', 'pickups.assign', 'pickups.status',
                'pickups.accept', 'pickups.picked_up', 'pickups.failed', 'pickups.reschedule',

                'deliveries.view', 'deliveries.assign', 'deliveries.status',
                'deliveries.accept', 'deliveries.out_for_delivery',
                'deliveries.delivered', 'deliveries.failed',

                'dispatches.view', 'dispatches.create', 'dispatches.receive',
                'dispatches.dispatch', 'dispatches.transfer_batches', 'dispatches.route_workflow',

                // Transfers page (TRs between branches)
                'transfers.view', 'transfers.dispatch', 'transfers.receive', 'transfers.receive_transit',

                // POD
                'pod.view', 'pod.collect', 'pod.deposit', 'pod.collections',

                // Pricing — VIEW ONLY (branch rates + simulator)
                'pricing.view',
                'pricing.branch_rates.view',
                'pricing.service_types.view',
                'pricing.simulator.use',

                // Notifications / Reports / Support
                'notifications.view',
                'reports.view', 'reports.branches', 'reports.shipments', 'reports.staff',
                'support.view', 'support.manage',

                // Staff portal access (to monitor riders/staff)
                'staff.dashboard', 'staff.pickups', 'staff.deliveries',
                'staff.pod', 'staff.rider_location',
            ],

            // ═══════════════════════════════════════════════════════════
            // SUB BRANCH MANAGER
            // Subset of branch_manager — local sub-branch only.
            // ═══════════════════════════════════════════════════════════
            SystemRole::SubBranchManager->value => [
                'dashboard.view',

                'branches.team.view', 'branches.team.manage', 'branches.team.credentials',

                'customers.view', 'customers.create', 'customers.edit',

                'shipments.view', 'shipments.create', 'shipments.status',
                'shipments.quote', 'shipments.lifecycle', 'shipments.print_label',

                'shipment_tasks.view', 'shipment_tasks.assign', 'shipment_tasks.status',

                'pickups.view', 'pickups.create', 'pickups.assign', 'pickups.status',
                'pickups.accept', 'pickups.picked_up', 'pickups.failed',

                'deliveries.view', 'deliveries.assign', 'deliveries.status',
                'deliveries.accept', 'deliveries.out_for_delivery',
                'deliveries.delivered', 'deliveries.failed',

                'dispatches.view', 'dispatches.receive', 'dispatches.route_workflow',

                'transfers.view', 'transfers.receive', 'transfers.receive_transit',

                'pod.view', 'pod.collect', 'pod.deposit',

                // Pricing — view only
                'pricing.view',
                'pricing.branch_rates.view',
                'pricing.service_types.view',
                'pricing.simulator.use',

                'notifications.view',

                'staff.dashboard', 'staff.pickups', 'staff.deliveries',
                'staff.pod', 'staff.rider_location',
            ],

            // ═══════════════════════════════════════════════════════════
            // BOOKING STAFF
            // Books shipments via ADMIN portal. No staff portal.
            // ═══════════════════════════════════════════════════════════
            SystemRole::BookingStaff->value => [
                'dashboard.view',

                'customers.view', 'customers.create', 'customers.edit',

                'shipments.view', 'shipments.create', 'shipments.edit',
                'shipments.quote', 'shipments.print_label',

                'pickups.view', 'pickups.create',

                // Pricing — view only for quoting
                'pricing.view', 'pricing.calculate',
                'pricing.branch_rates.view',
                'pricing.service_types.view',
                'pricing.simulator.use',

                'notifications.view',
            ],

            // ═══════════════════════════════════════════════════════════
            // PICKUP STAFF — staff portal only
            // ═══════════════════════════════════════════════════════════
            SystemRole::PickupStaff->value => [
                'staff.dashboard', 'staff.pickups',

                'shipments.view',

                // Pickup workflow - STRICT sequence
                'pickups.view',                      // View assigned pickups
                'pickups.accept',                    // Accept assignment
                'pickups.start',                     // Start pickup
                'pickups.arrive',                    // Arrive at store
                'pickups.collect',                   // Collect shipments
                'pickups.complete',                  // Complete pickup
                'pickups.receive',                   // Receive at origin
                'pickups.failed',                    // Mark as failed
                'pickups.assignable_staff',          // View assignable staff
                'pickups.failed',                    // Mark as failed if needed

                'notifications.view',
            ],

            // ═══════════════════════════════════════════════════════════
            // DISPATCH STAFF — staff portal + admin dispatch screens
            // ═══════════════════════════════════════════════════════════
            SystemRole::DispatchStaff->value => [
                'staff.dashboard',

                'shipments.view', 'shipments.status', 'shipments.lifecycle',

                'shipment_tasks.view', 'shipment_tasks.assign', 'shipment_tasks.status',

                'pickups.view', 'pickups.status',

                'deliveries.view', 'deliveries.assign', 'deliveries.status',

                'dispatches.view', 'dispatches.create', 'dispatches.receive',
                'dispatches.dispatch', 'dispatches.transfer_batches', 'dispatches.route_workflow',

                // View transfer lanes/routes for routing decisions
                'pricing.transfer_lanes.view',
                'pricing.transfer_routes.view',

                'notifications.view',
            ],

            // ═══════════════════════════════════════════════════════════
            // DELIVERY STAFF — staff portal only
            // ═══════════════════════════════════════════════════════════
            SystemRole::DeliveryStaff->value => [
                'staff.dashboard', 'staff.deliveries',

                'shipments.view',

                'deliveries.view', 'deliveries.status', 'deliveries.accept',
                'deliveries.out_for_delivery', 'deliveries.delivered', 'deliveries.failed',

                'notifications.view',
            ],

            // ═══════════════════════════════════════════════════════════
            // WAREHOUSE STAFF — staff portal, receiving only
            // ═══════════════════════════════════════════════════════════
            SystemRole::WarehouseStaff->value => [
                'staff.dashboard',

                'shipments.view', 'shipments.status',

                'dispatches.view', 'dispatches.receive',

                'notifications.view',
            ],

            // ═══════════════════════════════════════════════════════════
            // RIDER — staff portal, pickup + delivery + POD
            // ═══════════════════════════════════════════════════════════
            // RIDER — staff portal, pickup + delivery + POD
            // ═══════════════════════════════════════════════════════════
            SystemRole::Rider->value => [
                'staff.dashboard', 'staff.pickups', 'staff.deliveries',
                'staff.pod', 'staff.rider_location',

                'shipments.view',

                // Pickup workflow - STRICT sequence
                'pickups.view',                      // View assigned pickups
                'pickups.accept',                    // Accept assignment (ASSIGNED → ACCEPTED)
                'pickups.start',                     // Start pickup (ACCEPTED → STARTED)
                'pickups.arrive',                    // Arrive at store (STARTED → ARRIVED)
                'pickups.collect',                   // Collect shipments (ARRIVED → collect each)
                'pickups.complete',                  // Complete pickup (ARRIVED → COMPLETED)
                'pickups.receive',                   // Receive at origin branch
                'pickups.failed',                    // Mark as failed
                'pickups.assignable_staff',          // View assignable staff
                'pickups.transfer',                  // Transfer to another staff member

                // Delivery workflow
                'deliveries.view', 'deliveries.status', 'deliveries.accept',
                'deliveries.out_for_delivery', 'deliveries.delivered', 'deliveries.failed',

                // POD workflow
                'pod.view', 'pod.collect',

                'notifications.view',
            ],

            // ═══════════════════════════════════════════════════════════
            // ACCOUNTS STAFF — staff portal, finance
            // ═══════════════════════════════════════════════════════════
            SystemRole::AccountsStaff->value => [
                'staff.dashboard',

                'merchants.view',
                'shipments.view',

                // POD full cycle
                'pod.view', 'pod.collect', 'pod.confirm', 'pod.deposit',
                'pod.rider_deposit', 'pod.collections', 'pod.settle',

                // Finance
                'settlements.view', 'settlements.create', 'settlements.pay',
                'merchant_settlements.view', 'merchant_settlements.create', 'merchant_settlements.pay',
                'invoices.view', 'invoices.create',
                'receipts.view', 'receipts.create',

                // Reports
                'reports.view', 'reports.export',
                'reports.pod', 'reports.revenue', 'reports.merchants',

                'notifications.view',
            ],

            // ═══════════════════════════════════════════════════════════
            // SUPPORT STAFF — staff portal, read + tickets
            // ═══════════════════════════════════════════════════════════
            SystemRole::SupportStaff->value => [
                'staff.dashboard',

                'shipments.view',
                'customers.view',
                'merchants.view', 'merchants.documents.view', 'merchants.locations.view',
                'pickups.view', 'deliveries.view', 'dispatches.view',

                'support.view', 'support.manage',

                'notifications.view',
                'api_logs.view', 'webhook_logs.view',
                'sms_logs.view', 'email_logs.view',
            ],

            // ═══════════════════════════════════════════════════════════
            // BRANCH STAFF (generic catch-all for branch-level staff)
            // ═══════════════════════════════════════════════════════════
            SystemRole::BranchStaff->value => [
                'staff.dashboard',
                'shipments.view',
                'pickups.view',
                'deliveries.view',
                'notifications.view',
            ],

            // ═══════════════════════════════════════════════════════════
            // MERCHANT — merchant portal only
            // ═══════════════════════════════════════════════════════════
            SystemRole::Merchant->value => [
                'merchant.onboarding', 'merchant.profile', 'merchant.documents',
                'merchant.locations', 'merchant.bank_details', 'merchant.submit_verification',
                'merchant.dashboard', 'merchant.shipments', 'merchant.pickups',
                'merchant.pickup_locations', 'merchant.customers', 'merchant.rates',
                'merchant.pod', 'merchant.settlements', 'merchant.invoices',
                'merchant.api_keys', 'merchant.api_logs',
                'merchant.webhooks', 'merchant.webhook_logs',
                'merchant.support',
            ],
        ];
    }
}
