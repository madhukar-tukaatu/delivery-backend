<?php

declare(strict_types=1);

/**
 * Standalone Shipment Reconciliation Script
 * ------------------------------------------
 * Usage on server:
 *   php reconcile_shipment.php
 *   php reconcile_shipment.php --tracking=TEX-20261012-328515 --dest-branch=18 --fare=220
 *   php reconcile_shipment.php --dry-run
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Services\BranchShareService;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\BranchAssignmentService;
use Modules\Shipment\Services\CheckoutDeliveryChargeResolver;
use Modules\Shipment\Services\TransferRouteProgressService;

$options = getopt('', ['tracking::', 'dest-branch::', 'fare::', 'dry-run']);

$tracking = (string) ($options['tracking'] ?? 'TEX-20261012-328515');
$destBranchId = (int) ($options['dest-branch'] ?? 18);
$targetFare = (float) ($options['fare'] ?? 220.00);
$dryRun = isset($options['dry-run']);

echo "========================================================\n";
echo " Shipment Reconciliation Script\n";
echo "========================================================\n";
echo "Tracking:     {$tracking}\n";
echo "Target Dest:  Branch #{$destBranchId}\n";
echo "Target Fare:  NPR {$targetFare}\n";
echo "Dry Run:      " . ($dryRun ? 'YES' : 'NO') . "\n";
echo "--------------------------------------------------------\n";

$shipment = Shipment::where('tracking_number', $tracking)->first();

if (! $shipment) {
    echo "ERROR: Shipment with tracking number [{$tracking}] not found.\n";
    exit(1);
}

echo "Found Shipment ID: {$shipment->id}\n";
echo "Current Origin Branch:       #{$shipment->origin_branch_id}\n";
echo "Current Destination Branch:  #{$shipment->destination_branch_id}\n";

$destBranch = DB::table('branches')->where('id', $destBranchId)->first();
if ($destBranch) {
    echo "Destination Branch:          {$destBranch->name} (ID: {$destBranch->id}, City: {$destBranch->city})\n";
} else {
    echo "WARNING: Branch #{$destBranchId} not found in branches table.\n";
}

// 1. Prepare coordinates & branch
$shipment->destination_branch_id = $destBranchId;
$shipment->destination_sub_branch_id = null;

if (empty($shipment->delivery_lat) || empty($shipment->delivery_lng)) {
    if ($destBranch && ! empty($destBranch->latitude) && ! empty($destBranch->longitude)) {
        $shipment->delivery_lat = $destBranch->latitude;
        $shipment->delivery_lng = $destBranch->longitude;
        echo "Coordinates populated from branch: {$shipment->delivery_lat}, {$shipment->delivery_lng}\n";
    }
}

// 2. Re-resolve delivery charge & breakdown from PricingEngine
echo "Resolving PricingEngine breakdown...\n";
$resolver = app(CheckoutDeliveryChargeResolver::class);
$priced = [];
try {
    $priced = $resolver->resolveFromShipment($shipment);
} catch (\Throwable $e) {
    echo "PricingEngine note: {$e->getMessage()}\n";
}

$finalFare = round((float) ($priced['delivery_charge'] ?? $targetFare), 2);
if ($finalFare <= 0) {
    $finalFare = $targetFare;
}

$breakdown = $priced['breakdown'] ?? (is_array($shipment->delivery_charge_breakdown)
    ? $shipment->delivery_charge_breakdown
    : json_decode((string) $shipment->delivery_charge_breakdown, true) ?? []);

if (is_array($breakdown)) {
    $breakdown['final_price'] = $finalFare;
    $breakdown['delivery_charge'] = $finalFare;
    if ($destBranch) {
        $breakdown['delivery_branch'] = [
            'id' => (int) $destBranch->id,
            'name' => (string) $destBranch->name,
            'distance_km' => $breakdown['delivery_branch']['distance_km'] ?? 0,
        ];
    }
}

// 3. Build route & path
echo "Building transfer route...\n";
$assigner = app(BranchAssignmentService::class);
$routeDetails = $assigner->buildRoute(
    ['branch_id' => $shipment->origin_branch_id, 'sub_branch_id' => $shipment->origin_sub_branch_id],
    ['branch_id' => $shipment->destination_branch_id, 'sub_branch_id' => $shipment->destination_sub_branch_id],
    $shipment->delivery_lat !== null ? (float) $shipment->delivery_lat : null,
    $shipment->delivery_lng !== null ? (float) $shipment->delivery_lng : null,
    (string) ($shipment->service_type ?? 'standard')
);

$routeDistance = $routeDetails['total_distance_km'] ?? $routeDetails['distance_km'] ?? 0;
$transferRouteId = $routeDetails['transfer_route_id'] ?? null;
$routeId = $routeDetails['route_id'] ?? null;
$pathText = $routeDetails['path_text'] ?? null;

echo "  Distance:   {$routeDistance} km\n";
echo "  Transfer Route ID: " . ($transferRouteId ?? 'None') . "\n";
echo "  Path:       " . ($pathText ?? 'N/A') . "\n";

if ($dryRun) {
    echo "\n[DRY RUN] No changes were written to database.\n";
    exit(0);
}

// 4. Execute DB updates in transaction
DB::transaction(function () use (
    $shipment,
    $destBranchId,
    $finalFare,
    $breakdown,
    $routeDistance,
    $transferRouteId,
    $routeId,
    $pathText
): void {
    $shipment->destination_branch_id = $destBranchId;
    $shipment->destination_sub_branch_id = null;
    $shipment->delivery_charge = $finalFare;
    $shipment->delivery_charge_breakdown = $breakdown;
    $shipment->route_distance_km = $routeDistance;

    if (! empty($transferRouteId)) {
        $shipment->transfer_route_id = $transferRouteId;
    }
    if (! empty($routeId) && DB::table('delivery_routes')->where('id', $routeId)->exists()) {
        $shipment->route_id = $routeId;
    }
    if (! empty($pathText)) {
        $shipment->path_text = $pathText;
    }

    try {
        $progress = app(TransferRouteProgressService::class)->resolveForShipment($shipment);
        if (! empty($progress['next_hop_branch_id'])) {
            $shipment->next_hop_branch_id = $progress['next_hop_branch_id'];
        }
        if (! empty($progress['path_text'])) {
            $shipment->path_text = $progress['path_text'];
        }
        if (! empty($progress['transfer_leg_index'])) {
            $shipment->transfer_leg_index = $progress['transfer_leg_index'];
        }
    } catch (\Throwable $e) {}

    $shipment->save();

    // Secondary tables
    if (Schema::hasTable('shipment_charges')) {
        DB::table('shipment_charges')->where('shipment_id', $shipment->id)->update([
            'delivery_charge' => $finalFare,
            'breakdown'       => json_encode($breakdown),
            'updated_at'      => now(),
        ]);
    }

    if (Schema::hasTable('shipment_price_breakdowns')) {
        DB::table('shipment_price_breakdowns')->updateOrInsert(
            ['shipment_id' => $shipment->id],
            [
                'final_price'   => $finalFare,
                'snapshot_json' => json_encode($breakdown),
                'updated_at'    => now(),
            ]
        );
    }

    // Invoices
    $invoices = \Modules\Billing\Models\Invoice::where('shipment_id', $shipment->id)->get();
    foreach ($invoices as $inv) {
        $inv->subtotal = $finalFare;
        $inv->total_amount = $finalFare;
        $inv->save();

        \Modules\Billing\Models\InvoiceItem::where('invoice_id', $inv->id)->delete();
        \Modules\Billing\Models\InvoiceItem::create([
            'invoice_id'  => $inv->id,
            'description' => "Delivery charge {$shipment->tracking_number}",
            'quantity'    => 1,
            'unit_price'  => $finalFare,
            'total'       => $finalFare,
        ]);
    }

    // Branch Shares
    if (Schema::hasTable('shipment_branch_shares')) {
        \Modules\Billing\Models\ShipmentBranchShare::where('shipment_id', $shipment->id)
            ->whereNull('statement_id')
            ->delete();
        try {
            app(BranchShareService::class)->ensureForShipment($shipment);
        } catch (\Throwable $e) {}
    }
});

echo "\n--------------------------------------------------------\n";
echo "SUCCESS: Shipment [{$tracking}] reconciled successfully!\n";
echo "--------------------------------------------------------\n";

