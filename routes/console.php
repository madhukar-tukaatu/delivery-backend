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

            \Illuminate\Support\Facades\DB::transaction(function () use (
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

                $paidBy = strtolower((string) ($shipment->delivery_charge_paid_by ?? 'customer'));
                $podAmount = (float) ($shipment->pod_amount ?? 0);
                if ($paidBy === 'customer') {
                    $shipment->total_collectable_amount = round($podAmount + $newFare, 2);
                } else {
                    $shipment->total_collectable_amount = round($podAmount, 2);
                }
                $shipment->save();

                // 2. SHIPMENT PRICE BREAKDOWNS TABLE
                if (\Illuminate\Support\Facades\Schema::hasTable('shipment_price_breakdowns')) {
                    \Illuminate\Support\Facades\DB::table('shipment_price_breakdowns')
                        ->where('shipment_id', $shipment->id)
                        ->update([
                            'final_price' => $newFare,
                            'weight_charge' => 0.00,
                            'snapshot_json' => json_encode($breakdown),
                            'updated_at' => now(),
                        ]);
                }

                // 3. INVOICES, ITEMS, PAYMENTS & MARKETPLACE BILLS
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
                        $fareShare = (float) $split['marketplace_share'];
                        $podFee = 0.0;
                    } else {
                        $fareShare = (float) $split['store_share'];
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
                    if ($mbill && $mbill->isOpen()) {
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

                // 4. SHIPMENT BRANCH SHARES, HQ COMMISSION BILLS, & COMMISSION SETTLEMENTS
                if (\Illuminate\Support\Facades\Schema::hasTable('shipment_branch_shares')) {
                    $hasSettledShares = \Modules\Billing\Models\ShipmentBranchShare::where('shipment_id', $shipment->id)
                        ->where('status', 'settled')
                        ->exists();

                    if (!$hasSettledShares) {
                        \Modules\Billing\Models\ShipmentBranchShare::where('shipment_id', $shipment->id)->delete();
                        \Modules\Billing\Models\BranchCommissionBill::where('shipment_id', $shipment->id)
                            ->where('status', 'unpaid')
                            ->whereNull('settlement_id')
                            ->delete();

                        if (strtolower((string) $shipment->status) === 'delivered') {
                            $branchShares->ensureForShipment($shipment);
                            $commissionService->autoEnsureForShipment($shipment, false);
                        }
                    }

                    // Sync any open BranchCommissionSettlement linked to bills for this shipment
                    $commissionBills = \Modules\Billing\Models\BranchCommissionBill::where('shipment_id', $shipment->id)->get();
                    $settlementIds = $commissionBills->pluck('settlement_id')->filter()->unique();
                    foreach ($settlementIds as $sId) {
                        $bSettlement = \Modules\Billing\Models\BranchCommissionSettlement::find($sId);
                        if ($bSettlement && ($bSettlement->status === 'pending' || !$bSettlement->paid_at)) {
                            $totalCommission = round((float) $bSettlement->bills()->sum('commission_amount'), 2);
                            $bSettlement->total_commission = $totalCommission;
                            $bSettlement->final_payable_amount = round($totalCommission + (float) ($bSettlement->adjustments ?? 0), 2);
                            $bSettlement->save();
                        }
                    }
                }

                // 5. MERCHANT SETTLEMENTS & SETTLEMENT ITEMS
                $settlementItems = \Modules\Settlement\Models\MerchantSettlementItem::where('shipment_id', $shipment->id)->get();
                foreach ($settlementItems as $item) {
                    $line = $settlementWorkflow->lineNetAuto($shipment);
                    $item->update([
                        'pod_amount' => $line['pod_amount'],
                        'delivery_charge' => $line['delivery_charge'],
                        'pod_charge' => $line['pod_charge'],
                        'net_amount' => $line['net_amount'],
                    ]);

                    $settlement = \Modules\Settlement\Models\MerchantSettlement::find($item->merchant_settlement_id);
                    if ($settlement && $settlement->status === 'pending') {
                        $settlementWorkflow->recalcSettlementTotals($settlement);
                    }
                }

                // 6. DELIVERY ASSIGNMENTS
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
            });

            $touchedShipments++;
            $this->info("  -> Synchronized successfully.");
        }

        $this->info(($dry ? '[DRY RUN COMPLETE] ' : '[SUCCESS] ') . "Processed {$touchedShipments} shipment(s).");
    }
)->purpose('Reconcile shipments to target weight and sync all financial tables');

