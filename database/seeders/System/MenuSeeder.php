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
         * Remove legacy / duplicate routes before upserting the hierarchical tree.
         */
        foreach ([
            '/admin/rate-cards',
            '/admin/pricing-settings',
            '/admin/branch-offices',
            '/admin/franchise-offices',
        ] as $legacyRoute) {
            $this->deleteMenuByRoute(
                table: $table,
                section: MenuSection::Admin->value,
                route: $legacyRoute
            );
        }

        $menus = [
            // Dashboard (root leaf)
            ['section' => MenuSection::Admin->value, 'label' => 'Dashboard', 'route' => '/admin/dashboard', 'icon' => 'dashboard', 'permission' => 'dashboard.view', 'sort_order' => 10],

            // Operations
            ['section' => MenuSection::Admin->value, 'label' => 'Operations', 'route' => null, 'icon' => 'shipments', 'permission' => null, 'sort_order' => 20],
            ['section' => MenuSection::Admin->value, 'label' => 'Shipments', 'route' => '/admin/shipments', 'icon' => 'shipments', 'permission' => 'shipments.view', 'sort_order' => 10, 'parent' => 'Operations'],
            ['section' => MenuSection::Admin->value, 'label' => 'Shipment Tasks', 'route' => '/admin/shipment-tasks', 'icon' => 'checklist', 'permission' => 'shipment_tasks.view', 'sort_order' => 20, 'parent' => 'Operations'],
            ['section' => MenuSection::Admin->value, 'label' => 'Pickups', 'route' => '/admin/pickups', 'icon' => 'pickups', 'permission' => 'pickups.view', 'sort_order' => 30, 'parent' => 'Operations'],
            ['section' => MenuSection::Admin->value, 'label' => 'Deliveries', 'route' => '/admin/deliveries', 'icon' => 'deliveries', 'permission' => 'deliveries.view', 'sort_order' => 40, 'parent' => 'Operations'],
            ['section' => MenuSection::Admin->value, 'label' => 'Transfers', 'route' => '/admin/transfers', 'icon' => 'dispatches', 'permission' => 'transfers.view', 'sort_order' => 50, 'parent' => 'Operations'],
            ['section' => MenuSection::Admin->value, 'label' => 'Dispatches', 'route' => '/admin/dispatches', 'icon' => 'dispatches', 'permission' => 'dispatches.view', 'sort_order' => 60, 'parent' => 'Operations'],

            // Network — hub network config (branches, lanes, routes)
            ['section' => MenuSection::Admin->value, 'label' => 'Network', 'route' => null, 'icon' => 'branches', 'permission' => null, 'sort_order' => 30],
            ['section' => MenuSection::Admin->value, 'label' => 'Branches', 'route' => '/admin/branches', 'icon' => 'branches', 'permission' => 'branches.view', 'sort_order' => 10, 'parent' => 'Network'],
            ['section' => MenuSection::Admin->value, 'label' => 'Transfer Lanes', 'route' => '/admin/branch-transfer-lanes', 'icon' => 'transfer', 'permission' => 'pricing.transfer_lanes.manage', 'sort_order' => 20, 'parent' => 'Network'],
            ['section' => MenuSection::Admin->value, 'label' => 'Transfer Routes', 'route' => '/admin/branch-transfer-routes', 'icon' => 'truck', 'permission' => 'pricing.transfer_routes.manage', 'sort_order' => 30, 'parent' => 'Network'],
            ['section' => MenuSection::Admin->value, 'label' => 'Branch Allocation', 'route' => '/admin/coverage-locations', 'icon' => 'location', 'permission' => 'coverage_locations.view', 'sort_order' => 40, 'parent' => 'Network'],
            ['section' => MenuSection::Admin->value, 'label' => 'Branch Staff', 'route' => '/admin/branch-staff', 'icon' => 'users', 'permission' => 'branches.team.view', 'sort_order' => 50, 'parent' => 'Network'],
            ['section' => MenuSection::Admin->value, 'label' => 'Branch Roles', 'route' => '/admin/branch-roles', 'icon' => 'roles', 'permission' => 'branches.team.view', 'sort_order' => 60, 'parent' => 'Network'],
            ['section' => MenuSection::Admin->value, 'label' => 'Merchants', 'route' => '/admin/merchants', 'icon' => 'merchants', 'permission' => 'merchants.view', 'sort_order' => 70, 'parent' => 'Network'],
            ['section' => MenuSection::Admin->value, 'label' => 'Merchant Applications', 'route' => '/admin/merchant-applications', 'icon' => 'store', 'permission' => 'merchants.view', 'sort_order' => 80, 'parent' => 'Network'],
            ['section' => MenuSection::Admin->value, 'label' => 'Customers', 'route' => '/admin/customers', 'icon' => 'customers', 'permission' => 'customers.view', 'sort_order' => 90, 'parent' => 'Network'],

            // Pricing — rate cards / quotes only (lanes/routes live under Network)
            ['section' => MenuSection::Admin->value, 'label' => 'Pricing', 'route' => null, 'icon' => 'rates', 'permission' => null, 'sort_order' => 40],
            ['section' => MenuSection::Admin->value, 'label' => 'Pricing Settings', 'route' => '/admin/rates', 'icon' => 'rates', 'permission' => 'pricing.settings.manage', 'sort_order' => 10, 'parent' => 'Pricing'],
            ['section' => MenuSection::Admin->value, 'label' => 'Service Types', 'route' => '/admin/service-types', 'icon' => 'settings', 'permission' => 'pricing.service_types.manage', 'sort_order' => 20, 'parent' => 'Pricing'],
            ['section' => MenuSection::Admin->value, 'label' => 'Price Simulator', 'route' => '/admin/pricing-test', 'icon' => 'refresh', 'permission' => 'pricing.simulator.use', 'sort_order' => 30, 'parent' => 'Pricing'],
            ['section' => MenuSection::Admin->value, 'label' => 'Pricing Quotes', 'route' => '/admin/pricing-quotes', 'icon' => 'money', 'permission' => 'pricing.quotes.view', 'sort_order' => 40, 'parent' => 'Pricing'],
            ['section' => MenuSection::Admin->value, 'label' => 'Branch Pricing', 'route' => '/admin/branch-pricing', 'icon' => 'money', 'permission' => 'pricing.branch_rates.view', 'sort_order' => 50, 'parent' => 'Pricing'],

            // Finance — POD under Finance
            ['section' => MenuSection::Admin->value, 'label' => 'Finance', 'route' => null, 'icon' => 'money', 'permission' => null, 'sort_order' => 50],
            ['section' => MenuSection::Admin->value, 'label' => 'Settlements', 'route' => '/admin/settlements', 'icon' => 'settlements', 'permission' => 'settlements.view', 'sort_order' => 10, 'parent' => 'Finance'],
            ['section' => MenuSection::Admin->value, 'label' => 'HQ Commissions', 'route' => '/admin/hq-commissions', 'icon' => 'money', 'permission' => 'hq-commissions.bills', 'sort_order' => 20, 'parent' => 'Finance'],
            ['section' => MenuSection::Admin->value, 'label' => 'Payment Gateways', 'route' => '/admin/payment-gateways', 'icon' => 'api', 'permission' => 'payment-gateways.accounts', 'sort_order' => 30, 'parent' => 'Finance'],
            ['section' => MenuSection::Admin->value, 'label' => 'Invoices', 'route' => '/admin/invoices', 'icon' => 'invoices', 'permission' => 'invoices.view', 'sort_order' => 40, 'parent' => 'Finance'],
            ['section' => MenuSection::Admin->value, 'label' => 'POD', 'route' => '/admin/pod', 'icon' => 'pod', 'permission' => 'pod.view', 'sort_order' => 50, 'parent' => 'Finance'],

            // Integrations
            ['section' => MenuSection::Admin->value, 'label' => 'Integrations', 'route' => null, 'icon' => 'api', 'permission' => null, 'sort_order' => 60],
            ['section' => MenuSection::Admin->value, 'label' => 'API Keys', 'route' => '/admin/api-keys', 'icon' => 'api', 'permission' => 'api_keys.view', 'sort_order' => 10, 'parent' => 'Integrations'],
            ['section' => MenuSection::Admin->value, 'label' => 'Webhooks', 'route' => '/admin/webhooks', 'icon' => 'webhooks', 'permission' => 'webhooks.view', 'sort_order' => 20, 'parent' => 'Integrations'],
            ['section' => MenuSection::Admin->value, 'label' => 'API Logs', 'route' => '/admin/api-logs', 'icon' => 'api', 'permission' => 'api_logs.view', 'sort_order' => 30, 'parent' => 'Integrations'],
            ['section' => MenuSection::Admin->value, 'label' => 'Webhook Logs', 'route' => '/admin/webhook-logs', 'icon' => 'webhooks', 'permission' => 'webhook_logs.view', 'sort_order' => 40, 'parent' => 'Integrations'],

            // Support
            ['section' => MenuSection::Admin->value, 'label' => 'Support', 'route' => null, 'icon' => 'support', 'permission' => null, 'sort_order' => 70],
            ['section' => MenuSection::Admin->value, 'label' => 'Notifications', 'route' => '/admin/notifications', 'icon' => 'notifications', 'permission' => 'notifications.view', 'sort_order' => 10, 'parent' => 'Support'],
            ['section' => MenuSection::Admin->value, 'label' => 'Reports', 'route' => '/admin/reports', 'icon' => 'reports', 'permission' => 'reports.view', 'sort_order' => 20, 'parent' => 'Support'],
            ['section' => MenuSection::Admin->value, 'label' => 'Shipment Reports', 'route' => '/admin/reports/shipments', 'icon' => 'reports', 'permission' => 'reports.view', 'sort_order' => 25, 'parent' => 'Support'],
            ['section' => MenuSection::Admin->value, 'label' => 'Support', 'route' => '/admin/support-tickets', 'icon' => 'support', 'permission' => 'support.view', 'sort_order' => 30, 'parent' => 'Support'],

            // System
            ['section' => MenuSection::Admin->value, 'label' => 'System', 'route' => null, 'icon' => 'settings', 'permission' => null, 'sort_order' => 80],
            ['section' => MenuSection::Admin->value, 'label' => 'Users', 'route' => '/admin/users', 'icon' => 'users', 'permission' => 'users.view', 'sort_order' => 10, 'parent' => 'System'],
            ['section' => MenuSection::Admin->value, 'label' => 'Roles', 'route' => '/admin/roles', 'icon' => 'roles', 'permission' => 'roles.view', 'sort_order' => 20, 'parent' => 'System'],
            ['section' => MenuSection::Admin->value, 'label' => 'Menus', 'route' => '/admin/menus', 'icon' => 'menus', 'permission' => 'menus.view', 'sort_order' => 30, 'parent' => 'System'],
            ['section' => MenuSection::Admin->value, 'label' => 'Settings', 'route' => '/admin/settings', 'icon' => 'settings', 'permission' => 'settings.view', 'sort_order' => 40, 'parent' => 'System'],
        ];

        $this->upsertMenusHierarchical($table, $menus);
    }

    private function seedMerchantMenus(string $table): void
    {
        // Keep existing merchant leaves; light grouping only (no heavy nest).
        $menus = [
            ['section' => MenuSection::Merchant->value, 'label' => 'Dashboard', 'route' => '/merchant/dashboard', 'icon' => 'dashboard', 'permission' => 'merchant.dashboard', 'sort_order' => 10],
            ['section' => MenuSection::Merchant->value, 'label' => 'Onboarding', 'route' => '/merchant/onboarding', 'icon' => 'onboarding', 'permission' => 'merchant.onboarding', 'sort_order' => 20],
            ['section' => MenuSection::Merchant->value, 'label' => 'Business Profile', 'route' => '/merchant/onboarding', 'icon' => 'profile', 'permission' => 'merchant.profile', 'sort_order' => 30],
            ['section' => MenuSection::Merchant->value, 'label' => 'Documents', 'route' => '/merchant/onboarding', 'icon' => 'documents', 'permission' => 'merchant.documents', 'sort_order' => 40],
            ['section' => MenuSection::Merchant->value, 'label' => 'Pickup Location', 'route' => '/merchant/onboarding', 'icon' => 'location', 'permission' => 'merchant.locations', 'sort_order' => 50],
            ['section' => MenuSection::Merchant->value, 'label' => 'Bank Details', 'route' => '/merchant/onboarding', 'icon' => 'bank', 'permission' => 'merchant.bank_details', 'sort_order' => 60],
            ['section' => MenuSection::Merchant->value, 'label' => 'Submit Verification', 'route' => '/merchant/onboarding', 'icon' => 'submit', 'permission' => 'merchant.submit_verification', 'sort_order' => 70],
            ['section' => MenuSection::Merchant->value, 'label' => 'Shipments', 'route' => '/merchant/shipments', 'icon' => 'shipments', 'permission' => 'merchant.shipments', 'sort_order' => 80],
            ['section' => MenuSection::Merchant->value, 'label' => 'Customers', 'route' => '/merchant/customers', 'icon' => 'customers', 'permission' => 'merchant.customers', 'sort_order' => 90],
            ['section' => MenuSection::Merchant->value, 'label' => 'Pickups', 'route' => '/merchant/pickups', 'icon' => 'pickups', 'permission' => 'merchant.pickups', 'sort_order' => 100],
            ['section' => MenuSection::Merchant->value, 'label' => 'Pickup Locations', 'route' => '/merchant/pickup-locations', 'icon' => 'locations', 'permission' => 'merchant.pickup_locations', 'sort_order' => 110],
            ['section' => MenuSection::Merchant->value, 'label' => 'Rates', 'route' => '/merchant/rates', 'icon' => 'rates', 'permission' => 'merchant.rates', 'sort_order' => 120],
            ['section' => MenuSection::Merchant->value, 'label' => 'POD', 'route' => '/merchant/pod', 'icon' => 'pod', 'permission' => 'merchant.pod', 'sort_order' => 130],
            ['section' => MenuSection::Merchant->value, 'label' => 'Settlements', 'route' => '/merchant/settlements', 'icon' => 'settlements', 'permission' => 'merchant.settlements', 'sort_order' => 140],
            ['section' => MenuSection::Merchant->value, 'label' => 'Invoices', 'route' => '/merchant/invoices', 'icon' => 'invoices', 'permission' => 'merchant.invoices', 'sort_order' => 150],
            ['section' => MenuSection::Merchant->value, 'label' => 'API Keys', 'route' => '/merchant/api-keys', 'icon' => 'api-keys', 'permission' => 'merchant.api_keys', 'sort_order' => 160],
            ['section' => MenuSection::Merchant->value, 'label' => 'API Logs', 'route' => '/merchant/api-logs', 'icon' => 'api-logs', 'permission' => 'merchant.api_logs', 'sort_order' => 170],
            ['section' => MenuSection::Merchant->value, 'label' => 'Webhooks', 'route' => '/merchant/webhooks', 'icon' => 'webhooks', 'permission' => 'merchant.webhooks', 'sort_order' => 180],
            ['section' => MenuSection::Merchant->value, 'label' => 'Webhook Logs', 'route' => '/merchant/webhook-logs', 'icon' => 'webhook-logs', 'permission' => 'merchant.webhook_logs', 'sort_order' => 190],
            ['section' => MenuSection::Merchant->value, 'label' => 'Support', 'route' => '/merchant/support-tickets', 'icon' => 'support', 'permission' => 'merchant.support', 'sort_order' => 200],
        ];

        $this->upsertMenusHierarchical($table, $menus);
    }

    private function seedStaffMenus(string $table): void
    {
        /*
         * Strip finance menus from default staff seed. Admins can re-add via UI.
         */
        foreach ([
            '/staff/cod',
            '/staff/settlements',
            '/staff/hq-commissions',
            '/staff/payment-gateways',
        ] as $financeRoute) {
            $this->deleteMenuByRoute(
                table: $table,
                section: MenuSection::Staff->value,
                route: $financeRoute
            );
        }

        $menus = [
            ['section' => MenuSection::Staff->value, 'label' => 'Dashboard', 'route' => '/staff/dashboard', 'icon' => 'dashboard', 'permission' => 'staff.dashboard', 'sort_order' => 10],

            ['section' => MenuSection::Staff->value, 'label' => 'My Jobs', 'route' => null, 'icon' => 'checklist', 'permission' => null, 'sort_order' => 20],
            ['section' => MenuSection::Staff->value, 'label' => 'Pickups', 'route' => '/staff/pickups', 'icon' => 'pickups', 'permission' => 'staff.pickups', 'sort_order' => 10, 'parent' => 'My Jobs'],
            ['section' => MenuSection::Staff->value, 'label' => 'Deliveries', 'route' => '/staff/deliveries', 'icon' => 'deliveries', 'permission' => 'staff.deliveries', 'sort_order' => 20, 'parent' => 'My Jobs'],
            ['section' => MenuSection::Staff->value, 'label' => 'POD', 'route' => '/staff/pod', 'icon' => 'pod', 'permission' => 'staff.pod', 'sort_order' => 30, 'parent' => 'My Jobs'],

            // support_staff — permission-gated
            ['section' => MenuSection::Staff->value, 'label' => 'Support', 'route' => null, 'icon' => 'support', 'permission' => null, 'sort_order' => 30],
            ['section' => MenuSection::Staff->value, 'label' => 'Shipments', 'route' => '/staff/shipments', 'icon' => 'shipments', 'permission' => 'shipments.view', 'sort_order' => 10, 'parent' => 'Support'],
            ['section' => MenuSection::Staff->value, 'label' => 'Support Tickets', 'route' => '/staff/support', 'icon' => 'support', 'permission' => 'support.view', 'sort_order' => 20, 'parent' => 'Support'],
        ];

        $this->upsertMenusHierarchical($table, $menus);
    }

    /**
     * Two-pass upsert: parents (no parent key) first, then children with parent lookup.
     */
    private function upsertMenusHierarchical(string $table, array $menus): void
    {
        $parents = [];
        $children = [];

        foreach ($menus as $menu) {
            if (! empty($menu['parent'])) {
                $children[] = $menu;
            } else {
                $parents[] = $menu;
            }
        }

        foreach ($parents as $menu) {
            $this->upsertOne($table, $menu, null);
        }

        foreach ($children as $menu) {
            $parentId = $this->findParentId(
                $table,
                $menu['section'],
                $menu['parent']
            );

            $this->upsertOne($table, $menu, $parentId);
        }
    }

    private function findParentId(string $table, string $section, string $parentLabel): ?int
    {
        $query = DB::table($table)->where('section', $section);

        if (Schema::hasColumn($table, 'parent_id')) {
            $query->whereNull('parent_id');
        }

        if (Schema::hasColumn($table, 'label')) {
            $query->where('label', $parentLabel);
        } elseif (Schema::hasColumn($table, 'title')) {
            $query->where('title', $parentLabel);
        } else {
            return null;
        }

        $id = $query->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function upsertOne(string $table, array $menu, ?int $parentId): void
    {
        $route = $menu['route'] ?? null;

        $data = $this->filterColumns($table, [
            'title'      => $menu['title'] ?? $menu['label'],
            'label'      => $menu['label'] ?? $menu['title'],
            'name'       => $menu['label'] ?? $menu['title'],

            'section'    => $menu['section'],
            'route'      => $route,
            'href'       => $route,
            'url'        => $route,
            'path'       => $route,

            'icon'       => $menu['icon'] ?? null,
            'permission' => $menu['permission'] ?? null,

            'parent_id'  => $parentId,

            'sort_order' => $menu['sort_order'] ?? 999,
            'order'      => $menu['sort_order'] ?? 999,

            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Match strategy:
        // - Leaves with a route: section + path/route
        // - Pathless parents: section + label + parent_id null
        if ($route !== null && $route !== '') {
            $match = $this->filterColumns($table, [
                'section' => $menu['section'],
                'route'   => $route,
                'href'    => $route,
                'url'     => $route,
                'path'    => $route,
            ]);

            if (empty($match)) {
                return;
            }

            $existing = DB::table($table)->where($match)->first();
        } else {
            // Pathless parents: match section + label among root rows with null path
            $label = $menu['label'] ?? $menu['title'];
            $query = DB::table($table)->where('section', $menu['section']);

            if (Schema::hasColumn($table, 'parent_id')) {
                $query->whereNull('parent_id');
            }

            if (Schema::hasColumn($table, 'label')) {
                $query->where('label', $label);
            } elseif (Schema::hasColumn($table, 'title')) {
                $query->where('title', $label);
            } else {
                return;
            }

            foreach (['path', 'route', 'href', 'url'] as $routeCol) {
                if (Schema::hasColumn($table, $routeCol)) {
                    $query->where(function ($q) use ($routeCol) {
                        $q->whereNull($routeCol)->orWhere($routeCol, '');
                    });
                    break;
                }
            }

            $existing = $query->first();
        }

        // Do not overwrite created_at on update
        $updateData = $data;
        unset($updateData['created_at']);

        if ($existing) {
            DB::table($table)->where('id', $existing->id)->update($updateData);
        } else {
            DB::table($table)->insert($data);
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
