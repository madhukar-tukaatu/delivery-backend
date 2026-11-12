<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Settlement\Services\SettlementWorkflowService;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\CheckoutDeliveryChargeResolver;

Artisan::command('courier:hello', function () {
    $this->info('Tukaatu Express is ready.');
});

/**
 * Rebuild unpaid INV-DLV delivery charge bills from PricingEngine amounts.
 *
 * Usage:
 *   php artisan billing:reconcile-delivery-charges
 *   php artisan billing:reconcile-delivery-charges --dry-run
 *   php artisan billing:reconcile-delivery-charges --invoice=INV-DLV-20260928112148-581
 */
Artisan::command('billing:reconcile-delivery-charges {--dry-run : Report only} {--invoice= : Limit to one invoice_number}', function (CheckoutDeliveryChargeResolver $resolver) {
    $dry = (bool) $this->option('dry-run');
    $invoiceNumber = $this->option('invoice');

    $query = Invoice::query()
        ->with('items')
        ->where('type', 'delivery_charges')
        ->where('status', 'unpaid')
        ->whereNotNull('shipment_id')
        ->orderBy('id');

    if ($invoiceNumber) {
        $query->where('invoice_number', $invoiceNumber);
    }

    $updated = 0;
    $skipped = 0;

    foreach ($query->cursor() as $invoice) {
        /** @var Invoice $invoice */
        $shipment = Shipment::query()->find($invoice->shipment_id);
        if (! $shipment) {
            $this->warn("Invoice {$invoice->invoice_number}: shipment missing, skip");
            $skipped++;
            continue;
        }

        // A marketplace bill was already issued with this amount; changing the
        // line would make the bill total wrong. Cancel the bill first.
        if (! empty($invoice->marketplace_bill_id)) {
            $this->warn("Invoice {$invoice->invoice_number}: on marketplace bill #{$invoice->marketplace_bill_id}, skip");
            $skipped++;
            continue;
        }

        $priced = $resolver->resolveFromShipment($shipment);
        $fullFare = round((float) $priced['delivery_charge'], 2);
        $payerType = strtolower((string) ($invoice->payer_type ?? 'merchant'));
        $split = SettlementWorkflowService::splitDeliveryCharge(
            $fullFare,
            (string) ($shipment->delivery_free_by ?? 'none'),
            (string) ($shipment->delivery_charge_paid_by ?? 'customer'),
        );
        $podFee = round((float) ($shipment->pod_charge ?? $priced['pod_charge'] ?? 0), 2);
        $freeBy = (string) ($split['free_by'] ?? 'none');
        if ($payerType === 'company') {
            $deliveryFee = (float) $split['marketplace_share'];
            $podFee = 0.0;
            $lineDescription = 'Free delivery (marketplace) '.$shipment->tracking_number;
        } else {
            $deliveryFee = (float) $split['store_share'];
            $lineDescription = $freeBy === 'store'
                ? 'Free delivery (store) '.$shipment->tracking_number
                : 'Delivery charge '.$shipment->tracking_number;
        }
        $subtotal = round($deliveryFee + $podFee, 2);

        $before = round((float) $invoice->total_amount, 2);
        $oldShipmentCharge = round((float) $shipment->delivery_charge, 2);

        $this->line(sprintf(
            '%s shipment=%s payer=%s source=%s shipment_charge %.2f -> %.2f | bill %.2f -> %.2f',
            $invoice->invoice_number,
            $shipment->tracking_number,
            $payerType,
            $priced['source'] ?? '?',
            $oldShipmentCharge,
            $fullFare,
            $before,
            $subtotal
        ));

        if ($subtotal <= 0) {
            $this->warn('  -> computed amount is 0; leave invoice unchanged');
            $skipped++;
            continue;
        }

        if (abs($before - $subtotal) < 0.009 && abs($oldShipmentCharge - $fullFare) < 0.009) {
            $skipped++;
            continue;
        }

        if ($dry) {
            $updated++;
            continue;
        }

        DB::transaction(function () use ($invoice, $shipment, $deliveryFee, $podFee, $subtotal, $priced, $fullFare, $lineDescription) {
            $shipment->delivery_charge = $fullFare;
            if (! empty($priced['breakdown'])) {
                $shipment->delivery_charge_breakdown = $priced['breakdown'];
            }
            if ($podFee > 0 && (float) ($shipment->pod_charge ?? 0) <= 0) {
                $shipment->pod_charge = $podFee;
            }
            $shipment->save();

            $invoice->subtotal = $subtotal;
            $invoice->tax_amount = 0;
            $invoice->total_amount = $subtotal;
            $invoice->save();

            InvoiceItem::query()->where('invoice_id', $invoice->id)->delete();

            if ($deliveryFee > 0) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $lineDescription,
                    'quantity' => 1,
                    'unit_price' => $deliveryFee,
                    'total' => $deliveryFee,
                ]);
            }

            if ($podFee > 0) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => 'POD service fee '.$shipment->tracking_number,
                    'quantity' => 1,
                    'unit_price' => $podFee,
                    'total' => $podFee,
                ]);
            }
        });

        $updated++;
    }

    $this->info(($dry ? 'Dry-run' : 'Updated')." rows touched={$updated}, skipped={$skipped}");
})->purpose('Recalculate unpaid delivery charge bills from PricingEngine');

/**
 * Reconcile shipments with corrected weight, recalculating delivery charges,
 * and synchronizing all downstream tables (breakdowns, invoices, branch shares,
 * commission bills, settlements, marketplace bills).
 *
 * Usage:
 *   php artisan finance:reconcile-shipment-weights --dry-run
 *   php artisan finance:reconcile-shipment-weights
 *   php artisan finance:reconcile-shipment-weights --target-weight=1.5
 */
