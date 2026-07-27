<?php

namespace Database\Seeders\Permission;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class RateManagementPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $guardName = 'web';

        $permissions = [
            /*
             * General access.
             */
            'pricing.view',
            'pricing.calculate',

            /*
             * Global pricing settings.
             */
            'pricing.settings.view',
            'pricing.settings.manage',

            /*
             * Service types.
             */
            'pricing.service_types.view',
            'pricing.service_types.manage',

            /*
             * Legacy/direct branch route rates.
             */
            'pricing.branch_route_rates.view',
            'pricing.branch_route_rates.manage',

            /*
             * Direct physical transfer lanes.
             */
            'pricing.transfer_lanes.view',
            'pricing.transfer_lanes.manage',

            /*
             * Complete transfer routes and route base rates.
             */
            'pricing.transfer_routes.view',
            'pricing.transfer_routes.manage',

            /*
             * Pricing quote history.
             */
            'pricing.quotes.view',

            /*
             * Optional future pages.
             */
            'pricing.coverage_health.view',
            'pricing.network_health.view',
            'pricing.audit.view',
        ];

        foreach ($permissions as $permissionName) {
            Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => $guardName,
            ]);
        }

        /*
         * Adjust these role names if your project
         * uses different role codes.
         */

        $superAdmin = Role::query()->firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => $guardName,
        ]);

        $pricingManager = Role::query()->firstOrCreate([
            'name' => 'hq_pricing_manager',
            'guard_name' => $guardName,
        ]);

        $branchManager = Role::query()->firstOrCreate([
            'name' => 'branch_manager',
            'guard_name' => $guardName,
        ]);

        $supportTeam = Role::query()->firstOrCreate([
            'name' => 'support_team',
            'guard_name' => $guardName,
        ]);

        /*
         * Super admin receives all available permissions.
         */
        $superAdmin->syncPermissions(
            Permission::query()
                ->where('guard_name', $guardName)
                ->pluck('name')
                ->all()
        );

        /*
         * HQ pricing manager.
         */
        $pricingManager->syncPermissions([
            'pricing.view',
            'pricing.calculate',

            'pricing.settings.view',
            'pricing.settings.manage',

            'pricing.service_types.view',
            'pricing.service_types.manage',

            'pricing.branch_route_rates.view',
            'pricing.branch_route_rates.manage',

            'pricing.transfer_lanes.view',
            'pricing.transfer_lanes.manage',

            'pricing.transfer_routes.view',
            'pricing.transfer_routes.manage',

            'pricing.quotes.view',
            'pricing.coverage_health.view',
            'pricing.network_health.view',
            'pricing.audit.view',
        ]);

        /*
         * Branch manager can inspect rates and calculate prices,
         * but cannot change HQ pricing configuration.
         */
        $branchManager->syncPermissions([
            'pricing.view',
            'pricing.calculate',

            'pricing.settings.view',
            'pricing.service_types.view',
            'pricing.branch_route_rates.view',
            'pricing.transfer_lanes.view',
            'pricing.transfer_routes.view',
            'pricing.quotes.view',
        ]);

        /*
         * Support can view calculator and quote history.
         */
        $supportTeam->syncPermissions([
            'pricing.view',
            'pricing.calculate',
            'pricing.quotes.view',
        ]);
    }
}