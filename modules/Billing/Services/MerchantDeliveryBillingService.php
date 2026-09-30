<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Merchant\Models\Merchant;
use Modules\Notification\Models\EmailLog;
use Modules\Notification\Models\NotificationLog;
use Modules\Settlement\Services\SettlementWorkflowService;
use Modules\Shipment\Models\Shipment;
use Throwable;

/**
 * Merchant delivery-fee bills (branch receives). Independent of POD settlements
 * and independent of HQ commission bills (branch pays company).
 */
class MerchantDeliveryBillingService
{
    public function __construct(private SettlementWorkflowService $settlements)
    {
    }

    public function autoEnsureForShipment(Shipment $shipment): ?Invoice
    {
        $shipment->refresh();

        if (strtolower((string) $shipment->status) !== 'delivered') {
            return null;
        }

        $deliveryFee = 0.0;
        $payer = strtolower(trim((string) ($shipment->delivery_charge_paid_by ?? 'customer')));
        if (in_array($payer, ['merchant', 'store', 'seller', 'free', 'free_delivery'], true)) {
            $deliveryFee = $this->settlements->checkoutDeliveryCharge($shipment);
        }

        $podFee = (float) ($shipment->pod_charge ?? 0);

        if ($deliveryFee <= 0 && $podFee <= 0) {
            return null;
        }

        $branchId = $shipment->destination_branch_id
            ?? $shipment->current_branch_id
            ?? $shipment->origin_branch_id
            ?? null;

        return DB::transaction(function () use ($shipment, $deliveryFee, $podFee, $branchId) {
            $existing = Invoice::query()
                ->where('shipment_id', $shipment->id)
                ->where('type', 'delivery_charges')
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->load('items');
            }

            $subtotal = round($deliveryFee + $podFee, 2);

            $payload = [
                'merchant_id' => $shipment->merchant_id,
                'shipment_id' => $shipment->id,
                'invoice_number' => 'INV-DLV-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'type' => 'delivery_charges',
                'invoice_date' => now()->toDateString(),
                'subtotal' => $subtotal,
                'tax_amount' => 0,
                'total_amount' => $subtotal,
                'status' => 'unpaid',
            ];

            if (\Illuminate\Support\Facades\Schema::hasColumn('invoices', 'branch_id')) {
                $payload['branch_id'] = $branchId;
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('invoices', 'payer_type')) {
                $payload['payer_type'] = 'merchant';
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('invoices', 'payee_type')) {
                $payload['payee_type'] = 'branch';
            }

            $invoice = Invoice::create($payload);

            if ($deliveryFee > 0) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => 'Delivery charge (checkout) '.$shipment->tracking_number,
                    'quantity' => 1,
                    'unit_price' => round($deliveryFee, 2),
                    'total' => round($deliveryFee, 2),
                ]);
            }

            if ($podFee > 0) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => 'POD service fee '.$shipment->tracking_number,
                    'quantity' => 1,
                    'unit_price' => round($podFee, 2),
                    'total' => round($podFee, 2),
                ]);
            }

            $invoice = $invoice->load('items');
            // Queue for periodic digest instead of immediate email
            $this->queueForDigest($invoice, $shipment);

            return $invoice;
        });
    }

    /**
     * Queue invoice for periodic digest email (daily/weekly)
     */
    protected function queueForDigest(Invoice $invoice, Shipment $shipment): void
    {
        $merchant = Merchant::query()->find($shipment->merchant_id);
        $email = $merchant?->email ?? $merchant?->contact_email ?? null;

        if (! $email) {
            return;
        }

        try {
            if (class_exists(NotificationLog::class)) {
                NotificationLog::create([
                    'merchant_id' => $shipment->merchant_id,
                    'type' => 'delivery_bill_queued',
                    'channel' => 'system',
                    'status' => 'queued',
                    'payload' => [
                        'title' => 'Delivery charge bill queued for digest',
                        'message' => "Invoice {$invoice->invoice_number} for shipment {$shipment->tracking_number} queued for periodic digest",
                        'invoice_id' => $invoice->id,
                        'shipment_id' => $shipment->id,
                    ],
                    'sent_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            Log::info('delivery_bill.notification_log_skipped', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send immediate email for a specific invoice (manual trigger)
     */
    public function sendInvoiceEmail(Invoice $invoice): bool
    {
        $shipment = $invoice->shipment;
        if (! $shipment) {
            return false;
        }

        $merchant = Merchant::query()->find($shipment->merchant_id);
        $email = $merchant?->email ?? $merchant?->contact_email ?? null;

        if (! $email) {
            return false;
        }

        $subject = 'Delivery charge bill '.$invoice->invoice_number;
        $body = $this->buildInvoiceEmailBody($invoice, $shipment);

        try {
            Mail::raw($body, function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });
            if (class_exists(EmailLog::class)) {
                EmailLog::create([
                    'to_email' => $email,
                    'subject' => $subject,
                    'body' => $body,
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
            }
            return true;
        } catch (Throwable $e) {
            Log::warning('delivery_bill.email_failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Build email body for single invoice
     */
    protected function buildInvoiceEmailBody(Invoice $invoice, Shipment $shipment): string
    {
        return sprintf(
            "Delivery charge bill for shipment %s.\nInvoice: %s\nAmount due to branch: Rs. %s\n(This is separate from POD cash and from HQ commission.)",
            $shipment->tracking_number,
            $invoice->invoice_number,
            number_format((float) $invoice->total_amount, 2),
        );
    }

    /**
     * Build digest email body for multiple invoices
     */
    public function buildDigestEmailBody(array $invoices): string
    {
        $total = 0;
        $lines = ["Dear Merchant,\n\nHere is your delivery charges summary:\n"];

        foreach ($invoices as $inv) {
            $shipment = $inv->shipment;
            $amount = (float) $inv->total_amount;
            $total += $amount;
            $tracking = $shipment?->tracking_number ?? 'N/A';
            $lines[] = "- {$inv->invoice_number} | Shipment: {$tracking} | Amount: Rs. " . number_format($amount, 2);
        }

        $lines[] = "\nTotal Amount Due: Rs. " . number_format($total, 2);
        $lines[] = "\n(This is separate from POD cash and from HQ commission.)";
        $lines[] = "\nPlease log in to your merchant portal to view and pay invoices.";

        return implode("\n", $lines);
    }

    /**
     * Get unpaid invoices for a merchant within date range
     */
    public function getUnpaidInvoicesForMerchant(int $merchantId, ?string $fromDate = null, ?string $toDate = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = Invoice::with('shipment')
            ->where('merchant_id', $merchantId)
            ->where('type', 'delivery_charges')
            ->where('status', 'unpaid')
            ->orderBy('invoice_date', 'desc');

        if ($fromDate) {
            $query->whereDate('invoice_date', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('invoice_date', '<=', $toDate);
        }

        return $query->get();
    }
}