Artisan::command('finance:reconcile-shipment-weights
    {--target-weight=1.5 : Target chargeable weight in kg}
    {--trackings= : Comma-separated tracking numbers (leave empty for default 14)}
    {--dry-run : Preview calculations without modifying database}',
    function (
        \Modules\Shipment\Services\CheckoutDeliveryChargeResolver $resolver,
        \Modules\Settlement\Services\SettlementWorkflowService $settlementWorkflow,
        \Modules\Billing\Services\BranchShareService $branchShares,
        \Modules\Billing\Services\BranchCommissionService $commissionService
    ) {
        $dry = (bool) $this->option('dry-run');
        $targetWeight = (float) $this->option('target-weight');
        $trackingInput = $this->option('trackings');

        $defaultTrackings = [
            'TEX-20260929-761355',
            'TEX-20261002-410108',
            'TEX-20261004-940000',
            'TEX-20261005-348246',
            'TEX-20261006-313385',
            'TEX-20261008-349950',
            'TEX-20261008-865086',
            'TEX-20261010-063516',
            'TEX-20261010-295085',
            'TEX-20261010-545039',
            'TEX-20261011-164414',
            'TEX-20261012-530417',
            'TEX-20261012-314732',
            'TEX-20261012-328515',
        ];

        $trackingNumbers = $trackingInput
            ? array_filter(array_map('trim', explode(',', $trackingInput)))
            : $defaultTrackings;

        $this->info(($dry ? '[DRY RUN] ' : '') . "Starting reconciliation for " . count($trackingNumbers) . " tracking numbers to {$targetWeight} kg...");

        $shipments = \Modules\Shipment\Models\Shipment::query()
            ->whereIn('tracking_number', $trackingNumbers)
            ->get();

        if ($shipments->isEmpty()) {
            $this->warn("No shipments found for the provided tracking numbers in the local database.");
            return;
        }

        $touchedShipments = 0;

        $exactFares = [
            'TEX-20260929-761355' => 99.00,
            'TEX-20261002-410108' => 99.00,
            'TEX-20261004-940000' => 246.13,
            'TEX-20261005-348246' => 118.80,
            'TEX-20261006-313385' => 173.25,
            'TEX-20261008-349950' => 128.29,
            'TEX-20261008-865086' => 128.29,
            'TEX-20261010-063516' => 213.80,
            'TEX-20261010-295085' => 99.00,
            'TEX-20261010-545039' => 249.00,
            'TEX-20261011-164414' => 99.00,
            'TEX-20261012-530417' => 99.00,
            'TEX-20261012-314732' => 99.00,
            'TEX-20261012-328515' => 99.00,
        ];

        foreach ($shipments as $shipment) {
            $this->line("----------------------------------------------------------------");
            $this->info("Shipment [{$shipment->tracking_number}] (ID: {$shipment->id}, Status: {$shipment->status})");

            $oldWeight = (float) $shipment->weight;
            $oldFare = (float) $shipment->delivery_charge;

            // Set target weight
            $shipment->weight = $targetWeight;
            if (\Illuminate\Support\Facades\Schema::hasColumn('shipments', 'chargeable_weight')) {
                $shipment->chargeable_weight = $targetWeight;
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('shipments', 'actual_weight')) {
                $shipment->actual_weight = $targetWeight;
            }

            if (isset($exactFares[$shipment->tracking_number])) {
                $newFare = round((float) $exactFares[$shipment->tracking_number], 2);
                $fareSource = 'marketplace_checkout_order';
                $priced = [];
                try {
                    $priced = $resolver->resolveFromShipment($shipment);
                } catch (\Throwable $e) {
                    // Resolver might fail if coordinates are missing, but we have the exact fare
                }
            } else {
                $priced = $resolver->resolveFromShipment($shipment);
                $newFare = round((float) $priced['delivery_charge'], 2);
                $fareSource = $priced['source'] ?? 'pricing_engine';
            }

            $this->line("  Weight: {$oldWeight} kg -> {$targetWeight} kg");
            $this->line("  Delivery Charge: NPR {$oldFare} -> NPR {$newFare} (Source: {$fareSource})");

            if ($dry) {
                $touchedShipments++;
                continue;
            }

            $syncStats = \Illuminate\Support\Facades\DB::transaction(function () use (
                $shipment,
                $targetWeight,
                $newFare,
                $oldFare,
                $priced,
                $settlementWorkflow,
                $branchShares,
                $commissionService
            ) {
                // 1. SHIPMENT & COLLECTABLE
                $shipment->weight = $targetWeight;
                if (\Illuminate\Support\Facades\Schema::hasColumn('shipments', 'actual_weight')) {
                    $shipment->actual_weight = $targetWeight;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('shipments', 'chargeable_weight')) {
                    $shipment->chargeable_weight = $targetWeight;
                }
                $shipment->delivery_charge = $newFare;
                
                $breakdown = $priced['breakdown'] ?? ($shipment->delivery_charge_breakdown ? (is_array($shipment->delivery_charge_breakdown) ? $shipment->delivery_charge_breakdown : json_decode($shipment->delivery_charge_breakdown, true)) : []);
                if (is_array($breakdown)) {
                    $breakdown['final_price'] = $newFare;
                    $breakdown['delivery_charge'] = $newFare;
                    $shipment->delivery_charge_breakdown = $breakdown;
                }

                // Maintain route_distance_km and route details (road/flight lane + last mile)
                if (\Illuminate\Support\Facades\Schema::hasColumn('shipments', 'route_distance_km')) {
                    $assigner = app(\Modules\Shipment\Services\BranchAssignmentService::class);
                    $routeDetails = $assigner->buildRoute(
                        ['branch_id' => $shipment->origin_branch_id, 'sub_branch_id' => $shipment->origin_sub_branch_id],
                        ['branch_id' => $shipment->destination_branch_id, 'sub_branch_id' => $shipment->destination_sub_branch_id],
                        $shipment->delivery_lat !== null ? (float) $shipment->delivery_lat : null,
                        $shipment->delivery_lng !== null ? (float) $shipment->delivery_lng : null,
                        (string) ($shipment->service_type ?? 'standard')
                    );
                    $shipment->route_distance_km = $routeDetails['total_distance_km'] ?? $routeDetails['distance_km'] ?? 0;
                    if (\Illuminate\Support\Facades\Schema::hasColumn('shipments', 'transfer_route_id') && !empty($routeDetails['transfer_route_id']) && empty($shipment->transfer_route_id)) {
                        $shipment->transfer_route_id = $routeDetails['transfer_route_id'];
                    }
                    if (\Illuminate\Support\Facades\Schema::hasColumn('shipments', 'route_id') && !empty($routeDetails['route_id']) && empty($shipment->route_id)) {
                        if (\Illuminate\Support\Facades\DB::table('delivery_routes')->where('id', $routeDetails['route_id'])->exists()) {
                            $shipment->route_id = $routeDetails['route_id'];
                        }
                    }
                    if (\Illuminate\Support\Facades\Schema::hasColumn('shipments', 'path_text') && !empty($routeDetails['path_text']) && empty($shipment->path_text)) {
                        $shipment->path_text = $routeDetails['path_text'];
                    }
                }

                $paidBy = strtolower((string) ($shipment->delivery_charge_paid_by ?? 'customer'));
                $podAmount = (float) ($shipment->pod_amount ?? 0);
                $shipment->total_collectable_amount = $podAmount > 0 ? round($podAmount, 2) : ($paidBy === 'customer' ? round($newFare, 2) : 0.00);
                $shipment->save();

                // 2. SECONDARY CHARGE & TRANSACTION TABLES
                if (\Illuminate\Support\Facades\Schema::hasTable('shipment_charges')) {
                    \Illuminate\Support\Facades\DB::table('shipment_charges')
                        ->where('shipment_id', $shipment->id)
                        ->update([
                            'delivery_charge' => $newFare,
                            'breakdown'       => json_encode($breakdown),
                            'updated_at'      => now(),
                        ]);
                }

                if (\Illuminate\Support\Facades\Schema::hasTable('pod_transactions')) {
                    \Illuminate\Support\Facades\DB::table('pod_transactions')
                        ->where('shipment_id', $shipment->id)
                        ->update([
                            'delivery_charge' => $newFare,
                            'total_collected' => $shipment->total_collectable_amount,
                            'updated_at'      => now(),
                        ]);
                }

                if (\Illuminate\Support\Facades\Schema::hasTable('shipment_charge_breakdowns')) {
                    \Illuminate\Support\Facades\DB::table('shipment_charge_breakdowns')
                        ->where('shipment_id', $shipment->id)
                        ->update([
                            'delivery_charge' => $newFare,
                            'updated_at'      => now(),
                        ]);
                }

                // 3. SHIPMENT PRICE BREAKDOWNS TABLE
                if (\Illuminate\Support\Facades\Schema::hasTable('shipment_price_breakdowns')) {
                    \Illuminate\Support\Facades\DB::table('shipment_price_breakdowns')
                        ->updateOrInsert(
                            ['shipment_id' => $shipment->id],
                            [
                                'final_price' => $newFare,
                                'delivery_extra_charge' => $breakdown['delivery_extra_charge'] ?? $breakdown['breakdown']['extra_delivery_distance']['total'] ?? 0.00,
                                'weight_charge' => 0.00,
                                'snapshot_json' => json_encode($breakdown),
                                'updated_at' => now(),
                            ]
                        );
                }

                // 4. INVOICES, ITEMS, PAYMENTS & MARKETPLACE BILLS
                try {
                    app(\Modules\Billing\Services\MerchantDeliveryBillingService::class)->autoEnsureForShipment($shipment);
                } catch (\Throwable $e) {
                    // Ignore if autoEnsure is not applicable
                }

                $split = \Modules\Settlement\Services\SettlementWorkflowService::splitDeliveryCharge(
                    $newFare,
                    (string) ($shipment->delivery_free_by ?? 'none'),
                    (string) ($shipment->delivery_charge_paid_by ?? 'customer')
                );

                $invoices = \Modules\Billing\Models\Invoice::query()->where('shipment_id', $shipment->id)->get();
                $touchedMarketplaceBillIds = [];

                foreach ($invoices as $invoice) {
                    $oldInvoiceTotal = (float) $invoice->total_amount;
                    $payerType = strtolower((string) ($invoice->payer_type ?? 'merchant'));
                    $podFee = round((float) ($shipment->pod_charge ?? $priced['pod_charge'] ?? 0), 2);

                    if ($payerType === 'company') {
                        $fareShare = (float) ($split['marketplace_share'] > 0 ? $split['marketplace_share'] : $newFare);
                        $podFee = 0.0;
                    } else {
                        $fareShare = (float) ($split['store_share'] > 0 ? $split['store_share'] : $newFare);
                    }

                    $newTotal = round($fareShare + $podFee, 2);
                    $invoice->subtotal = $newTotal;
                    $invoice->total_amount = $newTotal;

                    // Sync single 1-to-1 payment if it matched old invoice total exactly
                    $existingPayments = \Modules\Billing\Models\MerchantInvoicePayment::where('invoice_id', $invoice->id)->get();
                    if ($existingPayments->count() === 1 && abs((float) $existingPayments->first()->amount - $oldInvoiceTotal) < 0.01) {
                        $singlePayment = $existingPayments->first();
                        $singlePayment->amount = $newTotal;
                        $singlePayment->save();
                    }

                    // Recheck recorded payments against new total
                    $recordedPayments = (float) \Modules\Billing\Models\MerchantInvoicePayment::where('invoice_id', $invoice->id)
                        ->where('status', 'recorded')
                        ->sum('amount');

                    if ($recordedPayments >= $newTotal && $newTotal > 0) {
                        $invoice->status = 'paid';
                    } elseif ($recordedPayments > 0) {
                        $invoice->status = 'partially_paid';
                    } else {
                        $invoice->status = 'unpaid';
                    }

                    if (\Illuminate\Support\Facades\Schema::hasColumn('invoices', 'paid_amount')) {
                        $invoice->paid_amount = $recordedPayments;
                    }
                    if (\Illuminate\Support\Facades\Schema::hasColumn('invoices', 'outstanding_amount')) {
                        $invoice->outstanding_amount = max(0.0, round($newTotal - $recordedPayments, 2));
                    }

                    $invoice->save();

                    // Rebuild invoice items
                    \Modules\Billing\Models\InvoiceItem::where('invoice_id', $invoice->id)->delete();
                    if ($fareShare > 0) {
                        \Modules\Billing\Models\InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'description' => "Delivery charge {$shipment->tracking_number}",
                            'quantity' => 1,
                            'unit_price' => $fareShare,
                            'total' => $fareShare,
                        ]);
                    }
                    if ($podFee > 0) {
                        \Modules\Billing\Models\InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'description' => "POD service fee {$shipment->tracking_number}",
                            'quantity' => 1,
                            'unit_price' => $podFee,
                            'total' => $podFee,
                        ]);
                    }

                    if (!empty($invoice->marketplace_bill_id)) {
                        $touchedMarketplaceBillIds[] = $invoice->marketplace_bill_id;
                    }
                }

                // Recalculate Marketplace Bills and payments if any invoices belonged to one
                foreach (array_unique($touchedMarketplaceBillIds) as $billId) {
                    $mbill = \Modules\Billing\Models\MarketplaceBill::find($billId);
                    if ($mbill) {
                        $oldBillTotal = (float) $mbill->total_amount;
                        $sumTotal = round((float) $mbill->invoices()->sum('total_amount'), 2);
                        $mbill->total_amount = $sumTotal;

                        // Check single bill payment sync if it matched old bill total
                        $mbillPayments = \Modules\Billing\Models\MarketplaceBillPayment::where('marketplace_bill_id', $mbill->id)->get();
                        if ($mbillPayments->count() === 1 && abs((float) $mbillPayments->first()->amount - $oldBillTotal) < 0.01) {
                            $singleMbillPayment = $mbillPayments->first();
                            $singleMbillPayment->amount = $sumTotal;
                            $singleMbillPayment->save();
                        }

                        $recordedMbillPayments = (float) \Modules\Billing\Models\MarketplaceBillPayment::where('marketplace_bill_id', $mbill->id)
                            ->where('status', 'recorded')
                            ->sum('amount');

                        $mbill->paid_amount = $recordedMbillPayments;
                        $mbill->outstanding_amount = max(0.0, round($sumTotal - $recordedMbillPayments, 2));

                        if ($recordedMbillPayments >= $sumTotal && $sumTotal > 0) {
                            $mbill->status = 'paid';
                        } elseif ($recordedMbillPayments > 0) {
                            $mbill->status = 'partially_paid';
                        }
                        $mbill->save();
                    }
                }

                // 5. SHIPMENT BRANCH SHARES, HQ COMMISSION BILLS, & COMMISSION SETTLEMENTS
                if (\Illuminate\Support\Facades\Schema::hasTable('shipment_branch_shares')) {
                    \Modules\Billing\Models\ShipmentBranchShare::where('shipment_id', $shipment->id)
                        ->whereNull('statement_id')
                        ->delete();

                    \Modules\Billing\Models\BranchCommissionBill::where('shipment_id', $shipment->id)
                        ->where('status', 'unpaid')
                        ->whereNull('settlement_id')
                        ->delete();

                    $origStatus = $shipment->status;
                    if (strtolower((string) $origStatus) !== 'delivered') {
                        $shipment->status = 'delivered';
                        $shipment->save();
                    }
                    $shareOutcome = $branchShares->ensureForShipment($shipment);
                    $commissionService->autoEnsureForShipment($shipment, true);
                    if ($origStatus !== 'delivered') {
                        $shipment->status = $origStatus;
                        $shipment->save();
                    }

                    // For any commission bill that had a settlement_id, update its amounts to match the new shares
                    $commissionBills = \Modules\Billing\Models\BranchCommissionBill::where('shipment_id', $shipment->id)->get();
                    foreach ($commissionBills as $cbill) {
                        $matchingShare = \Modules\Billing\Models\ShipmentBranchShare::where('shipment_id', $shipment->id)
                            ->where('branch_id', $cbill->branch_id)
                            ->first();
                        if ($matchingShare) {
                            $cbill->delivery_charge_base = round((float) $matchingShare->allocation_amount, 2);
                            $cbill->commission_rate = (float) $matchingShare->hq_rate;
                            $cbill->commission_amount = round((float) $matchingShare->hq_commission_amount, 2);
                            $cbill->save();
                        }
                    }

                    // Recalculate linked BranchCommissionSettlements
                    $settlementIds = $commissionBills->pluck('settlement_id')->filter()->unique();
                    foreach ($settlementIds as $sId) {
                        $bSettlement = \Modules\Billing\Models\BranchCommissionSettlement::find($sId);
                        if ($bSettlement) {
                            $totalCommission = round((float) $bSettlement->bills()->sum('commission_amount'), 2);
                            $bSettlement->total_commission = $totalCommission;
                            $bSettlement->final_payable_amount = round($totalCommission + (float) ($bSettlement->adjustments ?? 0), 2);
                            $bSettlement->save();
                        }
                    }
                }

                // 6. MERCHANT SETTLEMENTS & SETTLEMENT ITEMS
                $settlementItems = \Modules\Settlement\Models\MerchantSettlementItem::where('shipment_id', $shipment->id)->get();
                foreach ($settlementItems as $item) {
                    $podAmount = (float) ($shipment->pod_amount ?? $item->pod_amount ?? 0);
                    $itemFare = ($paidBy === 'customer') ? 0.00 : $newFare;
                    $item->update([
                        'pod_amount' => $podAmount,
                        'delivery_charge' => $itemFare,
                        'net_amount' => $podAmount,
                    ]);

                    $settlement = \Modules\Settlement\Models\MerchantSettlement::find($item->merchant_settlement_id);
                    if ($settlement) {
                        $settlementWorkflow->recalcSettlementTotals($settlement);
                    }
                }

                // 7. DELIVERY ASSIGNMENTS
                $assignments = \Modules\Delivery\Models\DeliveryAssignment::where('shipment_id', $shipment->id)->get();
                foreach ($assignments as $assignment) {
                    if ($assignment->pod_collected_amount !== null && (float) $assignment->pod_collected_amount > 0) {
                        $oldExpected = round((float) ($shipment->pod_amount ?? 0) + (float) $oldFare, 2);
                        if (abs((float) $assignment->pod_collected_amount - $oldExpected) < 0.01) {
                            $assignment->pod_collected_amount = $shipment->total_collectable_amount;
                            $assignment->save();
                        }
                    }
                }

                return [
                    'invoices_count' => count($invoices ?? []),
                    'shares_count' => \Modules\Billing\Models\ShipmentBranchShare::where('shipment_id', $shipment->id)->count(),
                    'bills_count' => \Modules\Billing\Models\BranchCommissionBill::where('shipment_id', $shipment->id)->count(),
                    'settlement_items_count' => count($settlementItems ?? []),
                ];
            });

            $touchedShipments++;
            $this->info("  -> Synchronized successfully: [Invoices: {$syncStats['invoices_count']}, Branch Shares: {$syncStats['shares_count']}, Commission Bills: {$syncStats['bills_count']}, Settlement Items: {$syncStats['settlement_items_count']}]");
        }

        $this->info(($dry ? '[DRY RUN COMPLETE] ' : '[SUCCESS] ') . "Processed {$touchedShipments} shipment(s).");
    }
)->purpose('Reconcile shipments to target weight and sync all financial tables');

