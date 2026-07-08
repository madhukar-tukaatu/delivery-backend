<?php

namespace Database\Seeders\System;

use Illuminate\Database\Seeder;
use Modules\Access\Models\MenuItem;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $menus = [
            // Admin section
            ['admin', 'Dashboard', '/admin/dashboard', 'dashboard', 'dashboard.view', 1],
            ['admin', 'Merchant Applications', '/admin/merchant-applications', 'store', 'merchant_applications.view', 5],
            ['admin', 'Merchants', '/admin/merchants', 'store', 'merchants.view', 6],
            ['admin', 'Shipments', '/admin/shipments', 'package', 'shipments.view', 10],
            ['admin', 'Pickups', '/admin/pickups', 'pickup', 'pickups.view', 11],
            ['admin', 'Dispatch', '/admin/dispatch', 'route', 'dispatch.view', 12],
            ['admin', 'Deliveries', '/admin/deliveries', 'truck', 'deliveries.view', 13],
            ['admin', 'COD', '/admin/cod', 'money', 'cod.view', 14],
            ['admin', 'Settlements', '/admin/settlements', 'settlement', 'settlements.view', 15],
            ['admin', 'Branches', '/admin/branches', 'branch', 'branches.view', 20],
            ['admin', 'Users', '/admin/users', 'users', 'users.view', 30],
            ['admin', 'Roles & Permissions', '/admin/roles', 'shield', 'roles.view', 31],
            ['admin', 'Menus', '/admin/menus', 'menu', 'menus.view', 32],
            ['admin', 'Permission Sync', '/admin/access-sync', 'refresh', 'access.sync', 99],

            // Merchant section
            ['merchant', 'Onboarding', '/merchant/onboarding', 'checklist', 'merchant_onboarding.view', 1],
            ['merchant', 'Create Shipment', '/merchant/create-shipment', 'package-plus', 'shipments.create', 5],
            ['merchant', 'Shipments', '/merchant/shipments', 'package', 'shipments.view', 6],
            ['merchant', 'Pickup Locations', '/merchant/pickup-locations', 'pickup', 'merchant_pickup_locations.view', 7],
            ['merchant', 'COD', '/merchant/cod', 'money', 'cod.view', 8],
            ['merchant', 'Settlements', '/merchant/settlements', 'settlement', 'settlements.view', 9],

            // Staff section
            ['staff', 'Pickups', '/staff/pickups', 'pickup', 'pickups.view', 1],
            ['staff', 'Deliveries', '/staff/deliveries', 'truck', 'deliveries.view', 2],
        ];

        foreach ($menus as [$section, $label, $path, $icon, $permission, $sortOrder]) {
            MenuItem::updateOrCreate(
                [
                    'section' => $section,
                    'path' => $path,
                ],
                [
                    'label' => $label,
                    'icon' => $icon,
                    'permission' => $permission,
                    'sort_order' => $sortOrder,
                    'is_active' => true,
                    'parent_id' => null,
                ]
            );
        }
    }
}
