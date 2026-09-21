<?php

namespace Database\Seeders\System;

use Modules\Access\Enums\MenuSection;

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
                section: MenuSection::Admin->value,
                route: $legacyPricingRoute
            );
        }

        $menus = [
            // ── Core ──────────────────────────────────────────────────
            ['section'=>MenuSection::Admin->value,'label'=>'Dashboard',              'route'=>'/admin/dashboard',            'icon'=>'dashboard',   'permission'=>'dashboard.view',                    'sort_order'=>10],

            // ── Branches (super_admin / main_admin only) ──────────────
            ['section'=>MenuSection::Admin->value,'label'=>'Branches',               'route'=>'/admin/branches',             'icon'=>'branches',    'permission'=>'branches.view',                     'sort_order'=>20],
            ['section'=>MenuSection::Admin->value,'label'=>'Branch Allocation',      'route'=>'/admin/coverage-locations',   'icon'=>'location',    'permission'=>'coverage_locations.view',            'sort_order'=>21],
            ['section'=>MenuSection::Admin->value,'label'=>'Franchise / Branch Offices','route'=>'/admin/branch-offices',    'icon'=>'branches',    'permission'=>'branches.view',                     'sort_order'=>22],

            // ── Branch Manager: own team ──────────────────────────────
            ['section'=>MenuSection::Admin->value,'label'=>'Branch Staff',           'route'=>'/admin/branch-staff',         'icon'=>'users',       'permission'=>'branches.team.view',                'sort_order'=>23],
            ['section'=>MenuSection::Admin->value,'label'=>'Branch Roles',           'route'=>'/admin/branch-roles',         'icon'=>'roles',       'permission'=>'branches.team.view',                'sort_order'=>24],

            // ── Merchants ─────────────────────────────────────────────
            ['section'=>MenuSection::Admin->value,'label'=>'Merchants',              'route'=>'/admin/merchants',            'icon'=>'merchants',   'permission'=>'merchants.view',                    'sort_order'=>30],
            ['section'=>MenuSection::Admin->value,'label'=>'Merchant Applications',  'route'=>'/admin/merchant-applications','icon'=>'store',       'permission'=>'merchants.view',                    'sort_order'=>31],

            // ── Customers ─────────────────────────────────────────────
            ['section'=>MenuSection::Admin->value,'label'=>'Customers',              'route'=>'/admin/customers',            'icon'=>'customers',   'permission'=>'customers.view',                    'sort_order'=>35],

            // ── Operations ────────────────────────────────────────────
            ['section'=>MenuSection::Admin->value,'label'=>'Shipments',              'route'=>'/admin/shipments',            'icon'=>'shipments',   'permission'=>'shipments.view',                    'sort_order'=>40],
            ['section'=>MenuSection::Admin->value,'label'=>'Shipment Tasks',         'route'=>'/admin/shipment-tasks',       'icon'=>'checklist',   'permission'=>'shipment_tasks.view',               'sort_order'=>41],
            ['section'=>MenuSection::Admin->value,'label'=>'Pickups',                'route'=>'/admin/pickups',              'icon'=>'pickups',     'permission'=>'pickups.view',                      'sort_order'=>50],
            ['section'=>MenuSection::Admin->value,'label'=>'Deliveries',             'route'=>'/admin/deliveries',           'icon'=>'deliveries',  'permission'=>'deliveries.view',                   'sort_order'=>60],
            ['section'=>MenuSection::Admin->value,'label'=>'Transfers',              'route'=>'/admin/transfers',            'icon'=>'dispatches',  'permission'=>'transfers.view',                    'sort_order'=>65],
            ['section'=>MenuSection::Admin->value,'label'=>'Dispatches',             'route'=>'/admin/dispatches',           'icon'=>'dispatches',  'permission'=>'dispatches.view',                   'sort_order'=>70],
            ['section'=>MenuSection::Admin->value,'label'=>'POD',                    'route'=>'/admin/pod',                  'icon'=>'pod',         'permission'=>'pod.view',                          'sort_order'=>80],

            // ── Pricing (admin/pricing_manager only) ──────────────────
            ['section'=>MenuSection::Admin->value,'label'=>'Pricing Settings',       'route'=>'/admin/rates',                'icon'=>'rates',       'permission'=>'pricing.settings.manage',            'sort_order'=>90],
            ['section'=>MenuSection::Admin->value,'label'=>'Service Types',          'route'=>'/admin/service-types',        'icon'=>'settings',    'permission'=>'pricing.service_types.manage',       'sort_order'=>91],
            ['section'=>MenuSection::Admin->value,'label'=>'Transfer Lanes',         'route'=>'/admin/branch-transfer-lanes','icon'=>'transfer',    'permission'=>'pricing.transfer_lanes.manage',      'sort_order'=>92],
            ['section'=>MenuSection::Admin->value,'label'=>'Transfer Routes',        'route'=>'/admin/branch-transfer-routes','icon'=>'truck',      'permission'=>'pricing.transfer_routes.manage',     'sort_order'=>93],
            ['section'=>MenuSection::Admin->value,'label'=>'Price Simulator',        'route'=>'/admin/pricing-test',         'icon'=>'refresh',     'permission'=>'pricing.simulator.use',              'sort_order'=>94],
            ['section'=>MenuSection::Admin->value,'label'=>'Pricing Quotes',         'route'=>'/admin/pricing-quotes',       'icon'=>'money',       'permission'=>'pricing.quotes.view',                'sort_order'=>95],

            // ── Branch Pricing (branch_manager: view only) ────────────
            ['section'=>MenuSection::Admin->value,'label'=>'Branch Pricing',         'route'=>'/admin/branch-pricing',       'icon'=>'money',       'permission'=>'pricing.branch_rates.view',          'sort_order'=>96],

            // ── Finance ───────────────────────────────────────────────
            ['section'=>MenuSection::Admin->value,'label'=>'Settlements',            'route'=>'/admin/settlements',          'icon'=>'settlements', 'permission'=>'settlements.view',                  'sort_order'=>100],
            ['section'=>MenuSection::Admin->value,'label'=>'HQ Commissions',         'route'=>'/admin/hq-commissions',       'icon'=>'money',       'permission'=>'hq-commissions.bills',              'sort_order'=>101],
            ['section'=>MenuSection::Admin->value,'label'=>'Payment Gateways',       'route'=>'/admin/payment-gateways',     'icon'=>'api',         'permission'=>'payment-gateways.accounts',         'sort_order'=>102],
            ['section'=>MenuSection::Admin->value,'label'=>'Invoices',               'route'=>'/admin/invoices',             'icon'=>'invoices',    'permission'=>'invoices.view',                     'sort_order'=>110],

            // ── Integrations ──────────────────────────────────────────
            ['section'=>MenuSection::Admin->value,'label'=>'API Keys',               'route'=>'/admin/api-keys',             'icon'=>'api',         'permission'=>'api_keys.view',                     'sort_order'=>115],
            ['section'=>MenuSection::Admin->value,'label'=>'Webhooks',               'route'=>'/admin/webhooks',             'icon'=>'webhooks',    'permission'=>'webhooks.view',                     'sort_order'=>116],
            ['section'=>MenuSection::Admin->value,'label'=>'API Logs',               'route'=>'/admin/api-logs',             'icon'=>'api',         'permission'=>'api_logs.view',                     'sort_order'=>117],
            ['section'=>MenuSection::Admin->value,'label'=>'Webhook Logs',           'route'=>'/admin/webhook-logs',         'icon'=>'webhooks',    'permission'=>'webhook_logs.view',                 'sort_order'=>118],

            // ── Notifications / Reports / Support ─────────────────────
            ['section'=>MenuSection::Admin->value,'label'=>'Notifications',          'route'=>'/admin/notifications',        'icon'=>'notifications','permission'=>'notifications.view',                'sort_order'=>120],
            ['section'=>MenuSection::Admin->value,'label'=>'Reports',                'route'=>'/admin/reports',              'icon'=>'reports',     'permission'=>'reports.view',                      'sort_order'=>125],
            ['section'=>MenuSection::Admin->value,'label'=>'Support',                'route'=>'/admin/support-tickets',      'icon'=>'support',     'permission'=>'support.view',                      'sort_order'=>130],

            // ── System Admin ──────────────────────────────────────────
            ['section'=>MenuSection::Admin->value,'label'=>'Users',                  'route'=>'/admin/users',                'icon'=>'users',       'permission'=>'users.view',                        'sort_order'=>140],
            ['section'=>MenuSection::Admin->value,'label'=>'Roles',                  'route'=>'/admin/roles',                'icon'=>'roles',       'permission'=>'roles.view',                        'sort_order'=>150],
            ['section'=>MenuSection::Admin->value,'label'=>'Menus',                  'route'=>'/admin/menus',                'icon'=>'menus',       'permission'=>'menus.view',                        'sort_order'=>155],
            ['section'=>MenuSection::Admin->value,'label'=>'Settings',               'route'=>'/admin/settings',             'icon'=>'settings',    'permission'=>'settings.view',                     'sort_order'=>160],
        ];

        $this->upsertMenus($table, $menus);
    }

    private function seedMerchantMenus(string $table): void
    {
        $menus = [
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Dashboard',
                'label' => 'Dashboard',
                'route' => '/merchant/dashboard',
                'icon' => 'dashboard',
                'permission' => 'merchant.dashboard',
                'sort_order' => 10,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Onboarding',
                'label' => 'Onboarding',
                'route' => '/merchant/onboarding',
                'icon' => 'onboarding',
                'permission' => 'merchant.onboarding',
                'sort_order' => 20,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Business Profile',
                'label' => 'Business Profile',
                'route' => '/merchant/onboarding',
                'icon' => 'profile',
                'permission' => 'merchant.profile',
                'sort_order' => 30,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Documents',
                'label' => 'Documents',
                'route' => '/merchant/onboarding',
                'icon' => 'documents',
                'permission' => 'merchant.documents',
                'sort_order' => 40,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Pickup Location',
                'label' => 'Pickup Location',
                'route' => '/merchant/onboarding',
                'icon' => 'location',
                'permission' => 'merchant.locations',
                'sort_order' => 50,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Bank Details',
                'label' => 'Bank Details',
                'route' => '/merchant/onboarding',
                'icon' => 'bank',
                'permission' => 'merchant.bank_details',
                'sort_order' => 60,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Submit Verification',
                'label' => 'Submit Verification',
                'route' => '/merchant/onboarding',
                'icon' => 'submit',
                'permission' => 'merchant.submit_verification',
                'sort_order' => 70,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Shipments',
                'label' => 'Shipments',
                'route' => '/merchant/shipments',
                'icon' => 'shipments',
                'permission' => 'merchant.shipments',
                'sort_order' => 80,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Customers',
                'label' => 'Customers',
                'route' => '/merchant/customers',
                'icon' => 'customers',
                'permission' => 'merchant.customers',
                'sort_order' => 90,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Pickups',
                'label' => 'Pickups',
                'route' => '/merchant/pickups',
                'icon' => 'pickups',
                'permission' => 'merchant.pickups',
                'sort_order' => 100,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Pickup Locations',
                'label' => 'Pickup Locations',
                'route' => '/merchant/pickup-locations',
                'icon' => 'locations',
                'permission' => 'merchant.pickup_locations',
                'sort_order' => 110,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Rates',
                'label' => 'Rates',
                'route' => '/merchant/rates',
                'icon' => 'rates',
                'permission' => 'merchant.rates',
                'sort_order' => 120,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'POD',
                'label' => 'POD',
                'route' => '/merchant/pod',
                'icon' => 'pod',
                'permission' => 'merchant.pod',
                'sort_order' => 130,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Settlements',
                'label' => 'Settlements',
                'route' => '/merchant/settlements',
                'icon' => 'settlements',
                'permission' => 'merchant.settlements',
                'sort_order' => 140,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Invoices',
                'label' => 'Invoices',
                'route' => '/merchant/invoices',
                'icon' => 'invoices',
                'permission' => 'merchant.invoices',
                'sort_order' => 150,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'API Keys',
                'label' => 'API Keys',
                'route' => '/merchant/api-keys',
                'icon' => 'api-keys',
                'permission' => 'merchant.api_keys',
                'sort_order' => 160,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'API Logs',
                'label' => 'API Logs',
                'route' => '/merchant/api-logs',
                'icon' => 'api-logs',
                'permission' => 'merchant.api_logs',
                'sort_order' => 170,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Webhooks',
                'label' => 'Webhooks',
                'route' => '/merchant/webhooks',
                'icon' => 'webhooks',
                'permission' => 'merchant.webhooks',
                'sort_order' => 180,
            ],
            [
                'section' => MenuSection::Merchant->value,
                'title' => 'Webhook Logs',
                'label' => 'Webhook Logs',
                'route' => '/merchant/webhook-logs',
                'icon' => 'webhook-logs',
                'permission' => 'merchant.webhook_logs',
                'sort_order' => 190,
            ],
            [
                'section' => MenuSection::Merchant->value,
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
                'section' => MenuSection::Staff->value,
                'title' => 'Dashboard',
                'label' => 'Dashboard',
                'route' => '/staff/dashboard',
                'icon' => 'dashboard',
                'permission' => 'staff.dashboard',
                'sort_order' => 10,
            ],
            [
                'section' => MenuSection::Staff->value,
                'title' => 'Pickups',
                'label' => 'Pickups',
                'route' => '/staff/pickups',
                'icon' => 'pickups',
                'permission' => 'staff.pickups',
                'sort_order' => 20,
            ],
            [
                'section' => MenuSection::Staff->value,
                'title' => 'Deliveries',
                'label' => 'Deliveries',
                'route' => '/staff/deliveries',
                'icon' => 'deliveries',
                'permission' => 'staff.deliveries',
                'sort_order' => 30,
            ],
            [
                'section' => MenuSection::Staff->value,
                'title' => 'POD',
                'label' => 'POD',
                'route' => '/staff/pod',
                'icon' => 'pod',
                'permission' => 'staff.pod',
                'sort_order' => 40,
            ],
            // support_staff menus
            [
                'section' => MenuSection::Staff->value,
                'title' => 'Shipments',
                'label' => 'Shipments',
                'route' => '/staff/shipments',
                'icon' => 'shipments',
                'permission' => 'shipments.view',
                'sort_order' => 50,
            ],
            [
                'section' => MenuSection::Staff->value,
                'title' => 'Support Tickets',
                'label' => 'Support Tickets',
                'route' => '/staff/support',
                'icon' => 'support',
                'permission' => 'support.view',
                'sort_order' => 60,
            ],
            // accounts_staff menus
            [
                'section' => MenuSection::Staff->value,
                'title' => 'COD / POD',
                'label' => 'COD / POD',
                'route' => '/staff/cod',
                'icon' => 'money',
                'permission' => 'pod.view',
                'sort_order' => 70,
            ],
            [
                'section' => MenuSection::Staff->value,
                'title' => 'Settlements',
                'label' => 'Settlements',
                'route' => '/staff/settlements',
                'icon' => 'settlements',
                'permission' => 'settlements.view',
                'sort_order' => 80,
            ],
            [
                'section' => MenuSection::Staff->value,
                'title' => 'HQ Commissions',
                'label' => 'HQ Commissions',
                'route' => '/staff/hq-commissions',
                'icon' => 'money',
                'permission' => 'hq-commissions.bills',
                'sort_order' => 81,
            ],
            [
                'section' => MenuSection::Staff->value,
                'title' => 'Payment Gateways',
                'label' => 'Payment Gateways',
                'route' => '/staff/payment-gateways',
                'icon' => 'api',
                'permission' => 'payment-gateways.accounts',
                'sort_order' => 82,
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