Artisan::command(
    'finance:sync-all-shipment-tables {--trackings= : Comma-separated list of tracking numbers} {--all : Process all delivered shipments} {--dry-run : Preview changes without saving}',
    function (
        \Modules\Settlement\Services\SettlementWorkflowService $settlementWorkflow,
        \Modules\Billing\Services\BranchShareService $branchShares,
        \Modules\Billing\Services\BranchCommissionService $commissionService
    ) {
        $dry = (bool) $this->option('dry-run');
        $trackingInput = $this->option('trackings');
        $all = (bool) $this->option('all');

        $exactFares = [
            'TEX-20260929-761355' => 99.00,
            'TEX-20261002-410108' => 99.00,
            'TEX-20261004-940000' => 246.13,
            'TEX-20261005-348246' => 118.80,
            'TEX-20261006-313385' => 173.25,
            'TEX-20261008-349950' => 128.29,
            'TEX-20261008-865086' => 128.29,
            'TEX-20261010-063516' => 213.80,
            'TEX-20261010-295085' => 99.00,
            'TEX-20261010-545039' => 249.00,
            'TEX-20261011-164414' => 99.00,
            'TEX-20261012-530417' => 99.00,
            'TEX-20261012-314732' => 99.00,
            'TEX-20261012-328515' => 99.00,
        ];

        $defaultTrackings = array_keys($exactFares);

        $query = \Modules\Shipment\Models\Shipment::query();
        if ($trackingInput) {
            $trackings = array_filter(array_map('trim', explode(',', $trackingInput)));
            $query->whereIn('tracking_number', $trackings);
        } elseif (! $all) {
            $query->whereIn('tracking_number', $defaultTrackings);
        }

        $shipments = $query->orderBy('id')->get();

        if ($shipments->isEmpty()) {
            $this->warn("No shipments found matching criteria.");
            return;
        }

        $this->info(($dry ? '[DRY RUN] ' : '') . "Starting downstream financial tables synchronization for " . $shipments->count() . " shipment(s)...");

        $touchedShipments = 0;

        foreach ($shipments as $shipment) {
            $this->line("----------------------------------------------------------------");
            $targetFare = round((float) ($exactFares[$shipment->tracking_number] ?? $shipment->delivery_charge), 2);
            $targetWeight = round((float) ($shipment->weight ?? 1.5), 2);

            $this->info("Shipment [{$shipment->tracking_number}] (ID: {$shipment->id}, Status: {$shipment->status})");
            $this->line("  Target Delivery Charge: NPR {$targetFare} | Target Weight: {$targetWeight} kg");

            if ($dry) {
                $this->line("  [DRY RUN] Would sync: invoices, branch shares, commission bills, breakdowns, settlements.");
                $touchedShipments++;
                continue;
            }

            $stats = \Illuminate\Support\Facades\DB::transaction(function () use (
                $shipment,
                $targetFare,
                $targetWeight,
                $settlementWorkflow,
                $branchShares,
                $commissionService
            ) {
                // 1. UPDATE SHIPMENT
                $shipment->weight = $targetWeight;
                if (\Illuminate\Support\Facades\Schema::hasColumn('shipments', 'actual_weight')) {
                    $shipment->actual_weight = $targetWeight;
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('shipments', 'chargeable_weight')) {
                    $shipment->chargeable_weight = $targetWeight;
                }
                $shipment->delivery_charge = $targetFare;

                $breakdown = $shipment->delivery_charge_breakdown;
                if (is_string($breakdown)) {
                    $breakdown = json_decode($breakdown, true);
                }
                if (is_array($breakdown)) {
                    $breakdown['final_price'] = $targetFare;
                    $breakdown['delivery_charge'] = $targetFare;
                    $shipment->delivery_charge_breakdown = $breakdown;
                }

                $paidBy = strtolower((string) ($shipment->delivery_charge_paid_by ?? 'customer'));
                $podAmount = (float) ($shipment->pod_amount ?? 0);
                $shipment->total_collectable_amount = $podAmount > 0 ? round($podAmount, 2) : ($paidBy === 'customer' ? round($targetFare, 2) : 0.00);
                $shipment->save();

                // 2. SECONDARY CHARGE & TRANSACTION TABLES
                if (\Illuminate\Support\Facades\Schema::hasTable('shipment_charges')) {
                    \Illuminate\Support\Facades\DB::table('shipment_charges')
                        ->where('shipment_id', $shipment->id)
                        ->update([
                            'delivery_charge' => $targetFare,
                            'breakdown'       => json_encode($breakdown),
                            'updated_at'      => now(),
                        ]);
                }

                if (\Illuminate\Support\Facades\Schema::hasTable('pod_transactions')) {
                    \Illuminate\Support\Facades\DB::table('pod_transactions')
                        ->where('shipment_id', $shipment->id)
                        ->update([
                            'delivery_charge' => $targetFare,
                            'total_collected' => $shipment->total_collectable_amount,
                            'updated_at'      => now(),
                        ]);
                }

                if (\Illuminate\Support\Facades\Schema::hasTable('shipment_charge_breakdowns')) {
                    \Illuminate\Support\Facades\DB::table('shipment_charge_breakdowns')
                        ->where('shipment_id', $shipment->id)
                        ->update([
                            'delivery_charge' => $targetFare,
                            'updated_at'      => now(),
                        ]);
                }

                if (\Illuminate\Support\Facades\Schema::hasTable('shipment_price_breakdowns')) {
                    \Illuminate\Support\Facades\DB::table('shipment_price_breakdowns')
                        ->updateOrInsert(
                            ['shipment_id' => $shipment->id],
                            [
                                'final_price' => $targetFare,
                                'delivery_extra_charge' => $breakdown['delivery_extra_charge'] ?? $breakdown['breakdown']['extra_delivery_distance']['total'] ?? 0.00,
                                'weight_charge' => 0.00,
                                'snapshot_json' => json_encode($breakdown),
                                'updated_at' => now(),
                            ]
                        );
                }

                // 3. INVOICES, ITEMS, PAYMENTS & MARKETPLACE BILLS
                $invoices = \Modules\Billing\Models\Invoice::query()->where('shipment_id', $shipment->id)->get();
                if ($invoices->isEmpty() && strtolower((string) $shipment->status) === 'delivered') {
                    try {
                        app(\Modules\Billing\Services\MerchantDeliveryBillingService::class)->autoEnsureForShipment($shipment);
                        $invoices = \Modules\Billing\Models\Invoice::query()->where('shipment_id', $shipment->id)->get();
                    } catch (\Throwable $e) {}
                }

                $invoiceSummary = [];
                $touchedMarketplaceBillIds = [];

                foreach ($invoices as $invoice) {
                    $oldInvoiceTotal = (float) $invoice->total_amount;
                    $payerType = strtolower((string) ($invoice->payer_type ?? 'merchant'));
                    $podFee = round((float) ($shipment->pod_charge ?? 0), 2);

                    if ($payerType === 'company') {
                        $fareShare = $targetFare;
                        $podFee = 0.0;
                    } else {
                        // Store invoice
                        $fareShare = $targetFare;
                    }

                    $newTotal = round($fareShare + $podFee, 2);
                    $invoice->subtotal = $newTotal;
                    $invoice->total_amount = $newTotal;

                    // Sync single payment if matching previous amount
                    $existingPayments = \Modules\Billing\Models\MerchantInvoicePayment::where('invoice_id', $invoice->id)->get();
                    if ($existingPayments->count() === 1 && abs((float) $existingPayments->first()->amount - $oldInvoiceTotal) < 0.01) {
                        $singlePayment = $existingPayments->first();
                        $singlePayment->amount = $newTotal;
                        $singlePayment->save();
                    }

                    $recordedPayments = (float) \Modules\Billing\Models\MerchantInvoicePayment::where('invoice_id', $invoice->id)
                        ->where('status', 'recorded')
                        ->sum('amount');

                    if ($recordedPayments >= $newTotal && $newTotal > 0) {
                        $invoice->status = 'paid';
                    } elseif ($recordedPayments > 0) {
                        $invoice->status = 'partially_paid';
                    } else {
                        $invoice->status = 'unpaid';
                    }

                    if (\Illuminate\Support\Facades\Schema::hasColumn('invoices', 'paid_amount')) {
                        $invoice->paid_amount = $recordedPayments;
                    }
                    if (\Illuminate\Support\Facades\Schema::hasColumn('invoices', 'outstanding_amount')) {
                        $invoice->outstanding_amount = max(0.0, round($newTotal - $recordedPayments, 2));
                    }
                    $invoice->save();

                    // Rebuild invoice items
                    \Modules\Billing\Models\InvoiceItem::where('invoice_id', $invoice->id)->delete();
                    if ($fareShare > 0) {
                        \Modules\Billing\Models\InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'description' => "Delivery charge {$shipment->tracking_number}",
                            'quantity' => 1,
                            'unit_price' => $fareShare,
                            'total' => $fareShare,
                        ]);
                    }
                    if ($podFee > 0) {
                        \Modules\Billing\Models\InvoiceItem::create([
                            'invoice_id' => $invoice->id,
                            'description' => "POD service fee {$shipment->tracking_number}",
                            'quantity' => 1,
                            'unit_price' => $podFee,
                            'total' => $podFee,
                        ]);
                    }

                    if (!empty($invoice->marketplace_bill_id)) {
                        $touchedMarketplaceBillIds[] = $invoice->marketplace_bill_id;
                    }

                    $invoiceSummary[] = "{$invoice->invoice_number} (NPR {$newTotal})";
                }

                // Recalculate Marketplace Bills
                foreach (array_unique($touchedMarketplaceBillIds) as $billId) {
                    $mbill = \Modules\Billing\Models\MarketplaceBill::find($billId);
                    if ($mbill) {
                        $sumTotal = round((float) $mbill->invoices()->sum('total_amount'), 2);
                        $mbill->total_amount = $sumTotal;
                        $recorded = (float) \Modules\Billing\Models\MarketplaceBillPayment::where('marketplace_bill_id', $mbill->id)
                            ->where('status', 'recorded')
                            ->sum('amount');
                        $mbill->paid_amount = $recorded;
                        $mbill->outstanding_amount = max(0.0, round($sumTotal - $recorded, 2));
                        if ($recorded >= $sumTotal && $sumTotal > 0) {
                            $mbill->status = 'paid';
                        } elseif ($recorded > 0) {
                            $mbill->status = 'partially_paid';
                        }
                        $mbill->save();
                    }
                }

                // 4. BRANCH SHARES & COMMISSION BILLS
                if (\Illuminate\Support\Facades\Schema::hasTable('shipment_branch_shares')) {
                    \Modules\Billing\Models\ShipmentBranchShare::where('shipment_id', $shipment->id)
                        ->whereNull('statement_id')
                        ->delete();

                    \Modules\Billing\Models\BranchCommissionBill::where('shipment_id', $shipment->id)
                        ->where('status', 'unpaid')
                        ->whereNull('settlement_id')
                        ->delete();

                    $origStatus = $shipment->status;
                    if (strtolower((string) $origStatus) !== 'delivered') {
                        $shipment->status = 'delivered';
                        $shipment->save();
                    }
                    $branchShares->ensureForShipment($shipment);
                    $commissionService->autoEnsureForShipment($shipment, true);
                    if ($origStatus !== 'delivered') {
                        $shipment->status = $origStatus;
                        $shipment->save();
                    }

                    // For any commission bill that had a settlement_id, update its amounts to match the new shares
                    $commissionBills = \Modules\Billing\Models\BranchCommissionBill::where('shipment_id', $shipment->id)->get();
                    foreach ($commissionBills as $cbill) {
                        $matchingShare = \Modules\Billing\Models\ShipmentBranchShare::where('shipment_id', $shipment->id)
                            ->where('branch_id', $cbill->branch_id)
                            ->first();
                        if ($matchingShare) {
                            $cbill->delivery_charge_base = round((float) $matchingShare->allocation_amount, 2);
                            $cbill->commission_rate = (float) $matchingShare->hq_rate;
                            $cbill->commission_amount = round((float) $matchingShare->hq_commission_amount, 2);
                            $cbill->save();
                        }
                    }

                    // Recalculate linked BranchCommissionSettlements
                    $settlementIds = $commissionBills->pluck('settlement_id')->filter()->unique();
                    foreach ($settlementIds as $sId) {
                        $bSettlement = \Modules\Billing\Models\BranchCommissionSettlement::find($sId);
                        if ($bSettlement) {
                            $totalCommission = round((float) $bSettlement->bills()->sum('commission_amount'), 2);
                            $bSettlement->total_commission = $totalCommission;
                            $bSettlement->final_payable_amount = round($totalCommission + (float) ($bSettlement->adjustments ?? 0), 2);
                            $bSettlement->save();
                        }
                    }
                }

                // 5. MERCHANT SETTLEMENT ITEMS
                $settlementItems = \Modules\Settlement\Models\MerchantSettlementItem::where('shipment_id', $shipment->id)->get();
                foreach ($settlementItems as $item) {
                    $podAmt = (float) ($shipment->pod_amount ?? $item->pod_amount ?? 0);
                    $itemFare = ($paidBy === 'customer') ? 0.00 : $targetFare;
                    $item->update([
                        'pod_amount' => $podAmt,
                        'delivery_charge' => $itemFare,
                        'net_amount' => $podAmt,
                    ]);
                    $settlement = \Modules\Settlement\Models\MerchantSettlement::find($item->merchant_settlement_id);
                    if ($settlement) {
                        $settlementWorkflow->recalcSettlementTotals($settlement);
                    }
                }

                return [
                    'invoices' => $invoiceSummary,
                    'shares' => \Modules\Billing\Models\ShipmentBranchShare::where('shipment_id', $shipment->id)->count(),
                    'bills' => \Modules\Billing\Models\BranchCommissionBill::where('shipment_id', $shipment->id)->count(),
                ];
            });

            $touchedShipments++;
            $invText = !empty($stats['invoices']) ? implode(', ', $stats['invoices']) : 'None';
            $this->info("  -> Sync Complete: Invoices: [{$invText}] | Branch Shares: {$stats['shares']} | Commission Bills: {$stats['bills']}");
        }

        $this->info(($dry ? '[DRY RUN COMPLETE] ' : '[SUCCESS] ') . "Synchronized {$touchedShipments} shipment(s).");
    }
)->purpose('Synchronize all downstream financial tables with shipment delivery charges');




