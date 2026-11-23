<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Modules\Access\Enums\SystemRole;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = Guard::getDefaultName(User::class);

        // 1. Ensure any missing HQ-specific permissions exist in the permissions table
        $extraPermissions = [
            'HQ Commissions' => [
                'hq-commissions.bills'        => 'HQ Commission Bills View',
                'hq-commissions.settings'     => 'HQ Commission Settings',
                'hq-commissions.settlements'  => 'HQ Commission Settlements',
            ],
            'Payment Gateways' => [
                'payment-gateways.accounts'   => 'Payment Gateway Accounts',
                'payment-gateways.catalog'    => 'Payment Gateway Catalog',
            ],
        ];

        foreach ($extraPermissions as $groupName => $perms) {
            foreach ($perms as $permName => $displayName) {
                $permission = Permission::query()->firstOrCreate([
                    'name'       => $permName,
                    'guard_name' => $guard,
                ]);

                $updates = [];
                if (Schema::hasColumn('permissions', 'display_name')) {
                    $updates['display_name'] = $displayName;
                }
                if (Schema::hasColumn('permissions', 'label')) {
                    $updates['label'] = $displayName;
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

        // Available permissions for this guard
        $allPermissions = Permission::query()
            ->where('guard_name', $guard)
            ->pluck('name')
            ->toArray();

        // 2. Define Headquarter Roles with metadata & permission sets
        $hqRoles = [
            SystemRole::HqAdmin->value => [
                'label'       => 'HQ Admin',
                'description' => 'Headquarter Administrator with full operational management permissions',
                'permissions' => [
                    'dashboard.view',

                    // Branches
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

                    // Transfers
                    'transfers.view', 'transfers.dispatch', 'transfers.receive', 'transfers.receive_transit',
                    'transfers.repair',

                    // POD
                    'pod.view', 'pod.collect', 'pod.confirm', 'pod.deposit',
                    'pod.rider_deposit', 'pod.collections', 'pod.settle',

                    // Pricing
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
            ],

            SystemRole::HqManager->value => [
                'label'       => 'HQ Manager',
                'description' => 'Headquarter Manager overseeing overall operations and approvals',
                'permissions' => [
                    'dashboard.view',

                    // Branches
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
            ],

            SystemRole::HqAccountsStaff->value => [
                'label'       => 'HQ Accounts Staff',
                'description' => 'Headquarter Accounts and Finance Staff',
                'permissions' => [
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
            ],

            SystemRole::HqOperationsStaff->value => [
                'label'       => 'HQ Operations Staff',
                'description' => 'Headquarter Operations Staff managing central dispatch, transfers, and routing',
                'permissions' => [
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
            ],
        ];

        foreach ($hqRoles as $roleName => $roleData) {
            $role = Role::query()->firstOrCreate([
                'name'       => $roleName,
                'guard_name' => $guard,
            ]);

            $updates = [];
            if (Schema::hasColumn('roles', 'label')) {
                $updates['label'] = $roleData['label'];
            }
            if (Schema::hasColumn('roles', 'display_name')) {
                $updates['display_name'] = $roleData['label'];
            }
            if (Schema::hasColumn('roles', 'description')) {
                $updates['description'] = $roleData['description'];
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

            // Sync valid existing permissions
            $valid = array_values(array_unique(array_intersect($roleData['permissions'], $allPermissions)));
            $role->syncPermissions($valid);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Safe down: do not delete roles to avoid cascade orphaned users.
        // Cache can be cleared.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
