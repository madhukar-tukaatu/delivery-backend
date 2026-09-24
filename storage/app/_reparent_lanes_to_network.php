<?php
/**
 * One-shot: reparent Transfer Lanes/Routes Pricing -> Network;
 * ensure Branches (/admin/branches) under Network.
 * Keeps user "Franchise Branch" -> /admin/branch-offices.
 */
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

DB::transaction(function () {
    $network = DB::table('menu_items')
        ->where('section', 'admin')
        ->whereNull('parent_id')
        ->where('label', 'Network')
        ->where(function ($q) {
            $q->whereNull('path')->orWhere('path', '');
        })
        ->first();

    if (!$network) {
        throw new RuntimeException('Network parent not found');
    }

    $pricing = DB::table('menu_items')
        ->where('section', 'admin')
        ->whereNull('parent_id')
        ->where('label', 'Pricing')
        ->where(function ($q) {
            $q->whereNull('path')->orWhere('path', '');
        })
        ->first();

    if (!$pricing) {
        throw new RuntimeException('Pricing parent not found');
    }

    echo "Network id={$network->id}, Pricing id={$pricing->id}\n";

    // Ensure Branches under Network
    $branches = DB::table('menu_items')
        ->where('section', 'admin')
        ->where('path', '/admin/branches')
        ->first();

    if ($branches) {
        DB::table('menu_items')->where('id', $branches->id)->update([
            'parent_id' => $network->id,
            'label' => 'Branches',
            'icon' => $branches->icon ?: 'branches',
            'permission' => $branches->permission ?: 'branches.view',
            'sort_order' => 10,
            'is_active' => true,
            'updated_at' => now(),
        ]);
        echo "Updated Branches id={$branches->id} -> Network\n";
    } else {
        $id = DB::table('menu_items')->insertGetId([
            'section' => 'admin',
            'label' => 'Branches',
            'path' => '/admin/branches',
            'icon' => 'branches',
            'permission' => 'branches.view',
            'parent_id' => $network->id,
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        echo "Inserted Branches id={$id} under Network\n";
    }

    // Keep Franchise Branch if present (different path) ? just note it
    $franchise = DB::table('menu_items')
        ->where('section', 'admin')
        ->where('path', '/admin/branch-offices')
        ->first();
    if ($franchise) {
        // Ensure still under Network; do not delete
        if ((int) $franchise->parent_id !== (int) $network->id) {
            DB::table('menu_items')->where('id', $franchise->id)->update([
                'parent_id' => $network->id,
                'updated_at' => now(),
            ]);
            echo "Reparented Franchise Branch id={$franchise->id} -> Network\n";
        } else {
            echo "Kept Franchise Branch id={$franchise->id} under Network\n";
        }
    }

    // Reparent Transfer Lanes + Transfer Routes to Network
    $laneRoutes = [
        '/admin/branch-transfer-lanes' => ['label' => 'Transfer Lanes', 'icon' => 'transfer', 'permission' => 'pricing.transfer_lanes.manage', 'sort' => 20],
        '/admin/branch-transfer-routes' => ['label' => 'Transfer Routes', 'icon' => 'truck', 'permission' => 'pricing.transfer_routes.manage', 'sort' => 30],
    ];

    foreach ($laneRoutes as $path => $meta) {
        $row = DB::table('menu_items')
            ->where('section', 'admin')
            ->where('path', $path)
            ->first();

        if ($row) {
            DB::table('menu_items')->where('id', $row->id)->update([
                'parent_id' => $network->id,
                'label' => $meta['label'],
                'icon' => $row->icon ?: $meta['icon'],
                'permission' => $row->permission ?: $meta['permission'],
                'sort_order' => $meta['sort'],
                'is_active' => true,
                'updated_at' => now(),
            ]);
            echo "Reparented {$meta['label']} id={$row->id} Pricing/elsewhere -> Network sort={$meta['sort']}\n";
        } else {
            $id = DB::table('menu_items')->insertGetId([
                'section' => 'admin',
                'label' => $meta['label'],
                'path' => $path,
                'icon' => $meta['icon'],
                'permission' => $meta['permission'],
                'parent_id' => $network->id,
                'sort_order' => $meta['sort'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            echo "Inserted {$meta['label']} id={$id} under Network\n";
        }
    }

    // Normalize Network sibling sort for remaining known items (do not clobber custom)
    $networkOrder = [
        '/admin/branches' => 10,
        '/admin/branch-offices' => 15, // Franchise Branch between Branches and lanes if present
        '/admin/branch-transfer-lanes' => 20,
        '/admin/branch-transfer-routes' => 30,
        '/admin/coverage-locations' => 40,
        '/admin/branch-staff' => 50,
        '/admin/branch-roles' => 60,
        '/admin/merchants' => 70,
        '/admin/merchant-applications' => 80,
        '/admin/customers' => 90,
    ];
    foreach ($networkOrder as $path => $sort) {
        DB::table('menu_items')
            ->where('section', 'admin')
            ->where('parent_id', $network->id)
            ->where('path', $path)
            ->update(['sort_order' => $sort, 'updated_at' => now()]);
    }

    // Normalize Pricing children sorts (lanes should be gone)
    $pricingOrder = [
        '/admin/rates' => 10,
        '/admin/service-types' => 20,
        '/admin/pricing-test' => 30,
        '/admin/pricing-quotes' => 40,
        '/admin/branch-pricing' => 50,
    ];
    foreach ($pricingOrder as $path => $sort) {
        DB::table('menu_items')
            ->where('section', 'admin')
            ->where('parent_id', $pricing->id)
            ->where('path', $path)
            ->update(['sort_order' => $sort, 'updated_at' => now()]);
    }

    // Safety: any lanes/routes still under Pricing -> Network
    DB::table('menu_items')
        ->where('section', 'admin')
        ->where('parent_id', $pricing->id)
        ->whereIn('path', ['/admin/branch-transfer-lanes', '/admin/branch-transfer-routes'])
        ->update(['parent_id' => $network->id, 'updated_at' => now()]);

    echo "--- Network children ---\n";
    $kids = DB::table('menu_items')
        ->where('parent_id', $network->id)
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get(['id', 'label', 'path', 'sort_order']);
    foreach ($kids as $k) {
        echo "  {$k->sort_order} {$k->label} {$k->path}\n";
    }

    echo "--- Pricing children ---\n";
    $kids = DB::table('menu_items')
        ->where('parent_id', $pricing->id)
        ->orderBy('sort_order')
        ->orderBy('id')
        ->get(['id', 'label', 'path', 'sort_order']);
    foreach ($kids as $k) {
        echo "  {$k->sort_order} {$k->label} {$k->path}\n";
    }

    echo "--- Admin roots ---\n";
    $roots = DB::table('menu_items')
        ->where('section', 'admin')
        ->whereNull('parent_id')
        ->orderBy('sort_order')
        ->get(['id', 'label', 'path', 'sort_order']);
    foreach ($roots as $r) {
        echo "  {$r->sort_order} {$r->label} " . ($r->path ?? '') . "\n";
    }
});

echo "DONE\n";

