<?php

namespace Database\Seeders\System;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::permissionsByGroup() as $group => $names) {
            foreach ($names as $name) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], [
                    'group' => $group,
                    'description' => ucwords(str_replace(['.', '_'], ' ', $name)),
                ]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public static function allPermissionNames(): array
    {
        return collect(self::permissionsByGroup())->flatten()->values()->all();
    }

    public static function permissionsByGroup(): array
    {
        return [
            'dashboard' => ['dashboard.view'],
            'branches' => ['branches.view','branches.create','branches.edit','branches.delete'],
            'users' => ['users.view','users.manage'],
            'roles' => ['roles.view','roles.manage','menus.manage'],
            'merchants' => ['merchants.view','merchants.create','merchants.edit','merchants.delete','merchants.approve'],
            'api_keys' => ['api_keys.view','api_keys.manage'],
            'customers' => ['customers.view','customers.create','customers.edit','customers.delete'],
            'rates' => ['rates.view','rates.manage','rates.calculate'],
            'shipments' => ['shipments.view','shipments.create','shipments.edit','shipments.cancel','shipments.status'],
            'pickups' => ['pickups.view','pickups.create','pickups.assign','pickups.status'],
            'dispatches' => ['dispatches.view','dispatches.create','dispatches.receive'],
            'deliveries' => ['deliveries.view','deliveries.assign','deliveries.status'],
            'cod' => ['cod.view','cod.collect','cod.deposit','cod.confirm'],
            'settlements' => ['settlements.view','settlements.create','settlements.pay'],
            'billing' => ['invoices.view','invoices.create','receipts.create'],
            'webhooks' => ['webhooks.view','webhooks.manage','webhooks.retry'],
            'notifications' => ['notifications.view','notifications.manage'],
            'reports' => ['reports.view','reports.export'],
            'settings' => ['settings.view','settings.manage'],
            'support' => ['support.view','support.manage'],
            'logs' => ['api_logs.view','audit_logs.view'],
            'merchant_portal' => ['merchant.dashboard','merchant.shipments','merchant.pickups','merchant.cod','merchant.settlements','merchant.invoices','merchant.api_keys','merchant.webhooks','merchant.support'],
            'staff_portal' => ['staff.dashboard','staff.pickups','staff.deliveries','staff.cod'],
        ];
    }
}
