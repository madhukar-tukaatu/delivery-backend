<?php

namespace Database\Seeders\System;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $all = PermissionSeeder::allPermissionNames();
        $map = [
            'super_admin' => $all,
            'main_admin' => $all,
            'branch_manager' => ['dashboard.view','branches.view','users.view','merchants.view','customers.view','customers.create','customers.edit','customers.delete','shipments.view','shipments.create','shipments.edit','shipments.status','pickups.view','pickups.create','pickups.assign','pickups.status','dispatches.view','dispatches.create','dispatches.receive','deliveries.view','deliveries.assign','deliveries.status','cod.view','cod.collect','cod.deposit','reports.view','support.view','support.manage'],
            'sub_branch_manager' => ['dashboard.view','branches.view','customers.view','customers.create','customers.edit','shipments.view','shipments.create','shipments.status','pickups.view','pickups.create','pickups.assign','pickups.status','dispatches.view','dispatches.create','dispatches.receive','deliveries.view','deliveries.assign','deliveries.status','cod.view','cod.collect','cod.deposit','reports.view'],
            'booking_staff' => ['dashboard.view','customers.view','customers.create','shipments.view','shipments.create','shipments.edit','pickups.view','pickups.create','rates.calculate'],
            'pickup_staff' => ['staff.dashboard','staff.pickups','pickups.view','pickups.status','shipments.view'],
            'dispatch_staff' => ['dashboard.view','shipments.view','shipments.status','dispatches.view','dispatches.create','dispatches.receive','deliveries.view'],
            'rider' => ['staff.dashboard','staff.deliveries','staff.cod','deliveries.view','deliveries.status','cod.view','cod.collect','cod.deposit','shipments.view'],
            'accounts_staff' => ['dashboard.view','merchants.view','shipments.view','cod.view','cod.confirm','settlements.view','settlements.create','settlements.pay','invoices.view','invoices.create','receipts.create','reports.view'],
            'support_staff' => ['dashboard.view','shipments.view','customers.view','merchants.view','support.view','support.manage','webhooks.view','api_logs.view'],
            'merchant' => ['merchant.dashboard','merchant.shipments','merchant.pickups','merchant.cod','merchant.settlements','merchant.invoices','merchant.api_keys','merchant.webhooks','merchant.support','shipments.view','shipments.create','shipments.cancel','pickups.view','pickups.create','rates.calculate','cod.view','settlements.view','invoices.view','api_keys.view','api_keys.manage','webhooks.view','webhooks.manage','support.view','support.manage','customers.view','customers.create','customers.edit'],
        ];
        foreach ($map as $name => $permissions) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'], [
                'label' => ucwords(str_replace('_', ' ', $name)),
                'is_system' => true,
            ]);
            $role->syncPermissions($permissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
