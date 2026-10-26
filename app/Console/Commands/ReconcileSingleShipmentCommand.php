<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Services\BranchShareService;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\BranchAssignmentService;
use Modules\Shipment\Services\CheckoutDeliveryChargeResolver;
use Modules\Shipment\Services\TransferRouteProgressService;

class ReconcileSingleShipmentCommand extends Command
{
    protected $signature = 'shipment:reconcile
        {tracking=TEX-20261012-328515 : Tracking number to reconcile}
        {--dest-branch=18 : Destination branch ID (default 18 for Itahari)}
        {--fare=220.00 : Target delivery charge fare}
        {--dry-run : Preview changes without saving to database}';

    protected $description = 'Reconcile a single shipment with updated destination branch, transfer routes, pricing breakdown, and invoices';

    public function handle(
        CheckoutDeliveryChargeResolver $resolver,
        BranchAssignmentService $assigner,
        TransferRouteProgressService $progressService,
        BranchShareService $branchShares
    ): int {
        $tracking = (string) $this->argument('tracking');
        $destBranchId = (int) $this->option('dest-branch');
        $targetFare = (float) $this->option('fare');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Looking up shipment [{$tracking}]...");

        $shipment = Shipment::where('tracking_number', $tracking)->first();

        if (! $shipment) {
            $this->error("Shipment with tracking number [{$tracking}] not found.");
            return 1;
        }

        $this->line("Found Shipment ID: {$shipment->id}, Status: {$shipment->status}");
        $this->line("Current Origin: #{$shipment->origin_branch_id}, Current Destination: #{$shipment->destination_branch_id}");
        $this->line("Target Destination: #{$destBranchId}, Target Fare: NPR {$targetFare}");

        // 1. Check destination branch in DB
        $destBranch = DB::table('branches')->where('id', $destBranchId)->first();
        if ($destBranch) {
            $this->info("Destination Branch resolved: {$destBranch->name} (#{$destBranch->id}, {$destBranch->city})");
        } else {
            $this->warn("Branch #{$destBranchId} not found in branches table. Proceeding with ID only.");
        }

        // 2. Prepare coordinates & destination
        $shipment->destination_branch_id = $destBranchId;
        $shipment->destination_sub_branch_id = null;

        // If coordinates for delivery are missing, use destination branch coordinates
        if (empty($shipment->delivery_lat) || empty($shipment->delivery_lng)) {
            if ($destBranch && ! empty($destBranch->latitude) && ! empty($destBranch->longitude)) {
                $shipment->delivery_lat = $destBranch->latitude;
                $shipment->delivery_lng = $destBranch->longitude;
                $this->line("Populated delivery coordinates from branch: {$shipment->delivery_lat}, {$shipment->delivery_lng}");
            }
        }

        // 3. Resolve fresh PricingEngine calculation & breakdown
        $this->info("Resolving delivery charge and breakdown via PricingEngine...");
        $priced = [];
        try {
            $priced = $resolver->resolveFromShipment($shipment);
        } catch (\Throwable $e) {
            $this->warn("PricingEngine resolution notice: {$e->getMessage()}");
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

        // 4. Build route and transfer details
        $this->info("Building inter-branch transfer route...");
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

        $this->line("  Route Distance: {$routeDistance} km");
        $this->line("  Transfer Route ID: " . ($transferRouteId ?? 'None'));
        $this->line("  Path: " . ($pathText ?? 'N/A'));

        if ($dryRun) {
            $this->warn("[DRY RUN] No database changes were saved.");
            return 0;
        }

        // Execute reconciliation transaction
        DB::transaction(function () use (
            $shipment,
            $destBranchId,
            $finalFare,
            $breakdown,
            $routeDistance,
            $transferRouteId,
            $routeId,
            $pathText,
            $progressService,
            $branchShares
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

            // Next hop progression
            try {
                $progress = $progressService->resolveForShipment($shipment);
                if (! empty($progress['next_hop_branch_id'])) {
                    $shipment->next_hop_branch_id = $progress['next_hop_branch_id'];
                }
                if (! empty($progress['path_text'])) {
                    $shipment->path_text = $progress['path_text'];
                }
                if (! empty($progress['transfer_leg_index'])) {
                    $shipment->transfer_leg_index = $progress['transfer_leg_index'];
                }
            } catch (\Throwable $e) {
                // Ignore route progression errors
            }

            $shipment->save();

            // Sync secondary tables
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

            // Sync Invoices
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

            // Sync Branch Shares
            if (Schema::hasTable('shipment_branch_shares')) {
                \Modules\Billing\Models\ShipmentBranchShare::where('shipment_id', $shipment->id)
                    ->whereNull('statement_id')
                    ->delete();
                try {
                    $branchShares->ensureForShipment($shipment);
                } catch (\Throwable $e) {
                    // Ignore branch share refresh errors
                }
            }
        });

        $this->info("SUCCESS: Shipment [{$tracking}] reconciled successfully!");
        $this->line("  - Destination Branch: #{$shipment->destination_branch_id}");
        $this->line("  - Delivery Charge: NPR {$shipment->delivery_charge}");
        $this->line("  - Breakdown & Routes: Synchronized");

        return 0;
    }
}