Artisan::command('pickups:sync-run-status {--run= : Run number (e.g. RPR-01) or id} {--dry-run : Only list lagging pickups}', function () {
    $runs = app(\Modules\Pickup\Services\PickupRunService::class);
    $runOpt = $this->option('run');
    $runId = null;
    if ($runOpt) {
        $run = \Modules\Pickup\Models\PickupRun::query()->where('id', is_numeric($runOpt) ? (int) $runOpt : 0)
            ->orWhere('run_number', $runOpt)->first();
        if (! $run) {
            $this->error("Run {$runOpt} not found.");

            return 1;
        }
        $runId = (int) $run->id;
    }

    $lagging = \Illuminate\Support\Facades\DB::table('pickup_runs as r')
        ->join('pickup_run_items as i', 'i.pickup_run_id', '=', 'r.id')
        ->join('pickup_requests as p', 'p.id', '=', 'i.pickup_request_id')
        ->where('r.status', 'in_progress')->where('i.status', '!=', 'removed')
        ->whereIn('p.status', ['requested', 'pending', 'assigned', 'accepted'])
        ->when($runId, fn ($q) => $q->where('r.id', $runId))
        ->get(['r.run_number', 'p.request_number', 'p.status']);
    foreach ($lagging as $l) {
        $this->line("{$l->run_number}  {$l->request_number}  {$l->status}");
    }
    if ($lagging->isEmpty()) {
        $this->info('Nothing to sync: every pickup on in-progress runs is started or later.');

        return 0;
    }
    if ($this->option('dry-run')) {
        $this->info('[DRY RUN] '.$lagging->count().' pickup(s) would be accepted/started.');

        return 0;
    }

    foreach ($runs->syncLaggingRuns(null, $runId) as $number => $results) {
        foreach ($results as $r) {
            $this->line("{$number}  {$r['request_number']}  {$r['from']} -> {$r['to']}".($r['error'] ? "  ({$r['error']})" : ''));
        }
    }

    return 0;
})->purpose('Accept and start pickups that lag behind their in-progress pickup run');

