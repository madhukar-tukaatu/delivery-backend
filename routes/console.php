<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
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

        $priced = $resolver->resolveFromShipment($shipment);
        $deliveryFee = 0.0;
        $payer = strtolower(trim((string) ($shipment->delivery_charge_paid_by ?? 'customer')));
        if (in_array($payer, ['merchant', 'store', 'seller', 'free', 'free_delivery'], true)) {
            $deliveryFee = round((float) $priced['delivery_charge'], 2);
        }

        $podFee = round((float) ($shipment->pod_charge ?? $priced['pod_charge'] ?? 0), 2);
        $subtotal = round($deliveryFee + $podFee, 2);

        $before = round((float) $invoice->total_amount, 2);
        $oldShipmentCharge = round((float) $shipment->delivery_charge, 2);

        $this->line(sprintf(
            '%s shipment=%s payer=%s source=%s shipment_charge %.2f -> %.2f | bill %.2f -> %.2f',
            $invoice->invoice_number,
            $shipment->tracking_number,
            $payer,
            $priced['source'] ?? '?',
            $oldShipmentCharge,
            $deliveryFee,
            $before,
            $subtotal
        ));

        if ($subtotal <= 0) {
            $this->warn('  -> computed amount is 0; leave invoice unchanged');
            $skipped++;
            continue;
        }

        if (abs($before - $subtotal) < 0.009 && abs($oldShipmentCharge - $deliveryFee) < 0.009) {
            $skipped++;
            continue;
        }

        if ($dry) {
            $updated++;
            continue;
        }

        DB::transaction(function () use ($invoice, $shipment, $deliveryFee, $podFee, $subtotal, $priced) {
            $shipment->delivery_charge = $deliveryFee;
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
                    'description' => 'Delivery charge (checkout) '.$shipment->tracking_number,
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
