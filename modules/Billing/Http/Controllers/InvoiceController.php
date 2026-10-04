<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\MerchantDeliveryBillingService;
use Modules\Billing\Services\PaymentGatewayAccountService;
use Modules\Shipment\Models\Shipment;

class InvoiceController extends Controller
{
    public function __construct(
        private MerchantDeliveryBillingService $billing,
        private PaymentGatewayAccountService $accounts,
    ) {
    }

    public function index(Request $request)
    {
        $query = Invoice::with([
            'items',
            'shipment',
            'merchant:id,name,code,external_store_id,marketplace_id',
            'merchant.marketplace:id,name,code',
        ])->latest();
        
        // Merchant can only see their own invoices
        if ($request->user()->role === 'merchant') {
            $query->where('merchant_id', $request->user()->merchant_id);
        }
        
        // Admin filters
        if ($request->filled('merchant_id')) {
            $query->where('merchant_id', $request->merchant_id);
        }
        if ($request->filled('marketplace_id')) {
            $marketplaceId = (int) $request->input('marketplace_id');
            $query->whereHas('merchant', fn ($mq) => $mq->where('marketplace_id', $marketplaceId));
        }
        $merchantSearch = trim((string) $request->input('merchant', $request->input('q', '')));
        if ($merchantSearch !== '') {
            if (ctype_digit($merchantSearch)) {
                $query->where('merchant_id', (int) $merchantSearch);
            } else {
                $query->whereHas('merchant', function ($mq) use ($merchantSearch) {
                    $like = '%'.$merchantSearch.'%';
                    $mq->where('name', 'like', $like)
                        ->orWhere('external_store_id', 'like', $like)
                        ->orWhere('code', 'like', $like);
                });
            }
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->routeIs('admin.delivery-charges.index')) {
            $query->where('type', 'delivery_charges');
        } elseif ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->boolean('due_for_check') && \Illuminate\Support\Facades\Schema::hasColumn('invoices', 'sent_at')) {
            $query->where('status', 'unpaid')
                ->whereNotNull('sent_at')
                ->where('sent_at', '<=', now()->subDays(3))
                ->whereNull('manual_checked_at');
        }

        if ($request->filled('payer_type')) {
            $payer = strtolower((string) $request->input('payer_type'));
            if ($payer === 'company') {
                $query->where('payer_type', 'company');
            } elseif (in_array($payer, ['merchant', 'store'], true)) {
                $query->where(function ($inner) {
                    $inner->where('payer_type', 'merchant')->orWhereNull('payer_type');
                });
            }
        }
        
        // Date range filters
        if ($request->filled('from_date')) {
            $query->whereDate('invoice_date', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('invoice_date', '<=', $request->to_date);
        }
        
        // Search by invoice number or tracking number
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('shipment', function ($sq) use ($search) {
                      $sq->where('tracking_number', 'like', "%{$search}%");
                  });
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(function (Invoice $invoice) {
            $merchant = $invoice->merchant;
            $shipment = $invoice->shipment;
            $store = $merchant ?: $shipment?->merchant;
            $mp = $merchant?->marketplace ?: $store?->marketplace;
            if (! $mp && $shipment) {
                $mp = \Modules\Setting\Models\Marketplace::resolveForShipment($shipment, $store);
            }
            $invoice->setAttribute('merchant_name', $store?->name);
            $invoice->setAttribute('external_store_id', $store?->external_store_id);
            $invoice->setAttribute('marketplace_id', $mp?->id ?? $store?->marketplace_id);
            $invoice->setAttribute('marketplace_name', $mp?->name);
            $invoice->setAttribute('marketplace_code', $mp?->code);
            $invoice->setAttribute('bill_to', $this->billing->invoiceRecipient($invoice));
            $invoice->setAttribute('tracking_number', $shipment?->tracking_number);
            $invoice->setAttribute('delivery_count', $this->deliveryCount($invoice));
            $invoice->setAttribute('due_for_check', $this->billing->isDueForCheck($invoice));
            $invoice->setAttribute('days_since_sent', $invoice->sent_at ? (int) $invoice->sent_at->diffInDays(now()) : null);

            return $invoice;
        });
        
        $payload = $paginator->toArray();
        $payload['merchant_summary'] = $this->merchantBillingSummary(clone $query);

        return ApiResponse::success($payload);
    }


    /**
     * One delivery-charge bill is one shipment. POD fee lines are not extra deliveries.
     */
    private function deliveryCount(Invoice $invoice): int
    {
        if ((string) $invoice->type !== 'delivery_charges') {
            return 0;
        }

        if ($invoice->shipment_id) {
            return 1;
        }

        return $invoice->items->filter(function ($item): bool {
            return ! str_starts_with((string) $item->description, 'POD service fee');
        })->count();
    }


