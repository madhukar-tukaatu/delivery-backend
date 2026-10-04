<?php



namespace Modules\Billing\Services;



use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Mail;

use Illuminate\Support\Facades\Schema;

use Modules\Billing\Models\Invoice;

use Modules\Billing\Models\InvoiceItem;

use Modules\Merchant\Models\Merchant;

use Modules\Setting\Models\Marketplace;

use Modules\Notification\Models\EmailLog;

use Modules\Notification\Models\NotificationLog;

use Modules\Settlement\Services\SettlementWorkflowService;

use Modules\Shipment\Models\Shipment;

use Throwable;



/**

 * Merchant delivery-fee bills (branch receives). Independent of POD settlements

 * and independent of HQ commission bills (branch pays company).

 *

 * Store free delivery stays on the merchant invoice. Marketplace free delivery

 * is a second delivery_charges invoice with payer_type=company.

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



        $split = $this->settlements->deliveryChargeSplit($shipment);

        $storeShare = (float) $split['store_share'];

        $marketplaceShare = (float) $split['marketplace_share'];

        $podFee = round((float) ($shipment->pod_charge ?? 0), 2);

        $freeBy = (string) ($split['free_by'] ?? 'none');



        if ($storeShare <= 0 && $podFee <= 0 && $marketplaceShare <= 0) {

            return null;

        }



        $branchId = $shipment->destination_branch_id

            ?? $shipment->current_branch_id

            ?? $shipment->origin_branch_id

            ?? null;



        return DB::transaction(function () use ($shipment, $storeShare, $marketplaceShare, $podFee, $branchId, $freeBy) {

            $merchantInvoice = null;



            if ($storeShare > 0 || $podFee > 0) {

                $merchantInvoice = $this->findDeliveryInvoice($shipment->id, 'merchant', true);



                if (! $merchantInvoice) {

                    $subtotal = round($storeShare + $podFee, 2);

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



                    if (Schema::hasColumn('invoices', 'branch_id')) {

                        $payload['branch_id'] = $branchId;

                    }

                    if (Schema::hasColumn('invoices', 'payer_type')) {

                        $payload['payer_type'] = 'merchant';

                    }

                    if (Schema::hasColumn('invoices', 'payee_type')) {

                        $payload['payee_type'] = 'branch';

                    }



                    $merchantInvoice = Invoice::create($payload);



                    if ($storeShare > 0) {

                        InvoiceItem::create([

                            'invoice_id' => $merchantInvoice->id,

                            'description' => $this->deliveryLineDescription($freeBy, 'store', (string) $shipment->tracking_number),

                            'quantity' => 1,

                            'unit_price' => round($storeShare, 2),

                            'total' => round($storeShare, 2),

                        ]);

                    }



                    if ($podFee > 0) {

                        InvoiceItem::create([

                            'invoice_id' => $merchantInvoice->id,

                            'description' => 'POD service fee '.$shipment->tracking_number,

                            'quantity' => 1,

                            'unit_price' => round($podFee, 2),

                            'total' => round($podFee, 2),

                        ]);

                    }



                    $merchantInvoice = $merchantInvoice->load('items');

                    $this->queueForDigest($merchantInvoice, $shipment);

                }

            }



            if ($marketplaceShare > 0) {

                $companyInvoice = $this->findDeliveryInvoice($shipment->id, 'company', true);



                if (! $companyInvoice) {

                    $email = $this->companyBillingEmail($shipment);

                    if (! $email) {

                        Log::info('delivery_bill.company_email_skipped', [

                            'shipment_id' => $shipment->id,

                            'reason' => 'Marketplace billing email and BILLING_MARKETPLACE_EMAIL are empty; company invoice created without email',

                        ]);

                    }



                    $amount = round($marketplaceShare, 2);

                    $payload = [

                        'merchant_id' => null,

                        'shipment_id' => $shipment->id,

                        'invoice_number' => 'INV-MKT-'.now()->format('YmdHis').'-'.random_int(100, 999),

                        'type' => 'delivery_charges',

                        'invoice_date' => now()->toDateString(),

                        'subtotal' => $amount,

                        'tax_amount' => 0,

                        'total_amount' => $amount,

                        'status' => 'unpaid',

                    ];



                    if (Schema::hasColumn('invoices', 'branch_id')) {

                        $payload['branch_id'] = $branchId;

                    }

                    if (Schema::hasColumn('invoices', 'payer_type')) {

                        $payload['payer_type'] = 'company';

                    }

                    if (Schema::hasColumn('invoices', 'payee_type')) {

                        $payload['payee_type'] = 'branch';

                    }

                    if (Schema::hasColumn('invoices', 'billed_to_email')) {

                        $payload['billed_to_email'] = $email ?: null;

                    }



                    $companyInvoice = Invoice::create($payload);



                    InvoiceItem::create([

                        'invoice_id' => $companyInvoice->id,

                        'description' => $this->deliveryLineDescription($freeBy, 'marketplace', (string) $shipment->tracking_number),

                        'quantity' => 1,

                        'unit_price' => $amount,

                        'total' => $amount,

                    ]);

                }

            }



            return $merchantInvoice?->load('items') ?? $this->findDeliveryInvoice($shipment->id, 'company')?->load('items');

        });

    }



    protected function findDeliveryInvoice(int $shipmentId, string $payerType, bool $lock = false): ?Invoice

    {

        $query = Invoice::query()

            ->where('shipment_id', $shipmentId)

            ->where('type', 'delivery_charges');



        if (Schema::hasColumn('invoices', 'payer_type')) {

            if ($payerType === 'company') {

                $query->where('payer_type', 'company');

            } else {

                $query->where(function ($inner) {

                    $inner->where('payer_type', 'merchant')->orWhereNull('payer_type');

                });

            }

        } elseif ($payerType === 'company') {

            return null;

        }



        if ($lock) {

            $query->lockForUpdate();

        }



        return $query->first();

    }



    public function deliveryLineDescription(string $freeBy, string $side, string $tracking): string

    {

        if ($side === 'marketplace' || $freeBy === 'marketplace') {

            return "Free delivery (marketplace) {$tracking}";

        }



        if ($freeBy === 'store') {

            return "Free delivery (store) {$tracking}";

        }



        return "Delivery charge {$tracking}";

    }



    /**

     * Queue merchant invoice for periodic digest email (daily/weekly).

     * Company invoices are not queued to the merchant.

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

     * Marketplace free-delivery bills go to that marketplace's email

     * (tukaatu.com, FCA, or any other). BILLING_MARKETPLACE_EMAIL is only

     * the fallback when the marketplace email is empty.

     */

    public function companyBillingEmail(?Shipment $shipment): ?string

    {

        if ($shipment) {

            $shipment->loadMissing('merchant');

        }



        $marketplace = Marketplace::resolveForShipment($shipment, $shipment?->merchant);

        $email = trim((string) ($marketplace?->email ?? ''));

        if ($email !== '') {

            return $email;

        }



        $fallback = trim((string) config('billing.marketplace_email'));



        return $fallback !== '' ? $fallback : null;

    }



    /**

     * Store free-delivery bills email the store. Marketplace free-delivery

     * bills email the shipment marketplace.

     */

    public function invoiceRecipient(Invoice $invoice): ?string

    {

        $shipment = $invoice->shipment;

        if (! $shipment && $invoice->shipment_id) {

            $shipment = Shipment::query()->find($invoice->shipment_id);

        }



        $payer = strtolower((string) ($invoice->payer_type ?? 'merchant'));

        if ($payer === 'company') {

            return $this->companyBillingEmail($shipment);

        }



        if (! $shipment) {

            return null;

        }



        $merchant = Merchant::query()->find($shipment->merchant_id);

        $email = trim((string) ($merchant?->email ?? $merchant?->contact_email ?? ''));



        return $email !== '' ? $email : null;

    }





    /**

     * Send immediate email for a specific invoice (manual trigger).

     */

    public function invoiceIsSendable(Invoice $invoice): bool

    {

        return strtolower(trim((string) $invoice->status)) === 'unpaid';

    }





    public function stampInvoicesSent(iterable $invoices): void

    {

        if (! \Illuminate\Support\Facades\Schema::hasColumn('invoices', 'sent_at')) {

            return;

        }



        foreach ($invoices as $invoice) {

            if (! $invoice instanceof Invoice) {

                continue;

            }

            if ($invoice->sent_at) {

                continue;

            }

            $invoice->forceFill(['sent_at' => now()])->save();

        }

    }



    public function isDueForCheck(Invoice $invoice): bool

    {

        if (! $this->invoiceIsSendable($invoice) || $invoice->manual_checked_at || ! $invoice->sent_at) {

            return false;

        }



        return $invoice->sent_at->copy()->lte(now()->subDays(3));

    }



    public function sendInvoiceEmail(Invoice $invoice): bool

    {

        if (! $this->invoiceIsSendable($invoice)) {

            return false;

        }



        $shipment = $invoice->shipment;

        if (! $shipment && $invoice->shipment_id) {

            $shipment = Shipment::query()->find($invoice->shipment_id);

        }

        if (! $shipment) {

            return false;

        }



        $payer = strtolower((string) ($invoice->payer_type ?? 'merchant'));

        if ($payer === 'company') {

            $email = $this->companyBillingEmail($shipment);

            if (! $email) {

                Log::info('delivery_bill.company_email_skipped', [

                    'invoice_id' => $invoice->id,

                    'reason' => 'Marketplace billing email and BILLING_MARKETPLACE_EMAIL are empty',

                ]);



                return false;

            }



            if (Schema::hasColumn('invoices', 'billed_to_email') && $invoice->billed_to_email !== $email) {

                $invoice->forceFill(['billed_to_email' => $email])->save();

            }



            $marketplace = Marketplace::resolveForShipment($shipment, $shipment->merchant);

            $who = $marketplace?->name ?: 'marketplace';

            $subject = 'Marketplace free delivery bill '.$invoice->invoice_number.' ('.$who.')';



            $sent = $this->deliverRawEmail($email, $subject, $this->buildInvoiceEmailBody($invoice, $shipment));
            if ($sent) {
                $this->stampInvoicesSent([$invoice]);
            }

            return $sent;

        }



        $merchant = Merchant::query()->find($shipment->merchant_id);

        $email = $merchant?->email ?? $merchant?->contact_email ?? null;



        if (! $email) {

            return false;

        }



        $subject = 'Delivery charge bill '.$invoice->invoice_number;



        $sent = $this->deliverRawEmail($email, $subject, $this->buildInvoiceEmailBody($invoice, $shipment));
            if ($sent) {
                $this->stampInvoicesSent([$invoice]);
            }

            return $sent;

    }



    protected function deliverRawEmail(string $email, string $subject, string $body): bool

    {

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

        $payer = strtolower((string) ($invoice->payer_type ?? 'merchant'));

        $who = $payer === 'company' ? 'marketplace (tukaatu.com)' : 'branch';



        return sprintf(

            "Delivery charge bill for shipment %s.\nInvoice: %s\nAmount due to %s: Rs. %s\n(This is separate from POD cash and from HQ commission.)",

            $shipment->tracking_number,

            $invoice->invoice_number,

            $who,

            number_format((float) $invoice->total_amount, 2),

        );

    }



    /**

     * Build digest email body for multiple invoice models.

     *

     * @param  iterable<Invoice>  $invoices

     */

    public function buildDigestEmailBody(iterable $invoices, string $audience = 'merchant'): string

    {

        $total = 0;
        $count = 0;
        $itemLines = [];

        $lines = $audience === 'company'

            ? ["Tukaatu marketplace,\n\nHere is the marketplace free-delivery summary:\n"]

            : ["Dear Merchant,\n\nHere is your delivery charges summary:\n"];



        foreach ($invoices as $inv) {

            if (! $this->invoiceIsSendable($inv)) {

                continue;

            }

            $payerType = strtolower((string) ($inv->payer_type ?? 'merchant'));

            if ($audience === 'company' && $payerType !== 'company') {

                continue;

            }

            if ($audience !== 'company' && $payerType === 'company') {

                continue;

            }

            $shipment = $inv->shipment ?? null;

            $amount = (float) $inv->total_amount;

            $total += $amount;

            $tracking = $shipment?->tracking_number ?? 'N/A';

            $itemLines[] = "- {$inv->invoice_number} | Shipment: {$tracking} | Amount: Rs. " . number_format($amount, 2);

        }



        foreach ($itemLines as $itemLine) {
            $count++;
        }
        $lines[] = 'Shipments: '.$count;
        $lines[] = 'Total amount due: Rs. '.number_format($total, 2);
        $lines[] = '';
        foreach ($itemLines as $itemLine) {
            $lines[] = $itemLine;
        }
        $lines[] = "\nTotal Amount Due: Rs. " . number_format($total, 2);

        $lines[] = "\n(This is separate from POD cash and from HQ commission.)";

        if ($audience !== 'company') {

            $lines[] = "\nPlease log in to your merchant portal to view and pay invoices.";

        }



        return implode("\n", $lines);

    }



    /**

     * Get unpaid merchant invoices within date range. Company invoices are excluded.

     */

    public function getUnpaidInvoicesForMerchant(int $merchantId, ?string $fromDate = null, ?string $toDate = null): \Illuminate\Database\Eloquent\Collection

    {

        $query = Invoice::with('shipment')

            ->where('merchant_id', $merchantId)

            ->where('type', 'delivery_charges')

            ->where('status', 'unpaid')

            ->orderBy('invoice_date', 'desc');



        if (Schema::hasColumn('invoices', 'payer_type')) {

            $query->where(function ($inner) {

                $inner->where('payer_type', 'merchant')->orWhereNull('payer_type');

            });

        }



        if ($fromDate) {

            $query->whereDate('invoice_date', '>=', $fromDate);

        }

        if ($toDate) {

            $query->whereDate('invoice_date', '<=', $toDate);

        }



        return $query->get();

    }



    /**

     * Unpaid company (marketplace free delivery) invoices for a digest window.

     */

    public function getUnpaidCompanyInvoices(?string $fromDate = null, ?string $toDate = null): \Illuminate\Database\Eloquent\Collection

    {

        $query = Invoice::with('shipment')

            ->where('type', 'delivery_charges')

            ->where('status', 'unpaid')

            ->orderBy('invoice_date', 'desc');



        if (Schema::hasColumn('invoices', 'payer_type')) {

            $query->where('payer_type', 'company');

        } else {

            $query->whereRaw('1 = 0');

        }



        if ($fromDate) {

            $query->whereDate('invoice_date', '>=', $fromDate);

        }

        if ($toDate) {

            $query->whereDate('invoice_date', '<=', $toDate);

        }



        return $query->get();

    }

}

