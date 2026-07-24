<?php

namespace Database\Seeders\System;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Access\Models\MenuItem;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $table = (new MenuItem())->getTable();

        $this->seedAdminMenus($table);
        $this->seedMerchantMenus($table);
        $this->seedStaffMenus($table);

        $this->command?->info('Menus seeded successfully.');
    }

    private function seedAdminMenus(string $table): void
    {
        /*
         * Remove the old pricing-settings URL because the page now lives at
         * /admin/rates. Other pricing rows are updated by route, so reseeding
         * changes their permission without creating duplicates.
         */
        foreach ([
            '/admin/rate-cards',
            '/admin/pricing-settings',
        ] as $legacyPricingRoute) {
            $this->deleteMenuByRoute(
                table: $table,
                section: 'admin',
                route: $legacyPricingRoute
            );
        }

        $menus = [
            [
                'section' => 'admin',
                'title' => 'Dashboard',
                'label' => 'Dashboard',
                'route' => '/admin/dashboard',
                'icon' => 'dashboard',
                'permission' => 'dashboard.view',
                'sort_order' => 10,
            ],
            [
                'section' => 'admin',
                'title' => 'Branches',
                'label' => 'Branches',
                'route' => '/admin/branches',
                'icon' => 'branches',
                'permission' => 'branches.view',
                'sort_order' => 20,
            ],
            [
                'section' => 'admin',
                'title' => 'Branch Allocation',
                'label' => 'Branch Allocation',
                'route' => '/admin/coverage-locations',
                'icon' => 'location',
                'permission' => 'coverage_locations.view',
                'sort_order' => 21,
            ],
            [
                'section' => 'admin',
                'title' => 'Franchise / Branch Offices',
                'label' => 'Franchise / Branch Offices',
                'route' => '/admin/branch-offices',
                'icon' => 'branches',
                'permission' => 'branches.view',
                'sort_order' => 22,
            ],
            [
                'section' => 'admin',
                'title' => 'Merchants',
                'label' => 'Merchants',
                'route' => '/admin/merchants',
                'icon' => 'merchants',
                'permission' => 'merchants.view',
                'sort_order' => 30,
            ],
            [
                'section' => 'admin',
                'title' => 'Merchant Applications',
                'label' => 'Merchant Applications',
                'route' => '/admin/merchant-applications',
                'icon' => 'merchant-applications',
                'permission' => 'merchants.view',
                'sort_order' => 31,
            ],
            [
                'section' => 'admin',
                'title' => 'Customers',
                'label' => 'Customers',
                'route' => '/admin/customers',
                'icon' => 'customers',
                'permission' => 'customers.view',
                'sort_order' => 35,
            ],
            [
                'section' => 'admin',
                'title' => 'Shipments',
                'label' => 'Shipments',
                'route' => '/admin/shipments',
                'icon' => 'shipments',
                'permission' => 'shipments.view',
                'sort_order' => 40,
            ],
            [
                'section' => 'admin',
                'title' => 'Shipment Tasks',
                'label' => 'Shipment Tasks',
                'route' => '/admin/shipment-tasks',
                'icon' => 'tasks',
                'permission' => 'shipment_tasks.view',
                'sort_order' => 41,
            ],
            [
                'section' => 'admin',
                'title' => 'Pickups',
                'label' => 'Pickups',
                'route' => '/admin/pickups',
                'icon' => 'pickups',
                'permission' => 'pickups.view',
                'sort_order' => 50,
            ],
            [
                'section' => 'admin',
                'title' => 'Deliveries',
                'label' => 'Deliveries',
                'route' => '/admin/deliveries',
                'icon' => 'deliveries',
                'permission' => 'deliveries.view',
                'sort_order' => 60,
            ],
            [
                'section' => 'admin',
                'title' => 'Dispatches',
                'label' => 'Dispatches',
                'route' => '/admin/dispatches',
                'icon' => 'dispatches',
                'permission' => 'dispatches.view',
                'sort_order' => 70,
            ],
            [
                'section' => 'admin',
                'title' => 'POD',
                'label' => 'POD',
                'route' => '/admin/pod',
                'icon' => 'pod',
                'permission' => 'pod.view',
                'sort_order' => 80,
            ],
            [
                'section' => 'admin',
                'title' => 'Pricing Settings',
                'label' => 'Pricing Settings',
                'route' => '/admin/rates',
                'icon' => 'rates',
                'permission' => 'pricing.settings.view',
                'sort_order' => 90,
            ],
            [
                'section' => 'admin',
                'title' => 'Service Types',
                'label' => 'Service Types',
                'route' => '/admin/service-types',
                'icon' => 'service-types',
                'permission' => 'pricing.service_types.view',
                'sort_order' => 91,
            ],
            [
                'section' => 'admin',
                'title' => 'Branch Pricing',
                'label' => 'Branch Pricing',
                'route' => '/admin/branch-pricing',
                'icon' => 'pricing',
                'permission' => 'pricing.branch_rates.view',
                'sort_order' => 92,
            ],
            [
                'section' => 'admin',
                'title' => 'Price Simulator',
                'label' => 'Price Simulator',
                'route' => '/admin/pricing-test',
                'icon' => 'calculator',
                'permission' => 'pricing.simulator.use',
                'sort_order' => 93,
            ],
            [
                'section' => 'admin',
                'title' => 'Pricing Quotes',
                'label' => 'Pricing Quotes',
                'route' => '/admin/pricing-quotes',
                'icon' => 'quotes',
                'permission' => 'pricing.quotes.view',
                'sort_order' => 94,
            ],
            [
                'section' => 'admin',
                'title' => 'Transfer Lanes',
                'label' => 'Transfer Lanes',
                'route' => '/admin/branch-transfer-lanes',
                'icon' => 'transfer',
                'permission' => 'rates.transfer_lanes',
                'sort_order' => 95,
            ],
            [
                'section' => 'admin',
                'title' => 'Settlements',
                'label' => 'Settlements',
                'route' => '/admin/settlements',
                'icon' => 'settlements',
                'permission' => 'settlements.view',
                'sort_order' => 100,
            ],
            [
                'section' => 'admin',
                'title' => 'Invoices',
                'label' => 'Invoices',
                'route' => '/admin/invoices',
                'icon' => 'invoices',
                'permission' => 'invoices.view',
                'sort_order' => 110,
            ],
            [
                'section' => 'admin',
                'title' => 'API Keys',
                'label' => 'API Keys',
                'route' => '/admin/api-keys',
                'icon' => 'api-keys',
                'permission' => 'api_keys.view',
                'sort_order' => 115,
            ],
            [
                'section' => 'admin',
                'title' => 'API Logs',
                'label' => 'API Logs',
                'route' => '/admin/api-logs',
                'icon' => 'api-logs',
                'permission' => 'api_logs.view',
                'sort_order' => 116,
            ],
            [
                'section' => 'admin',
                'title' => 'Webhooks',
                'label' => 'Webhooks',
                'route' => '/admin/webhooks',
                'icon' => 'webhooks',
                'permission' => 'webhooks.view',
                'sort_order' => 117,
            ],
            [
                'section' => 'admin',
                'title' => 'Webhook Logs',
                'label' => 'Webhook Logs',
                'route' => '/admin/webhook-logs',
                'icon' => 'webhook-logs',
                'permission' => 'webhook_logs.view',
                'sort_order' => 118,
            ],
            [
                'section' => 'admin',
                'title' => 'Notifications',
                'label' => 'Notifications',
                'route' => '/admin/notifications',
                'icon' => 'notifications',
                'permission' => 'notifications.view',
                'sort_order' => 120,
            ],
            [
                'section' => 'admin',
                'title' => 'Reports',
                'label' => 'Reports',
                'route' => '/admin/reports/shipments',
                'icon' => 'reports',
                'permission' => 'reports.view',
                'sort_order' => 125,
            ],
            [
                'section' => 'admin',
                'title' => 'Support',
                'label' => 'Support',
                'route' => '/admin/support-tickets',
                'icon' => 'support',
                'permission' => 'support.view',
                'sort_order' => 130,
            ],
            [
                'section' => 'admin',
                'title' => 'Staff',
                'label' => 'Staff',
                'route' => '/admin/staff',
                'icon' => 'staff',
                'permission' => 'users.view',
                'sort_order' => 135,
            ],
            [
                'section' => 'admin',
                'title' => 'Users',
                'label' => 'Users',
                'route' => '/admin/users',
                'icon' => 'users',
                'permission' => 'users.view',
                'sort_order' => 140,
            ],
            [
                'section' => 'admin',
                'title' => 'Roles',
                'label' => 'Roles',
                'route' => '/admin/roles',
                'icon' => 'roles',
                'permission' => 'roles.view',
                'sort_order' => 150,
            ],
            [
                'section' => 'admin',
                'title' => 'Menus',
                'label' => 'Menus',
                'route' => '/admin/menus',
                'icon' => 'menus',
                'permission' => 'menus.view',
                'sort_order' => 155,
            ],
            [
                'section' => 'admin',
                'title' => 'Settings',
                'label' => 'Settings',
                'route' => '/admin/settings',
                'icon' => 'settings',
                'permission' => 'settings.view',
                'sort_order' => 160,
            ],
        ];

        $this->upsertMenus($table, $menus);
    }

    private function seedMerchantMenus(string $table): void
    {
        $menus = [
            [
                'section' => 'merchant',
                'title' => 'Dashboard',
                'label' => 'Dashboard',
                'route' => '/merchant/dashboard',
                'icon' => 'dashboard',
                'permission' => 'merchant.dashboard',
                'sort_order' => 10,
            ],
            [
                'section' => 'merchant',
                'title' => 'Onboarding',
                'label' => 'Onboarding',
                'route' => '/merchant/onboarding',
                'icon' => 'onboarding',
                'permission' => 'merchant.onboarding',
                'sort_order' => 20,
            ],
            [
                'section' => 'merchant',
                'title' => 'Business Profile',
                'label' => 'Business Profile',
                'route' => '/merchant/onboarding',
                'icon' => 'profile',
                'permission' => 'merchant.profile',
                'sort_order' => 30,
            ],
            [
                'section' => 'merchant',
                'title' => 'Documents',
                'label' => 'Documents',
                'route' => '/merchant/onboarding',
                'icon' => 'documents',
                'permission' => 'merchant.documents',
                'sort_order' => 40,
            ],
            [
                'section' => 'merchant',
                'title' => 'Pickup Location',
                'label' => 'Pickup Location',
                'route' => '/merchant/onboarding',
                'icon' => 'location',
                'permission' => 'merchant.locations',
                'sort_order' => 50,
            ],
            [
                'section' => 'merchant',
                'title' => 'Bank Details',
                'label' => 'Bank Details',
                'route' => '/merchant/onboarding',
                'icon' => 'bank',
                'permission' => 'merchant.bank_details',
                'sort_order' => 60,
            ],
            [
                'section' => 'merchant',
                'title' => 'Submit Verification',
                'label' => 'Submit Verification',
                'route' => '/merchant/onboarding',
                'icon' => 'submit',
                'permission' => 'merchant.submit_verification',
                'sort_order' => 70,
            ],
            [
                'section' => 'merchant',
                'title' => 'Shipments',
                'label' => 'Shipments',
                'route' => '/merchant/shipments',
                'icon' => 'shipments',
                'permission' => 'merchant.shipments',
                'sort_order' => 80,
            ],
            [
                'section' => 'merchant',
                'title' => 'Customers',
                'label' => 'Customers',
                'route' => '/merchant/customers',
                'icon' => 'customers',
                'permission' => 'merchant.customers',
                'sort_order' => 90,
            ],
            [
                'section' => 'merchant',
                'title' => 'Pickups',
                'label' => 'Pickups',
                'route' => '/merchant/pickups',
                'icon' => 'pickups',
                'permission' => 'merchant.pickups',
                'sort_order' => 100,
            ],
            [
                'section' => 'merchant',
                'title' => 'Pickup Locations',
                'label' => 'Pickup Locations',
                'route' => '/merchant/pickup-locations',
                'icon' => 'locations',
                'permission' => 'merchant.pickup_locations',
                'sort_order' => 110,
            ],
            [
                'section' => 'merchant',
                'title' => 'Rates',
                'label' => 'Rates',
                'route' => '/merchant/rates',
                'icon' => 'rates',
                'permission' => 'merchant.rates',
                'sort_order' => 120,
            ],
            [
                'section' => 'merchant',
                'title' => 'POD',
                'label' => 'POD',
                'route' => '/merchant/pod',
                'icon' => 'pod',
                'permission' => 'merchant.pod',
                'sort_order' => 130,
            ],
            [
                'section' => 'merchant',
                'title' => 'Settlements',
                'label' => 'Settlements',
                'route' => '/merchant/settlements',
                'icon' => 'settlements',
                'permission' => 'merchant.settlements',
                'sort_order' => 140,
            ],
            [
                'section' => 'merchant',
                'title' => 'Invoices',
                'label' => 'Invoices',
                'route' => '/merchant/invoices',
                'icon' => 'invoices',
                'permission' => 'merchant.invoices',
                'sort_order' => 150,
            ],
            [
                'section' => 'merchant',
                'title' => 'API Keys',
                'label' => 'API Keys',
                'route' => '/merchant/api-keys',
                'icon' => 'api-keys',
                'permission' => 'merchant.api_keys',
                'sort_order' => 160,
            ],
            [
                'section' => 'merchant',
                'title' => 'API Logs',
                'label' => 'API Logs',
                'route' => '/merchant/api-logs',
                'icon' => 'api-logs',
                'permission' => 'merchant.api_logs',
                'sort_order' => 170,
            ],
            [
                'section' => 'merchant',
                'title' => 'Webhooks',
                'label' => 'Webhooks',
                'route' => '/merchant/webhooks',
                'icon' => 'webhooks',
                'permission' => 'merchant.webhooks',
                'sort_order' => 180,
            ],
            [
                'section' => 'merchant',
                'title' => 'Webhook Logs',
                'label' => 'Webhook Logs',
                'route' => '/merchant/webhook-logs',
                'icon' => 'webhook-logs',
                'permission' => 'merchant.webhook_logs',
                'sort_order' => 190,
            ],
            [
                'section' => 'merchant',
                'title' => 'Support',
                'label' => 'Support',
                'route' => '/merchant/support-tickets',
                'icon' => 'support',
                'permission' => 'merchant.support',
                'sort_order' => 200,
            ],
        ];

        $this->upsertMenus($table, $menus);
    }

    private function seedStaffMenus(string $table): void
    {
        $menus = [
            [
                'section' => 'staff',
                'title' => 'Dashboard',
                'label' => 'Dashboard',
                'route' => '/staff/dashboard',
                'icon' => 'dashboard',
                'permission' => 'staff.dashboard',
                'sort_order' => 10,
            ],
            [
                'section' => 'staff',
                'title' => 'Pickups',
                'label' => 'Pickups',
                'route' => '/staff/pickups',
                'icon' => 'pickups',
                'permission' => 'staff.pickups',
                'sort_order' => 20,
            ],
            [
                'section' => 'staff',
                'title' => 'Deliveries',
                'label' => 'Deliveries',
                'route' => '/staff/deliveries',
                'icon' => 'deliveries',
                'permission' => 'staff.deliveries',
                'sort_order' => 30,
            ],
            [
                'section' => 'staff',
                'title' => 'POD',
                'label' => 'POD',
                'route' => '/staff/pod',
                'icon' => 'pod',
                'permission' => 'staff.pod',
                'sort_order' => 40,
            ],
        ];

        $this->upsertMenus($table, $menus);
    }

    private function upsertMenus(string $table, array $menus): void
    {
        foreach ($menus as $menu) {
            $data = $this->filterColumns($table, [
                'title' => $menu['title'] ?? $menu['label'],
                'label' => $menu['label'] ?? $menu['title'],
                'name' => $menu['label'] ?? $menu['title'],

                'section' => $menu['section'],
                'route' => $menu['route'],
                'href' => $menu['route'],
                'url' => $menu['route'],
                'path' => $menu['route'],

                'icon' => $menu['icon'] ?? null,
                'permission' => $menu['permission'] ?? null,

                'sort_order' => $menu['sort_order'] ?? 999,
                'order' => $menu['sort_order'] ?? 999,

                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /*
             * Match menus by section and URL, not by permission.
             *
             * Permissions may change over time. Including permission in the
             * match would insert duplicate menu rows instead of updating the
             * existing route.
             */
            $match = $this->filterColumns($table, [
                'section' => $menu['section'],
                'route' => $menu['route'],
                'href' => $menu['route'],
                'url' => $menu['route'],
                'path' => $menu['route'],
            ]);

            if (empty($match)) {
                continue;
            }

            DB::table($table)->updateOrInsert($match, $data);
        }
    }

    private function deleteMenuByRoute(
        string $table,
        string $section,
        string $route
    ): void {
        $routeColumns = collect([
            'route',
            'href',
            'url',
            'path',
        ])->filter(
            fn(string $column): bool =>
                Schema::hasColumn($table, $column)
        )->values();

        if ($routeColumns->isEmpty()) {
            return;
        }

        $query = DB::table($table);

        if (Schema::hasColumn($table, 'section')) {
            $query->where('section', $section);
        }

        $query->where(
            function ($routeQuery) use (
                $routeColumns,
                $route
            ): void {
                foreach ($routeColumns as $index => $column) {
                    if ($index === 0) {
                        $routeQuery->where($column, $route);
                    } else {
                        $routeQuery->orWhere($column, $route);
                    }
                }
            }
        )->delete();
    }

    private function filterColumns(string $table, array $data): array
    {
        return collect($data)
            ->filter(fn($value, $column) => Schema::hasColumn($table, $column))
            ->toArray();
    }
}