/**
 * Reconcile inter-branch settlements, shares, and delivery charge invoices.
 *
 * Usage:
 *   php artisan billing:reconcile-inter-branch
 *   php artisan billing:reconcile-inter-branch --dry-run
 */
Artisan::command('billing:reconcile-inter-branch {--dry-run : Report changes without persisting}', function () {
    $dry = (bool) $this->option('dry-run');
    $this->info($dry ? '[DRY RUN] Simulating Inter-Branch Reconciliation...' : 'Reconciling Inter-Branch Settlements...');

    DB::transaction(function () use ($dry) {
        // 1. Mark merchant delivery charge invoices as paid
        $invIds = [9, 10, 11, 12, 14];
        $invoices = \Modules\Billing\Models\Invoice::whereIn('id', $invIds)
            ->where('type', 'delivery_charges')
            ->get();
        foreach ($invoices as $inv) {
            $this->line("Invoice {$inv->invoice_number} (#{$inv->id}): status {$inv->status} -> paid");
            if (! $dry) {
                $inv->status = 'paid';
                $inv->paid_at = $inv->paid_at ?? now();
                $inv->save();
            }
        }

        // 2. Shares
        $sharesData = [
            102 => ['collecting_branch_id' => 19, 'mode' => 'merchant_invoice', 'statement_id' => null, 'status' => 'settled'],
            103 => ['collecting_branch_id' => 19, 'mode' => 'merchant_invoice', 'statement_id' => 1, 'status' => 'on_statement'],
            109 => ['collecting_branch_id' => 19, 'mode' => 'merchant_invoice', 'statement_id' => null, 'status' => 'settled'],
            110 => ['collecting_branch_id' => 19, 'mode' => 'merchant_invoice', 'statement_id' => 3, 'status' => 'on_statement'],
            111 => ['collecting_branch_id' => 19, 'mode' => 'merchant_invoice', 'statement_id' => 2, 'status' => 'on_statement'],
            114 => ['collecting_branch_id' => 24, 'mode' => 'merchant_invoice', 'statement_id' => null, 'status' => 'settled'],
            115 => ['collecting_branch_id' => 24, 'mode' => 'merchant_invoice', 'statement_id' => 4, 'status' => 'on_statement'],
            118 => ['collecting_branch_id' => 24, 'mode' => 'merchant_invoice', 'statement_id' => null, 'status' => 'settled'],
            119 => ['collecting_branch_id' => 24, 'mode' => 'merchant_invoice', 'statement_id' => 4, 'status' => 'on_statement'],
            122 => ['collecting_branch_id' => 24, 'mode' => 'merchant_invoice', 'statement_id' => null, 'status' => 'settled'],
            123 => ['collecting_branch_id' => 24, 'mode' => 'merchant_invoice', 'statement_id' => 4, 'status' => 'on_statement'],
            136 => ['collecting_branch_id' => 19, 'mode' => 'merchant_invoice', 'statement_id' => null, 'status' => 'settled'],
            137 => ['collecting_branch_id' => 19, 'mode' => 'merchant_invoice', 'statement_id' => 5, 'status' => 'on_statement'],
        ];

        foreach ($sharesData as $id => $d) {
            $sh = \Modules\Billing\Models\ShipmentBranchShare::find($id);
            if ($sh) {
                $stmtStr = $d['statement_id'] ?? 'null';
                $this->line("Share #{$id} (Shipment {$sh->shipment_id}): collector {$sh->collecting_branch_id} -> {$d['collecting_branch_id']} | status {$sh->status} -> {$d['status']} | stmt {$sh->statement_id} -> {$stmtStr}");
                if (! $dry) {
                    $sh->collecting_branch_id = $d['collecting_branch_id'];
                    $sh->collection_mode = $d['mode'];
                    $sh->statement_id = $d['statement_id'];
                    $sh->status = $d['status'];
                    $sh->save();
                }
            }
        }

        // 3. Statements
        $stmtsData = [
            1 => ['from' => 19, 'to' => 20, 'amount' => 239.39, 'lines' => 1],
            2 => ['from' => 19, 'to' => 21, 'amount' => 63.40, 'lines' => 1],
            3 => ['from' => 19, 'to' => 26, 'amount' => 33.80, 'lines' => 1],
            4 => ['from' => 24, 'to' => 19, 'amount' => 134.34, 'lines' => 3],
            5 => ['from' => 19, 'to' => 18, 'amount' => 88.00, 'lines' => 1],
        ];

        foreach ($stmtsData as $id => $d) {
            $stmt = \Modules\Billing\Models\InterBranchStatement::find($id);
            if ($stmt) {
                $this->line("Statement {$stmt->statement_number} (#{$id}): from {$stmt->from_branch_id} -> {$d['from']}, to {$stmt->to_branch_id} -> {$d['to']}, amount {$stmt->total_amount} -> {$d['amount']}");
                if (! $dry) {
                    $stmt->from_branch_id = $d['from'];
                    $stmt->to_branch_id = $d['to'];
                    $stmt->total_amount = $d['amount'];
                    $stmt->line_count = $d['lines'];
                    $stmt->save();
                }
            }
        }

        // 4. Statement Lines
        $lines = [
            ['id' => 1, 'statement_id' => 1, 'shipment_branch_share_id' => 103, 'shipment_id' => 231, 'role' => 'delivery', 'share_amount' => 4.49, 'transport_amount' => 0.00, 'extra_distance_amount' => 234.90, 'amount' => 239.39],
            ['id' => 2, 'statement_id' => 2, 'shipment_branch_share_id' => 111, 'shipment_id' => 233, 'role' => 'delivery', 'share_amount' => 59.14, 'transport_amount' => 0.00, 'extra_distance_amount' => 4.26, 'amount' => 63.40],
            ['id' => 3, 'statement_id' => 3, 'shipment_branch_share_id' => 110, 'shipment_id' => 233, 'role' => 'transit', 'share_amount' => 33.80, 'transport_amount' => 0.00, 'extra_distance_amount' => 0.00, 'amount' => 33.80],
            ['id' => 4, 'statement_id' => 4, 'shipment_branch_share_id' => 115, 'shipment_id' => 234, 'role' => 'delivery', 'share_amount' => 28.15, 'transport_amount' => 0.00, 'extra_distance_amount' => 7.92, 'amount' => 36.07],
            ['id' => 5, 'statement_id' => 4, 'shipment_branch_share_id' => 119, 'shipment_id' => 235, 'role' => 'delivery', 'share_amount' => 28.15, 'transport_amount' => 0.00, 'extra_distance_amount' => 7.92, 'amount' => 36.07],
            ['id' => 6, 'statement_id' => 4, 'shipment_branch_share_id' => 123, 'shipment_id' => 242, 'role' => 'delivery', 'share_amount' => 42.40, 'transport_amount' => 0.00, 'extra_distance_amount' => 19.80, 'amount' => 62.20],
            ['id' => 7, 'statement_id' => 5, 'shipment_branch_share_id' => 137, 'shipment_id' => 253, 'role' => 'delivery', 'share_amount' => 88.00, 'transport_amount' => 0.00, 'extra_distance_amount' => 0.00, 'amount' => 88.00],
        ];

        if (! $dry) {
            \Modules\Billing\Models\InterBranchStatementLine::whereIn('statement_id', [1, 2, 3, 4, 5])->delete();
            foreach ($lines as $l) {
                \Modules\Billing\Models\InterBranchStatementLine::create($l);
            }
        }

        // 5. Reconcile any delivered shipments (e.g. TEX-20261107-782642) where collecting_branch_id was inverted to destination
        $invertedShares = \Modules\Billing\Models\ShipmentBranchShare::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_branch_shares.shipment_id')
            ->whereNull('shipment_branch_shares.statement_id')
            ->whereColumn('shipment_branch_shares.collecting_branch_id', '!=', 'shipments.origin_branch_id')
            ->select('shipment_branch_shares.*', 'shipments.origin_branch_id', 'shipments.tracking_number')
            ->get();

        foreach ($invertedShares as $sh) {
            $isCollector = (int) $sh->branch_id === (int) $sh->origin_branch_id;
            $newStatus = $isCollector ? 'settled' : 'pending';
            $this->line("Reconciling Share #{$sh->id} ({$sh->tracking_number}): collector {$sh->collecting_branch_id} -> {$sh->origin_branch_id} | status {$sh->status} -> {$newStatus}");
            if (! $dry) {
                $sh->collecting_branch_id = $sh->origin_branch_id;
                $sh->collection_mode = 'merchant_invoice';
                $sh->status = $newStatus;
                $sh->save();
            }
        }
    });

    $this->info($dry ? '[DRY RUN] Complete.' : '[SUCCESS] Reconciled inter-branch settlements, shares, and invoices.');
})->purpose('Reconcile inter-branch statements, shares, and merchant delivery charge bills');

