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
            '/admin/staff',
        ] as $legacyPricingRoute) {
            $this->deleteMenuByRoute(
                table: $table,
                section: 'admin',
                route: $legacyPricingRoute
            );
        }

        $menus = [
            // ── Core ──────────────────────────────────────────────────
            ['section'=>'admin','label'=>'Dashboard',              'route'=>'/admin/dashboard',            'icon'=>'dashboard',   'permission'=>'dashboard.view',                    'sort_order'=>10],

            // ── Branches (super_admin / main_admin only) ──────────────
            ['section'=>'admin','label'=>'Branches',               'route'=>'/admin/branches',             'icon'=>'branches',    'permission'=>'branches.view',                     'sort_order'=>20],
            ['section'=>'admin','label'=>'Branch Allocation',      'route'=>'/admin/coverage-locations',   'icon'=>'location',    'permission'=>'coverage_locations.view',            'sort_order'=>21],
            ['section'=>'admin','label'=>'Franchise / Branch Offices','route'=>'/admin/branch-offices',    'icon'=>'branches',    'permission'=>'branches.view',                     'sort_order'=>22],

            // ── Branch Manager: own team ──────────────────────────────
            ['section'=>'admin','label'=>'Branch Staff',           'route'=>'/admin/branch-staff',         'icon'=>'users',       'permission'=>'branches.team.view',                'sort_order'=>23],
            ['section'=>'admin','label'=>'Branch Roles',           'route'=>'/admin/branch-roles',         'icon'=>'roles',       'permission'=>'branches.team.view',                'sort_order'=>24],

            // ── Merchants ─────────────────────────────────────────────
            ['section'=>'admin','label'=>'Merchants',              'route'=>'/admin/merchants',            'icon'=>'merchants',   'permission'=>'merchants.view',                    'sort_order'=>30],
            ['section'=>'admin','label'=>'Merchant Applications',  'route'=>'/admin/merchant-applications','icon'=>'store',       'permission'=>'merchants.view',                    'sort_order'=>31],

            // ── Customers ─────────────────────────────────────────────
            ['section'=>'admin','label'=>'Customers',              'route'=>'/admin/customers',            'icon'=>'customers',   'permission'=>'customers.view',                    'sort_order'=>35],

            // ── Operations ────────────────────────────────────────────
            ['section'=>'admin','label'=>'Shipments',              'route'=>'/admin/shipments',            'icon'=>'shipments',   'permission'=>'shipments.view',                    'sort_order'=>40],
            ['section'=>'admin','label'=>'Shipment Tasks',         'route'=>'/admin/shipment-tasks',       'icon'=>'checklist',   'permission'=>'shipment_tasks.view',               'sort_order'=>41],
            ['section'=>'admin','label'=>'Pickups',                'route'=>'/admin/pickups',              'icon'=>'pickups',     'permission'=>'pickups.view',                      'sort_order'=>50],
            ['section'=>'admin','label'=>'Deliveries',             'route'=>'/admin/deliveries',           'icon'=>'deliveries',  'permission'=>'deliveries.view',                   'sort_order'=>60],
            ['section'=>'admin','label'=>'Dispatches',             'route'=>'/admin/dispatches',           'icon'=>'dispatches',  'permission'=>'dispatches.view',                   'sort_order'=>70],
            ['section'=>'admin','label'=>'POD',                    'route'=>'/admin/pod',                  'icon'=>'pod',         'permission'=>'pod.view',                          'sort_order'=>80],

            // ── Pricing (admin/pricing_manager only) ──────────────────
            ['section'=>'admin','label'=>'Pricing Settings',       'route'=>'/admin/rates',                'icon'=>'rates',       'permission'=>'pricing.settings.manage',            'sort_order'=>90],
            ['section'=>'admin','label'=>'Service Types',          'route'=>'/admin/service-types',        'icon'=>'settings',    'permission'=>'pricing.service_types.manage',       'sort_order'=>91],
            ['section'=>'admin','label'=>'Transfer Lanes',         'route'=>'/admin/branch-transfer-lanes','icon'=>'transfer',    'permission'=>'pricing.transfer_lanes.manage',      'sort_order'=>92],
            ['section'=>'admin','label'=>'Transfer Routes',        'route'=>'/admin/branch-transfer-routes','icon'=>'truck',      'permission'=>'pricing.transfer_routes.manage',     'sort_order'=>93],
            ['section'=>'admin','label'=>'Price Simulator',        'route'=>'/admin/pricing-test',         'icon'=>'refresh',     'permission'=>'pricing.simulator.use',              'sort_order'=>94],
            ['section'=>'admin','label'=>'Pricing Quotes',         'route'=>'/admin/pricing-quotes',       'icon'=>'money',       'permission'=>'pricing.quotes.view',                'sort_order'=>95],

            // ── Branch Pricing (branch_manager: view only) ────────────
            ['section'=>'admin','label'=>'Branch Pricing',         'route'=>'/admin/branch-pricing',       'icon'=>'money',       'permission'=>'pricing.branch_rates.view',          'sort_order'=>96],

            // ── Finance ───────────────────────────────────────────────
            ['section'=>'admin','label'=>'Settlements',            'route'=>'/admin/settlements',          'icon'=>'settlements', 'permission'=>'settlements.view',                  'sort_order'=>100],
            ['section'=>'admin','label'=>'Invoices',               'route'=>'/admin/invoices',             'icon'=>'invoices',    'permission'=>'invoices.view',                     'sort_order'=>110],

            // ── Integrations ──────────────────────────────────────────
            ['section'=>'admin','label'=>'API Keys',               'route'=>'/admin/api-keys',             'icon'=>'api',         'permission'=>'api_keys.view',                     'sort_order'=>115],
            ['section'=>'admin','label'=>'Webhooks',               'route'=>'/admin/webhooks',             'icon'=>'webhooks',    'permission'=>'webhooks.view',                     'sort_order'=>116],
            ['section'=>'admin','label'=>'API Logs',               'route'=>'/admin/api-logs',             'icon'=>'api',         'permission'=>'api_logs.view',                     'sort_order'=>117],
            ['section'=>'admin','label'=>'Webhook Logs',           'route'=>'/admin/webhook-logs',         'icon'=>'webhooks',    'permission'=>'webhook_logs.view',                 'sort_order'=>118],

            // ── Notifications / Reports / Support ─────────────────────
            ['section'=>'admin','label'=>'Notifications',          'route'=>'/admin/notifications',        'icon'=>'notifications','permission'=>'notifications.view',                'sort_order'=>120],
            ['section'=>'admin','label'=>'Reports',                'route'=>'/admin/reports',              'icon'=>'reports',     'permission'=>'reports.view',                      'sort_order'=>125],
            ['section'=>'admin','label'=>'Support',                'route'=>'/admin/support-tickets',      'icon'=>'support',     'permission'=>'support.view',                      'sort_order'=>130],

            // ── System Admin ──────────────────────────────────────────
            ['section'=>'admin','label'=>'Users',                  'route'=>'/admin/users',                'icon'=>'users',       'permission'=>'users.view',                        'sort_order'=>140],
            ['section'=>'admin','label'=>'Roles',                  'route'=>'/admin/roles',                'icon'=>'roles',       'permission'=>'roles.view',                        'sort_order'=>150],
            ['section'=>'admin','label'=>'Menus',                  'route'=>'/admin/menus',                'icon'=>'menus',       'permission'=>'menus.view',                        'sort_order'=>155],
            ['section'=>'admin','label'=>'Settings',               'route'=>'/admin/settings',             'icon'=>'settings',    'permission'=>'settings.view',                     'sort_order'=>160],
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
            // support_staff menus
            [
                'section' => 'staff',
                'title' => 'Shipments',
                'label' => 'Shipments',
                'route' => '/staff/shipments',
                'icon' => 'shipments',
                'permission' => 'shipments.view',
                'sort_order' => 50,
            ],
            [
                'section' => 'staff',
                'title' => 'Support Tickets',
                'label' => 'Support Tickets',
                'route' => '/staff/support',
                'icon' => 'support',
                'permission' => 'support.view',
                'sort_order' => 60,
            ],
            // accounts_staff menus
            [
                'section' => 'staff',
                'title' => 'COD / POD',
                'label' => 'COD / POD',
                'route' => '/staff/cod',
                'icon' => 'money',
                'permission' => 'pod.view',
                'sort_order' => 70,
            ],
            [
                'section' => 'staff',
                'title' => 'Settlements',
                'label' => 'Settlements',
                'route' => '/staff/settlements',
                'icon' => 'settlements',
                'permission' => 'settlements.view',
                'sort_order' => 80,
            ],
        ];

        $this->upsertMenus($table, $menus);
    }

    private function upsertMenus(string $table, array $menus): void
    {
        foreach ($menus as $menu) {
            $data = $this->filterColumns($table, [
                'title'      => $menu['title'] ?? $menu['label'],
                'label'      => $menu['label'] ?? $menu['title'],
                'name'       => $menu['label'] ?? $menu['title'],

                'section'    => $menu['section'],
                'route'      => $menu['route'],
                'href'       => $menu['route'],
                'url'        => $menu['route'],
                'path'       => $menu['route'],

                'icon'       => $menu['icon'] ?? null,
                'permission' => $menu['permission'] ?? null,

                'sort_order' => $menu['sort_order'] ?? 999,
                'order'      => $menu['sort_order'] ?? 999,

                'is_active'  => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $match = $this->filterColumns($table, [
                'section' => $menu['section'],
                'route'   => $menu['route'],
                'href'    => $menu['route'],
                'url'     => $menu['route'],
                'path'    => $menu['route'],
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
