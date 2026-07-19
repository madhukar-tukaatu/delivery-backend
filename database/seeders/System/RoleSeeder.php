<?php

namespace Database\Seeders\System;

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

        /*
        |--------------------------------------------------------------------------
        | Make sure permissions exist before syncing roles
        |--------------------------------------------------------------------------
        */
        $this->call(PermissionSeeder::class);

        $allPermissions = Permission::query()
            ->where('guard_name', $this->guardName)
            ->pluck('name')
            ->toArray();

        $roleMap = $this->rolePermissionMap($allPermissions);

        foreach ($roleMap as $roleName => $permissions) {
            $role = Role::query()->firstOrCreate([
                'name' => $roleName,
                'guard_name' => $this->guardName,
            ]);

            $updates = [];

            if (Schema::hasColumn('roles', 'label')) {
                $updates['label'] = ucwords(str_replace('_', ' ', $roleName));
            }

            if (Schema::hasColumn('roles', 'display_name')) {
                $updates['display_name'] = ucwords(str_replace('_', ' ', $roleName));
            }

            if (Schema::hasColumn('roles', 'description')) {
                $updates['description'] = ucwords(str_replace('_', ' ', $roleName));
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

            $validPermissions = $this->onlyExistingPermissions($permissions, $allPermissions);

            $role->syncPermissions($validPermissions);

            $missingPermissions = array_values(array_diff($permissions, $validPermissions));

            if (!empty($missingPermissions)) {
                $this->command?->warn(
                    $roleName . ' missing permissions skipped: ' . implode(', ', $missingPermissions)
                );
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Roles and role permissions seeded successfully.');
    }

    private function onlyExistingPermissions(array $permissions, array $allPermissions): array
    {
        return array_values(array_unique(array_intersect($permissions, $allPermissions)));
    }

    private function rolePermissionMap(array $allPermissions): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Super Admin
            |--------------------------------------------------------------------------
            | Full system access.
            */
            'super_admin' => $allPermissions,

            /*
            |--------------------------------------------------------------------------
            | Main Admin
            |--------------------------------------------------------------------------
            | Full operational access, but no system role/menu/settings/delete control.
            */
            'main_admin' => [
                'dashboard.view',

                'branches.view',
                'branches.create',
                'branches.edit',
                'branches.approve',
                'branches.reject',
                'branches.suspend',
                'branches.activate',
                'branches.documents.view',
                'branches.documents.manage',
                'branches.agreements.view',
                'branches.agreements.manage',

                'merchants.view',
                'merchants.create',
                'merchants.edit',
                'merchants.approve',
                'merchants.reject',
                'merchants.suspend',
                'merchants.request_more_info',
                'merchants.documents.view',
                'merchants.documents.verify',
                'merchants.locations.view',
                'merchants.locations.verify',

                'customers.view',
                'customers.create',
                'customers.edit',

                'shipments.view',
                'shipments.create',
                'shipments.edit',
                'shipments.cancel',
                'shipments.status',
                'shipments.quote',
                'shipments.assign_pickup',
                'shipments.assign_delivery',
                'shipments.lifecycle',
                'shipments.invoice',
                'shipments.print_label',
                'shipments.export',

                'shipment_tasks.view',
                'shipment_tasks.assign',
                'shipment_tasks.status',

                'pickups.view',
                'pickups.create',
                'pickups.assign',
                'pickups.status',
                'pickups.accept',
                'pickups.picked_up',
                'pickups.failed',
                'pickups.reschedule',

                'deliveries.view',
                'deliveries.assign',
                'deliveries.status',
                'deliveries.accept',
                'deliveries.out_for_delivery',
                'deliveries.delivered',
                'deliveries.failed',

                'dispatches.view',
                'dispatches.create',
                'dispatches.receive',
                'dispatches.dispatch',
                'dispatches.transfer_batches',
                'dispatches.route_workflow',

                'pod.view',
                'pod.collect',
                'pod.confirm',
                'pod.deposit',
                'pod.rider_deposit',
                'pod.collections',
                'pod.settle',

                'rates.view',
                'rates.calculate',
                'rates.manage',
                'rates.service_types',
                'rates.branch_pricing',
                'rates.transfer_lanes',

                'invoices.view',
                'invoices.create',
                'receipts.view',
                'receipts.create',

                'settlements.view',
                'settlements.create',
                'settlements.pay',
                'merchant_settlements.view',
                'merchant_settlements.create',
                'merchant_settlements.pay',

                'api_keys.view',
                'api_keys.manage',

                'webhooks.view',
                'webhooks.manage',
                'webhooks.retry',
                'webhooks.test',

                'notifications.view',
                'notifications.manage',
                'notifications.mark_sent',

                'reports.view',
                'reports.export',
                'reports.branches',
                'reports.pod',
                'reports.merchants',
                'reports.revenue',
                'reports.shipments',
                'reports.staff',

                'api_logs.view',
                'audit_logs.view',
                'sms_logs.view',
                'email_logs.view',
                'webhook_logs.view',

                'support.view',
                'support.manage',

                'users.view',
                'users.manage',

                'settings.view',
            ],

            /*
            |--------------------------------------------------------------------------
            | Branch Manager
            |--------------------------------------------------------------------------
            | Manages one branch operation.
            */
            'branch_manager' => [
                'dashboard.view',

                'branches.view',

                'customers.view',
                'customers.create',
                'customers.edit',

                'shipments.view',
                'shipments.create',
                'shipments.edit',
                'shipments.status',
                'shipments.quote',
                'shipments.assign_pickup',
                'shipments.assign_delivery',
                'shipments.lifecycle',
                'shipments.print_label',

                'shipment_tasks.view',
                'shipment_tasks.assign',
                'shipment_tasks.status',

                'pickups.view',
                'pickups.create',
                'pickups.assign',
                'pickups.status',
                'pickups.accept',
                'pickups.picked_up',
                'pickups.failed',
                'pickups.reschedule',

                'deliveries.view',
                'deliveries.assign',
                'deliveries.status',
                'deliveries.accept',
                'deliveries.out_for_delivery',
                'deliveries.delivered',
                'deliveries.failed',

                'dispatches.view',
                'dispatches.create',
                'dispatches.receive',
                'dispatches.dispatch',
                'dispatches.transfer_batches',
                'dispatches.route_workflow',

                'pod.view',
                'pod.collect',
                'pod.deposit',
                'pod.collections',

                'rates.view',
                'rates.calculate',

                'notifications.view',

                'reports.view',
                'reports.branches',
                'reports.shipments',
                'reports.staff',

                'support.view',
                'support.manage',

                'staff.dashboard',
                'staff.pickups',
                'staff.deliveries',
                'staff.pod',
                'staff.rider_location',
            ],

            /*
            |--------------------------------------------------------------------------
            | Sub Branch Manager
            |--------------------------------------------------------------------------
            | Local sub-branch operation only.
            */
            'sub_branch_manager' => [
                'dashboard.view',

                'branches.view',

                'customers.view',
                'customers.create',
                'customers.edit',

                'shipments.view',
                'shipments.create',
                'shipments.status',
                'shipments.quote',
                'shipments.lifecycle',
                'shipments.print_label',

                'shipment_tasks.view',
                'shipment_tasks.assign',
                'shipment_tasks.status',

                'pickups.view',
                'pickups.create',
                'pickups.assign',
                'pickups.status',
                'pickups.accept',
                'pickups.picked_up',
                'pickups.failed',

                'deliveries.view',
                'deliveries.assign',
                'deliveries.status',
                'deliveries.accept',
                'deliveries.out_for_delivery',
                'deliveries.delivered',
                'deliveries.failed',

                'dispatches.view',
                'dispatches.receive',
                'dispatches.route_workflow',

                'pod.view',
                'pod.collect',
                'pod.deposit',

                'rates.calculate',

                'notifications.view',

                'staff.dashboard',
                'staff.pickups',
                'staff.deliveries',
                'staff.pod',
                'staff.rider_location',
            ],

            /*
            |--------------------------------------------------------------------------
            | Booking Staff
            |--------------------------------------------------------------------------
            | Shipment booking, customers, quote calculation.
            */
            'booking_staff' => [
                'dashboard.view',

                'customers.view',
                'customers.create',
                'customers.edit',

                'shipments.view',
                'shipments.create',
                'shipments.edit',
                'shipments.quote',
                'shipments.print_label',

                'pickups.view',
                'pickups.create',

                'rates.view',
                'rates.calculate',

                'notifications.view',
            ],

            /*
            |--------------------------------------------------------------------------
            | Pickup Staff
            |--------------------------------------------------------------------------
            | Assigned pickup work only.
            */
            'pickup_staff' => [
                'staff.dashboard',
                'staff.pickups',

                'shipments.view',

                'pickups.view',
                'pickups.status',
                'pickups.accept',
                'pickups.picked_up',
                'pickups.failed',

                'notifications.view',
            ],

            /*
            |--------------------------------------------------------------------------
            | Dispatch Staff
            |--------------------------------------------------------------------------
            | Transfer, dispatch and branch route workflow.
            */
            'dispatch_staff' => [
                'dashboard.view',

                'branches.view',

                'shipments.view',
                'shipments.status',
                'shipments.lifecycle',

                'shipment_tasks.view',
                'shipment_tasks.assign',
                'shipment_tasks.status',

                'pickups.view',
                'pickups.status',

                'deliveries.view',
                'deliveries.assign',
                'deliveries.status',

                'dispatches.view',
                'dispatches.create',
                'dispatches.receive',
                'dispatches.dispatch',
                'dispatches.transfer_batches',
                'dispatches.route_workflow',

                'notifications.view',
            ],

            /*
            |--------------------------------------------------------------------------
            | Rider
            |--------------------------------------------------------------------------
            | Pickup/delivery rider portal.
            */
            'rider' => [
                'staff.dashboard',
                'staff.pickups',
                'staff.deliveries',
                'staff.pod',
                'staff.rider_location',

                'shipments.view',

                'pickups.view',
                'pickups.status',
                'pickups.accept',
                'pickups.picked_up',
                'pickups.failed',

                'deliveries.view',
                'deliveries.status',
                'deliveries.accept',
                'deliveries.out_for_delivery',
                'deliveries.delivered',
                'deliveries.failed',

                'pod.view',
                'pod.collect',

                'notifications.view',
            ],

            /*
            |--------------------------------------------------------------------------
            | Accounts Staff
            |--------------------------------------------------------------------------
            | POD, settlements, invoices, receipts and reports.
            */
            'accounts_staff' => [
                'dashboard.view',

                'merchants.view',

                'shipments.view',

                'pod.view',
                'pod.collect',
                'pod.confirm',
                'pod.deposit',
                'pod.rider_deposit',
                'pod.collections',
                'pod.settle',

                'settlements.view',
                'settlements.create',
                'settlements.pay',

                'merchant_settlements.view',
                'merchant_settlements.create',
                'merchant_settlements.pay',

                'invoices.view',
                'invoices.create',
                'receipts.view',
                'receipts.create',

                'reports.view',
                'reports.export',
                'reports.pod',
                'reports.revenue',
                'reports.merchants',

                'api_logs.view',
                'webhook_logs.view',

                'notifications.view',
            ],

            /*
            |--------------------------------------------------------------------------
            | Support Staff
            |--------------------------------------------------------------------------
            | View shipment/customer/merchant and handle tickets.
            */
            'support_staff' => [
                'dashboard.view',

                'shipments.view',

                'customers.view',

                'merchants.view',
                'merchants.documents.view',
                'merchants.locations.view',

                'pickups.view',
                'deliveries.view',
                'dispatches.view',

                'support.view',
                'support.manage',

                'notifications.view',

                'api_logs.view',
                'webhook_logs.view',
                'sms_logs.view',
                'email_logs.view',
            ],

            /*
            |--------------------------------------------------------------------------
            | Merchant
            |--------------------------------------------------------------------------
            | Merchant-scoped permissions only.
            | Do not give admin permissions like shipments.view or api_keys.manage.
            | Pending/active access should be controlled by merchant status middleware.
            */
            'merchant' => [
                'merchant.onboarding',
                'merchant.profile',
                'merchant.documents',
                'merchant.locations',
                'merchant.bank_details',
                'merchant.submit_verification',

                'merchant.dashboard',
                'merchant.shipments',
                'merchant.pickups',
                'merchant.pickup_locations',
                'merchant.customers',
                'merchant.rates',
                'merchant.pod',
                'merchant.settlements',
                'merchant.invoices',
                'merchant.api_keys',
                'merchant.api_logs',
                'merchant.webhooks',
                'merchant.webhook_logs',
                'merchant.support',
            ],
        ];
    }
}