    /**
     * Merchant-wise unpaid/paid counts for the current filters.
     * Marketplace (payer company) bills stay out of merchant totals.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return list<array<string, mixed>>
     */
    private function merchantBillingSummary($query): array
    {
        $rows = (clone $query)
            ->reorder()
            ->setEagerLoads([])
            ->selectRaw('merchant_id, payer_type, status, COUNT(*) as bill_count, COALESCE(SUM(total_amount), 0) as total')
            ->groupBy('merchant_id', 'payer_type', 'status')
            ->get();

        $merchantIds = $rows->pluck('merchant_id')->filter()->unique()->values();
        $merchants = $merchantIds->isEmpty()
            ? collect()
            : \Modules\Merchant\Models\Merchant::query()
                ->whereIn('id', $merchantIds)
                ->get(['id', 'name', 'external_store_id', 'code'])
                ->keyBy('id');

        $buckets = [];
        $marketplace = [
            'merchant_id' => null,
            'merchant_name' => 'Marketplace bills',
            'external_store_id' => null,
            'unpaid_count' => 0,
            'unpaid_total' => 0.0,
            'paid_count' => 0,
            'due_for_check_count' => 0,
            'side' => 'marketplace',
        ];

        foreach ($rows as $row) {
            $payer = strtolower((string) ($row->payer_type ?? 'merchant'));
            $status = strtolower((string) $row->status);
            $count = (int) $row->bill_count;
            $total = (float) $row->total;
            $isMarketplace = $payer === 'company' || $row->merchant_id === null;
            if ($isMarketplace) {
                if ($status === 'unpaid') {
                    $marketplace['unpaid_count'] += $count;
                    $marketplace['unpaid_total'] += $total;
                } elseif ($status === 'paid') {
                    $marketplace['paid_count'] += $count;
                }
                continue;
            }

            $id = (int) $row->merchant_id;
            if (! isset($buckets[$id])) {
                $merchant = $merchants->get($id);
                $buckets[$id] = [
                    'merchant_id' => $id,
                    'merchant_name' => $merchant?->name,
                    'external_store_id' => $merchant?->external_store_id,
                    'unpaid_count' => 0,
                    'unpaid_total' => 0.0,
                    'paid_count' => 0,
                    'due_for_check_count' => 0,
                    'side' => 'merchant',
                ];
            }
            if ($status === 'unpaid') {
                $buckets[$id]['unpaid_count'] += $count;
                $buckets[$id]['unpaid_total'] += $total;
            } elseif ($status === 'paid') {
                $buckets[$id]['paid_count'] += $count;
            }
        }

        if (\Illuminate\Support\Facades\Schema::hasColumn('invoices', 'sent_at')) {
            $dueRows = (clone $query)
                ->reorder()
                ->setEagerLoads([])
                ->where('status', 'unpaid')
                ->whereNotNull('sent_at')
                ->where('sent_at', '<=', now()->subDays(3))
                ->whereNull('manual_checked_at')
                ->selectRaw('merchant_id, payer_type, COUNT(*) as due_count')
                ->groupBy('merchant_id', 'payer_type')
                ->get();
            foreach ($dueRows as $due) {
                $payer = strtolower((string) ($due->payer_type ?? 'merchant'));
                $dueCount = (int) $due->due_count;
                if ($payer === 'company' || $due->merchant_id === null) {
                    $marketplace['due_for_check_count'] += $dueCount;
                    continue;
                }
                $id = (int) $due->merchant_id;
                if (! isset($buckets[$id])) {
                    continue;
                }
                $buckets[$id]['due_for_check_count'] += $dueCount;
            }
        }

        $list = array_values($buckets);
        usort($list, fn (array $a, array $b): int => $b['unpaid_total'] <=> $a['unpaid_total']);
        if ($marketplace['unpaid_count'] > 0 || $marketplace['paid_count'] > 0 || $marketplace['due_for_check_count'] > 0) {
            array_unshift($list, $marketplace);
        }

        return $list;
    }

    public function shipmentInvoice(Request $request, Shipment $shipment)
    {
        $invoice = $this->billing->autoEnsureForShipment($shipment);
        if (! $invoice) {
            return ApiResponse::success(null, 'No merchant-owed delivery charges to bill for this shipment.');
        }

        return ApiResponse::success($invoice->load('items'), 'Delivery charge bill ready.', 201);
    }

    public function markPaid(Request $request, Invoice $invoice)
    {
        $request->validate([
            'payment_method' => ['nullable', 'string', 'max:64'],
            'reference_number' => ['nullable', 'string', 'max:191'],
        ]);

        return ApiResponse::success($this->settlePaid($invoice), 'Invoice marked paid.');
    }