/**
 * Force recompute branch shares, HQ commissions, and collector direction for a shipment.
 *
 * Usage:
 *   php artisan billing:recompute-shipment TEX-20261107-782642
 */
Artisan::command('billing:recompute-shipment {tracking : Tracking number or Shipment ID}', function () {
    $tracking = (string) $this->argument('tracking');
    $shipment = \Modules\Shipment\Models\Shipment::where('tracking_number', $tracking)
        ->orWhere('id', is_numeric($tracking) ? (int) $tracking : 0)
        ->first();

    if (! $shipment) {
        $this->error("Shipment {$tracking} not found.");
        return 1;
    }

    $this->info("Recomputing shares for Shipment {$shipment->tracking_number} (#{$shipment->id})...");

    DB::transaction(function () use ($shipment) {
        \Modules\Billing\Models\ShipmentBranchShare::where('shipment_id', $shipment->id)
            ->whereNull('statement_id')
            ->delete();

        app(\Modules\Billing\Services\BranchCommissionService::class)->autoEnsureForShipment($shipment);
    });

    $shares = \Modules\Billing\Models\ShipmentBranchShare::where('shipment_id', $shipment->id)
        ->orderBy('position')
        ->get();

    foreach ($shares as $s) {
        $this->line("Branch #{$s->branch_id} ({$s->role}): allocation Rs {$s->allocation_amount}, HQ comm Rs {$s->hq_commission_amount}, net Rs {$s->net_amount}, collector: #{$s->collecting_branch_id}, status: {$s->status}");
    }

    $this->info("Recomputed successfully.");
    return 0;
})->purpose('Force recompute branch shares and HQ commissions for a shipment');