    public function markChecked(Invoice $invoice)
    {
        if (strtolower((string) $invoice->status) !== 'unpaid') {
            return ApiResponse::error('Only an unpaid bill can be checked.', 422);
        }
        if (! $this->billing->isDueForCheck($invoice)) {
            return ApiResponse::error('This bill is not due for check yet.', 422);
        }

        $invoice->forceFill(['manual_checked_at' => now()])->save();

        return ApiResponse::success($invoice->fresh('items'), 'Marked checked. It stays unpaid until you close it.');
    }

    public function close(Request $request, Invoice $invoice)
    {
        if (strtolower((string) $invoice->status) === 'paid') {
            return ApiResponse::error('Invoice is already paid.', 422);
        }
        if (! $invoice->sent_at) {
            return ApiResponse::error('Send the bill before closing it.', 422);
        }

        return ApiResponse::success($this->settlePaid($invoice), 'Invoice closed and marked paid.');
    }

    private function settlePaid(Invoice $invoice): Invoice
    {
        $invoice->update([
            'status' => 'paid',
        ]);

        return $invoice->fresh('items');
    }

    /**
     * Send invoice email immediately (manual trigger)
     */
    public function sendEmail(Request $request, Invoice $invoice)
    {
        // Check permissions
        $user = $request->user();
        if ($user->role === 'merchant') {
            abort_unless((int) $user->merchant_id === (int) $invoice->merchant_id, 403);
        }

        if ($user->role === 'merchant' && strtolower((string) ($invoice->payer_type ?? 'merchant')) === 'company') {
            abort(403);
        }

        if (! $this->billing->invoiceIsSendable($invoice)) {
            return ApiResponse::error('Nothing to send. Paid bills are excluded.', 422);
        }

        $to = $this->billing->invoiceRecipient($invoice);
        $sent = $this->billing->sendInvoiceEmail($invoice);

        if ($sent) {
            return ApiResponse::success(['to' => $to], 'Invoice email sent to '.$to.'.');
        }

        $payer = strtolower((string) ($invoice->payer_type ?? 'merchant'));
        $hint = $payer === 'company'
            ? 'Set the marketplace billing email on Admin -> Marketplaces, or BILLING_MARKETPLACE_EMAIL.'
            : 'The store has no email on file.';

        return ApiResponse::error('Failed to send email. '.$hint, 500);
    }

    /**
     * Send digest email for merchant (manual trigger for admin)
     */
    public function sendDigest(Request $request)
    {
        $request->validate([
            'merchant_id' => ['required', 'integer', 'exists:merchants,id'],
            'period' => ['required', 'in:daily,weekly'],
        ]);

        $merchantId = $request->merchant_id;
        $period = $request->period;

        // Check permissions
        $user = $request->user();
        if ($user->role === 'merchant') {
            abort_unless((int) $user->merchant_id === $merchantId, 403);
        }

        $fromDate = $period === 'weekly' ? now()->subWeek()->toDateString() : now()->subDay()->toDateString();
        $unpaid = $this->billing->getUnpaidInvoicesForMerchant((int) $merchantId, $fromDate, now()->toDateString());
        if ($unpaid->isEmpty()) {
            return ApiResponse::success(null, 'Nothing to send. Paid bills are excluded.');
        }

        \Modules\Billing\Jobs\SendMerchantBillDigest::dispatch($merchantId, $period);

        return ApiResponse::success(
            ['count' => $unpaid->count()],
            $period.' digest email queued for merchant. Paid bills are excluded.'
        );
    }

    /**
     * Get invoice summary for merchant (for dashboard)
     */
    public function summary(Request $request)
    {
        $merchantId = $request->user()->role === 'merchant' 
            ? $request->user()->merchant_id 
            : $request->get('merchant_id');

        if (! $merchantId) {
            return ApiResponse::error('Merchant ID required', 400);
        }

        $unpaid = Invoice::where('merchant_id', $merchantId)
            ->where('type', 'delivery_charges')
            ->where('status', 'unpaid')
            ->selectRaw('COUNT(*) as count, SUM(total_amount) as total')
            ->first();

        $paid = Invoice::where('merchant_id', $merchantId)
            ->where('type', 'delivery_charges')
            ->where('status', 'paid')
            ->selectRaw('COUNT(*) as count, SUM(total_amount) as total')
            ->first();

        return ApiResponse::success([
            'unpaid' => [
                'count' => (int) ($unpaid->count ?? 0),
                'total' => (float) ($unpaid->total ?? 0),
            ],
            'paid' => [
                'count' => (int) ($paid->count ?? 0),
                'total' => (float) ($paid->total ?? 0),
            ],
        ]);
    }

    /**
     * Merchant pays delivery-fee invoice into the branch HamroPay account.
     */
    public function payHamroPay(Request $request, Invoice $invoice)
    {
        if (strtolower((string) $invoice->status) === 'paid') {
            throw ValidationException::withMessages([
                'invoice' => ['Invoice is already paid.'],
            ]);
        }

        if (($invoice->type ?? '') !== 'delivery_charges') {
            throw ValidationException::withMessages([
                'invoice' => ['Only delivery_charges invoices can be paid to the branch this way.'],
            ]);
        }

        $amount = (float) $invoice->total_amount;
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'invoice' => ['Invoice amount must be greater than zero.'],
            ]);
        }

        $user = $request->user();
        if (($user->role ?? '') === 'merchant') {
            abort_unless((int) $user->merchant_id === (int) $invoice->merchant_id, 403);
        }

        $invoice->loadMissing('shipment.merchant');
        $paymentTarget = app(\Modules\Setting\Services\MarketplacePaymentUrlResolver::class)
            ->resolve($invoice->shipment, $invoice->shipment?->merchant);
        if ($paymentTarget['api_base_url'] === '') {
            $label = $paymentTarget['marketplace']?->name ?: 'this store';
            throw ValidationException::withMessages([
                'payment' => ['No payment API base URL for '.$label.'. Set API base URL on Admin -> Marketplaces.'],
            ]);
        }

        $branchId = $invoice->branch_id;
        $payerType = strtolower((string) ($invoice->payer_type ?? 'merchant'));
        $marketplaceId = $paymentTarget['marketplace']?->id ? (int) $paymentTarget['marketplace']->id : null;
        $payAccount = $payerType === 'company'
            ? $this->accounts->resolveMarketplacePayerAccount($marketplaceId, $branchId ? (int) $branchId : null, 'hamropay')
            : ($branchId ? $this->accounts->branchAccount((int) $branchId, 'hamropay') : null);

        if (! $payAccount) {
            throw ValidationException::withMessages([
                'gateway' => [$payerType === 'company'
                    ? 'Marketplace HamroPay account is not configured. Save it on Admin -> Marketplaces.'
                    : 'Branch HamroPay account is not configured. Branch must save credentials under Payment gateways.'],
            ]);
        }

        $client = $this->accounts->hamroPayClientFromAccount($payAccount);
        $paymentBase = (string) ($paymentTarget['api_base_url'] ?? '');
        if ($paymentBase !== '') {
            // Same host Admin -> Marketplaces stores (api.tukaatu.com, api.fca.com.np, ...).
            $client = $client->withEndpoints($paymentBase);
        }
        $branchMerchantId = $payAccount->credential('merchant_id');
        if (! filled($branchMerchantId)) {
            throw ValidationException::withMessages([
                'gateway' => [$payerType === 'company'
                    ? 'Marketplace HamroPay merchant_id is missing.'
                    : 'Branch HamroPay merchant_id is missing.'],
            ]);
        }

        $paisa = (int) round($amount * 100);
        $txnId = 'INVPAY-'.$invoice->id.'-'.Str::upper(Str::random(8));

        $session = $client->createSession([
            'merchantTxnId' => $txnId,
            'transactionAmount' => $paisa,
            'remarks' => 'Delivery bill '.$invoice->invoice_number,
            'metadata' => [
                'payment_api_base_url' => $paymentBase,
                'marketplace_code' => $paymentTarget['marketplace']?->code,
            ],
        ], (string) $branchMerchantId, null);

        $sessionId = data_get($session, 'sessionId')
            ?? data_get($session, 'data.sessionId')
            ?? data_get($session, 'session_id');

        if (! $sessionId) {
            throw ValidationException::withMessages([
                'hamropay' => [data_get($session, 'message', 'HamroPay did not return a session id.')],
            ]);
        }

        $params = $client->buildCheckoutParams(
            (string) $sessionId,
            $txnId,
            $paisa,
            'Delivery bill '.$invoice->invoice_number,
            (string) $branchMerchantId,
        );

        return ApiResponse::success([
            'invoice_id' => $invoice->id,
            'merchant_txn_id' => $txnId,
            'session_id' => $sessionId,
            'amount' => $amount,
            'payee' => 'branch',
            'branch_id' => $branchId,
            'gateway_url' => $client->getGatewayUrl(),
            'payment_api_base_url' => $paymentTarget['api_base_url'],
            'payment_request_url' => $paymentTarget['payment_request_url'],
            'marketplace' => $paymentTarget['marketplace'] ? [
                'id' => $paymentTarget['marketplace']->id,
                'code' => $paymentTarget['marketplace']->code,
                'name' => $paymentTarget['marketplace']->name,
            ] : null,
            'checkout' => $params,
            'provider' => $session,
        ], 'HamroPay session created — merchant pays branch for delivery charges.');
    }
}