/**
 * Universal reconciliation for ALL shipments, shares, invoices, and statements.
 *
 * Usage:
 *   php artisan billing:reconcile-all
 *   php artisan billing:reconcile-all --dry-run
 */
Artisan::command('billing:reconcile-all {--dry-run : Report changes without persisting}', function () {
    $dry = (bool) $this->option('dry-run');
    $this->info($dry ? '[DRY RUN] Simulating Universal Reconciliation...' : 'Reconciling ALL Shipments, Shares, Invoices, and Statements...');

    $stats = DB::transaction(function () use ($dry) {
        // 1. Update shipments payer
        $shipmentsQuery = \Modules\Shipment\Models\Shipment::query()
            ->where(function ($q) {
                $q->where('delivery_charge_paid_by', 'customer')
                  ->orWhereNull('delivery_charge_paid_by');
            });
        $shipmentCount = $shipmentsQuery->count();
        if (! $dry && $shipmentCount > 0) {
            $shipmentsQuery->update(['delivery_charge_paid_by' => 'merchant']);
        }
        $this->line("1. Shipments updated to merchant payer: {$shipmentCount}");

        // 2. Mark merchant delivery charge invoices as paid
        $invQuery = \Modules\Billing\Models\Invoice::query()
            ->where('type', 'delivery_charges')
            ->where('status', 'unpaid');
        $invCount = $invQuery->count();
        if (! $dry && $invCount > 0) {
            $invQuery->update(['status' => 'paid', 'paid_at' => now()]);
        }
        $this->line("2. Merchant delivery charge invoices marked paid: {$invCount}");

        // 3. Update collecting_branch_id and collection_mode on ALL shipment_branch_shares
        $shares = \Modules\Billing\Models\ShipmentBranchShare::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_branch_shares.shipment_id')
            ->select('shipment_branch_shares.*', 'shipments.origin_branch_id', 'shipments.delivery_free_by')
            ->get();

        $sharesUpdated = 0;
        foreach ($shares as $s) {
            $originId = (int) $s->origin_branch_id;
            $mode = ($s->delivery_free_by === 'marketplace') ? 'company_invoice' : 'merchant_invoice';
            $isCollector = (int) $s->branch_id === $originId;
            $newStatus = $isCollector ? 'settled' : ($s->statement_id ? 'on_statement' : 'pending');

            $needsUpdate = (int) $s->collecting_branch_id !== $originId 
                || $s->collection_mode !== $mode 
                || ($isCollector && $s->status !== 'settled')
                || (! $isCollector && ! $s->statement_id && $s->status === 'settled');

            if ($needsUpdate) {
                $sharesUpdated++;
                if (! $dry) {
                    $s->collecting_branch_id = $originId;
                    $s->collection_mode = $mode;
                    $s->status = $newStatus;
                    if ($isCollector) {
                        $s->statement_id = null;
                    }
                    $s->save();
                }
            }
        }
        $this->line("3. Branch shares updated with origin collector: {$sharesUpdated}");

        // 4. Historical statements reconciliation (1 to 5)
        $stmtsData = [
            1 => ['from' => 19, 'to' => 20, 'amount' => 239.39, 'lines' => 1],
            2 => ['from' => 19, 'to' => 21, 'amount' => 63.40, 'lines' => 1],
            3 => ['from' => 19, 'to' => 26, 'amount' => 33.80, 'lines' => 1],
            4 => ['from' => 24, 'to' => 19, 'amount' => 134.34, 'lines' => 3],
            5 => ['from' => 19, 'to' => 18, 'amount' => 88.00, 'lines' => 1],
        ];

        foreach ($stmtsData as $id => $d) {
            $stmt = \Modules\Billing\Models\InterBranchStatement::find($id);
            if ($stmt) {
                if (! $dry) {
                    $stmt->from_branch_id = $d['from'];
                    $stmt->to_branch_id = $d['to'];
                    $stmt->total_amount = $d['amount'];
                    $stmt->line_count = $d['lines'];
                    $stmt->save();
                }
            }
        }

        $lines = [
            ['id' => 1, 'statement_id' => 1, 'shipment_branch_share_id' => 103, 'shipment_id' => 231, 'role' => 'delivery', 'share_amount' => 4.49, 'transport_amount' => 0.00, 'extra_distance_amount' => 234.90, 'amount' => 239.39],
            ['id' => 2, 'statement_id' => 2, 'shipment_branch_share_id' => 111, 'shipment_id' => 233, 'role' => 'delivery', 'share_amount' => 59.14, 'transport_amount' => 0.00, 'extra_distance_amount' => 4.26, 'amount' => 63.40],
            ['id' => 3, 'statement_id' => 3, 'shipment_branch_share_id' => 110, 'shipment_id' => 233, 'role' => 'transit', 'share_amount' => 33.80, 'transport_amount' => 0.00, 'extra_distance_amount' => 0.00, 'amount' => 33.80],
            ['id' => 4, 'statement_id' => 4, 'shipment_branch_share_id' => 115, 'shipment_id' => 234, 'role' => 'delivery', 'share_amount' => 28.15, 'transport_amount' => 0.00, 'extra_distance_amount' => 7.92, 'amount' => 36.07],
            ['id' => 5, 'statement_id' => 4, 'shipment_branch_share_id' => 119, 'shipment_id' => 235, 'role' => 'delivery', 'share_amount' => 28.15, 'transport_amount' => 0.00, 'extra_distance_amount' => 7.92, 'amount' => 36.07],
            ['id' => 6, 'statement_id' => 4, 'shipment_branch_share_id' => 123, 'shipment_id' => 242, 'role' => 'delivery', 'share_amount' => 42.40, 'transport_amount' => 0.00, 'extra_distance_amount' => 19.80, 'amount' => 62.20],
            ['id' => 7, 'statement_id' => 5, 'shipment_branch_share_id' => 137, 'shipment_id' => 253, 'role' => 'delivery', 'share_amount' => 88.00, 'transport_amount' => 0.00, 'extra_distance_amount' => 0.00, 'amount' => 88.00],
        ];

        if (! $dry) {
            \Modules\Billing\Models\InterBranchStatementLine::whereIn('statement_id', [1, 2, 3, 4, 5])->delete();
            foreach ($lines as $l) {
                \Modules\Billing\Models\InterBranchStatementLine::create($l);
            }
        }
        $this->line("4. Statements 1-5 and lines reconciled.");

        // 5. Ensure shares for any delivered shipments that lack shares
        $deliveredWithoutShares = \Modules\Shipment\Models\Shipment::query()
            ->where('status', 'delivered')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                  ->from('shipment_branch_shares')
                  ->whereColumn('shipment_branch_shares.shipment_id', 'shipments.id');
            })
            ->get();
        $generated = 0;
        if (! $dry) {
            foreach ($deliveredWithoutShares as $delShip) {
                app(\Modules\Billing\Services\BranchCommissionService::class)->autoEnsureForShipment($delShip);
                $generated++;
            }
        }
        $this->line("5. Generated missing shares for delivered shipments: " . ($dry ? $deliveredWithoutShares->count() : $generated));

        return [
            'shipments' => $shipmentCount,
            'invoices' => $invCount,
            'shares' => $sharesUpdated,
        ];
    });

    $this->info($dry ? '[DRY RUN] Complete.' : '[SUCCESS] Universal reconciliation completed successfully for all shipments.');
})->purpose('Universal reconciliation for all shipments, branch shares, invoices, and statements');
