<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\BillingPaymentNotice;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingPaymentNoticeService;
use Modules\Billing\Services\MarketplaceBillingService;
use Modules\Billing\Services\MerchantInvoicePaymentService;
use Modules\Branch\Models\Branch;

/**
 * Merchant portal "Billing" (v1/merchant/billing/*).
 *
 * The store sees what it owes each branch for delivery charges and tells the
 * branch it paid: by invoice number, "pay all" (oldest first) or a part amount.
 * Every such notice is pending_verification until the billing branch manager
 * verifies it; nothing here marks an invoice paid. HamroPay is the only path
 * that records a payment automatically (after the provider confirms it).
 */
class MerchantBillingController extends Controller
{
    public function __construct(
        private MerchantInvoicePaymentService $payments,
        private BillingPaymentNoticeService $notices,
        private MarketplaceBillingService $billing,
    ) {
    }

    /** GET summary: totals + per branch (each branch is paid separately). */
    public function summary(Request $request)
    {
        $merchantId = $this->merchantId($request);
        $open = $this->notices->openMerchantInvoicesQuery($merchantId)->get();
        $pendingByInvoice = $this->pendingByInvoice($open->pluck('id')->all());
        $branches = Branch::query()->whereIn('id', $open->pluck('branch_id')->filter()->unique())->pluck('name', 'id');

        $byBranch = [];
        foreach ($open as $inv) {
            $bid = (int) $inv->branch_id;
            $due = $this->payments->outstanding($inv);
            $pending = $pendingByInvoice[$inv->id] ?? 0.0;
            $byBranch[$bid] ??= ['branch_id' => $bid, 'branch_name' => $branches[$bid] ?? null, 'invoices' => 0, 'outstanding' => 0.0, 'pending_verification' => 0.0, 'claimable' => 0.0, 'oldest_invoice_date' => null];
            $b = &$byBranch[$bid];
            $b['invoices']++;
            $b['outstanding'] = round($b['outstanding'] + $due, 2);
            $b['pending_verification'] = round($b['pending_verification'] + $pending, 2);
            $b['claimable'] = round($b['claimable'] + max(0, $due - $pending), 2);
            $d = $inv->invoice_date?->toDateString();
            if ($d && (! $b['oldest_invoice_date'] || $d < $b['oldest_invoice_date'])) {
                $b['oldest_invoice_date'] = $d;
            }
            unset($b);
        }
        $rows = array_values($byBranch);

        $paidMonth = DB::table('merchant_invoice_payments')->where('merchant_id', $merchantId)->where('status', 'recorded')
            ->where('paid_at', '>=', now()->startOfMonth())->selectRaw('COUNT(*) c, COALESCE(SUM(amount),0) t')->first();

        return ApiResponse::success([
            'currency' => 'NPR',
            'outstanding' => round(array_sum(array_column($rows, 'outstanding')), 2),
            'pending_verification' => round(array_sum(array_column($rows, 'pending_verification')), 2),
            'claimable' => round(array_sum(array_column($rows, 'claimable')), 2),
            'open_invoices' => $open->count(),
            'paid_this_month' => ['payments' => (int) $paidMonth->c, 'total' => round((float) $paidMonth->t, 2)],
            'by_branch' => $rows,
            'rule' => 'Payments are applied oldest invoice first. A payment notice is checked by the branch before it counts as paid; HamroPay payments are confirmed automatically.',
        ]);
    }

    /** GET invoices?status=open|paid|partially_paid|unpaid|all&branch_id=&search=&per_page= */
    public function invoices(Request $request)
    {
        $merchantId = $this->merchantId($request);
        $q = $this->base($merchantId)->with(['branch:id,name,code', 'shipment:id,tracking_number,delivered_at'])
            ->orderByRaw("CASE WHEN status IN ('unpaid','partially_paid') THEN 0 ELSE 1 END")
            ->orderBy('invoice_date')->orderBy('id');
        $status = (string) $request->query('status', 'open');
        if ($status === 'open') {
            $q->whereIn('status', MerchantInvoicePaymentService::OPEN_STATUSES);
        } elseif (in_array($status, ['paid', 'unpaid', 'partially_paid'], true)) {
            $q->where('status', $status);
        }
        if ($request->filled('branch_id')) {
            $q->where('branch_id', (int) $request->query('branch_id'));
        }
        if ($request->filled('search')) {
            $s = trim((string) $request->query('search'));
            $q->where(fn ($w) => $w->where('invoice_number', 'like', '%'.$s.'%')
                ->orWhereHas('shipment', fn ($sq) => $sq->where('tracking_number', 'like', '%'.$s.'%')));
        }
        $page = $q->paginate(min(max((int) $request->query('per_page', 25), 1), 100));
        $pending = $this->pendingByInvoice(collect($page->items())->pluck('id')->all());
        $payload = $page->toArray();
        $payload['data'] = collect($page->items())->map(fn (Invoice $i) => $this->presentRow($i, $pending[$i->id] ?? 0.0))->values();

        return ApiResponse::success($payload);
    }

    /** GET invoices/lookup?number=INV-... (pay by invoice number) */
    public function lookup(Request $request)
    {
        $merchantId = $this->merchantId($request);
        $number = trim((string) $request->query('number', ''));
        abort_if($number === '', 422, 'Enter an invoice number.');
        $inv = $this->base($merchantId)->with(['branch:id,name,code', 'shipment:id,tracking_number,delivered_at'])
            ->where('invoice_number', $number)->first();
        abort_unless($inv, 404, 'No invoice '.$number.' found for your store.');

        return ApiResponse::success($this->presentRow($inv, $this->notices->pendingSum('invoice', (int) $inv->id)) + ['payable' => $this->payments->payable($inv)]);
    }

    /** GET invoices/{invoice}: detail with payment history and notices. */
    public function show(Request $request, int $invoice)
    {
        $inv = $this->own($request, $invoice);
        $inv->load(['branch:id,name,code', 'shipment:id,tracking_number,delivered_at', 'items']);

        return ApiResponse::success($this->presentRow($inv, $this->notices->pendingSum('invoice', (int) $inv->id)) + [
            'items' => $inv->items->map(fn ($it) => ['description' => $it->description, 'amount' => round((float) ($it->amount ?? $it->total ?? 0), 2)])->values(),
            'payments' => $this->payments->history($inv, false),
            'notices' => $this->notices->forTarget('invoice', (int) $inv->id, false),
            'payable' => $this->payments->payable($inv),
        ]);
    }

    /** POST invoices/{invoice}/payment-notice {amount?, method, reference, paid_at?, note?, request_id?} */
    public function notice(Request $request, int $invoice)
    {
        $inv = $this->own($request, $invoice);
        $data = $this->validateNotice($request);
        $r = $this->notices->submit('invoice', $inv, $data['amount'] ?? null, $data['method'], $data['reference'], $data['paid_at'] ?? null, $data['note'] ?? null,
            'merchant_portal', ['type' => 'user', 'id' => $request->user()->id], $this->requestId($request, $data));

        return ApiResponse::success([
            'notice' => $this->notices->present($r['notice'], false),
            'idempotent_replay' => $r['replay'],
            'invoice' => $this->presentRow($inv->fresh(['branch:id,name,code', 'shipment:id,tracking_number,delivered_at']), $this->notices->pendingSum('invoice', (int) $inv->id)),
        ], $r['replay'] ? 'This notice was already sent.' : 'Payment notice sent to '.($inv->branch?->name ?? 'the branch').'. It counts as paid once the branch verifies it.', $r['replay'] ? 200 : 202);
    }

    /** GET notices?status=pending_verification|verified|rejected|all */
    public function notices(Request $request)
    {
        $merchantId = $this->merchantId($request);
        $q = BillingPaymentNotice::query()->where('payer_type', 'merchant')->where('payer_id', $merchantId)->where('target_type', 'invoice')->latest('id');
        $status = (string) $request->query('status', 'all');
        if ($status !== 'all') {
            $q->where('status', $status);
        }
        $page = $q->paginate(min(max((int) $request->query('per_page', 25), 1), 100));
        $payload = $page->toArray();
        $payload['data'] = collect($page->items())->map(fn ($n) => $this->notices->present($n, false))->values();

        return ApiResponse::success($payload);
    }

    /** GET pay-all/preview?amount=&branch_id= : oldest-first allocation, nothing saved. */
    public function payAllPreview(Request $request)
    {
        $merchantId = $this->merchantId($request);
        $request->validate(['amount' => ['nullable', 'numeric'], 'branch_id' => ['nullable', 'integer']]);
        $items = $this->notices->openMerchantInvoicesQuery($merchantId, $request->filled('branch_id') ? (int) $request->query('branch_id') : null)->get();
        $alloc = $this->notices->allocate($items, 'invoice', $request->query('amount'));

        return ApiResponse::success($this->notices->presentAllocation($alloc));
    }

    /** POST pay-all {amount?, branch_id?, method, reference?, paid_at?, note?, request_id?} */
    public function payAll(Request $request)
    {
        $merchantId = $this->merchantId($request);
        $data = $request->validate([
            'amount' => ['nullable', 'numeric'],
            'branch_id' => ['nullable', 'integer'],
            'method' => ['required', 'string', 'max:64'],
            'reference' => ['nullable', 'string', 'max:191'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'request_id' => ['nullable', 'string', 'max:100'],
        ]);
        $r = $this->notices->submitMerchantPayAll($merchantId, $data['amount'] ?? null, $data['branch_id'] ?? null, $data['method'], $data['reference'] ?? null,
            $data['paid_at'] ?? null, $data['note'] ?? null, ['type' => 'user', 'id' => $request->user()->id], $this->requestId($request, $data));

        return ApiResponse::success($r, $r['replay']
            ? 'These notices were already sent.'
            : 'Payment notice Rs '.number_format((float) $r['amount'], 2).' sent ('.count($r['notices']).' invoice(s), '.$r['batch_reference'].'). Each branch verifies its part.', $r['replay'] ? 200 : 202);
    }

    /** POST hamropay {invoice_id?, amount?, branch_id?}: one checkout per branch, confirmed by HamroPay. */
    public function hamroPay(Request $request)
    {
        $merchantId = $this->merchantId($request);
        $data = $request->validate(['invoice_id' => ['nullable', 'integer'], 'amount' => ['nullable', 'numeric'], 'branch_id' => ['nullable', 'integer']]);
        $inv = ! empty($data['invoice_id']) ? $this->own($request, (int) $data['invoice_id']) : null;
        if ($inv) {
            $this->payments->assertPayable($inv);
        }
        $r = $this->notices->startMerchantHamroPay($merchantId, $inv, $data['amount'] ?? null, $data['branch_id'] ?? null, ['type' => 'user', 'id' => $request->user()->id]);
        $ok = collect($r['sessions'])->whereNull('error')->count();

        return ApiResponse::success($r, $ok === count($r['sessions'])
            ? 'HamroPay checkout ready ('.$ok.' branch payment(s)).'
            : $ok.' of '.count($r['sessions']).' branch checkout(s) ready; see the errors for the others.', $ok > 0 ? 201 : 422);
    }

    /** POST hamropay/confirm {merchant_txn_id} */
    public function confirmHamroPay(Request $request)
    {
        $merchantId = $this->merchantId($request);
        $data = $request->validate(['merchant_txn_id' => ['required', 'string', 'max:64']]);
        $txn = trim($data['merchant_txn_id']);
        if (str_starts_with($txn, 'MIPAY-')) {
            abort_unless(str_starts_with($txn, 'MIPAY-'.$merchantId.'-'), 404, 'Unknown HamroPay transaction.');
            $r = $this->notices->confirmMerchantHamroPay($txn, $merchantId);
        } elseif (preg_match('/^INVPAY-(\d+)-/', $txn, $m)) {
            $this->own($request, (int) $m[1]);
            $r = $this->billing->confirmHamroPayTransaction($txn);
            unset($r['invoice'], $r['payment'], $r['provider']);
        } else {
            abort(422, 'Unknown HamroPay transaction id.');
        }
        if (empty($r['paid'])) {
            return ApiResponse::error('HamroPay has not confirmed this payment yet.', 422, ['status' => $r['status'] ?? null]);
        }

        return ApiResponse::success($r, ! empty($r['replay']) || ($r['status'] ?? '') === 'already_recorded' ? 'Payment already recorded.' : 'HamroPay payment confirmed and recorded.');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function merchantId(Request $request): int
    {
        $id = (int) ($request->user()?->merchant_id ?? 0);
        abort_if($id <= 0, 403, 'No store is linked to this account.');

        return $id;
    }

    private function base(int $merchantId): Builder
    {
        return Invoice::query()
            ->where('merchant_id', $merchantId)
            ->where('type', 'delivery_charges')
            ->where(fn ($q) => $q->where('payer_type', 'merchant')->orWhereNull('payer_type'));
    }

    private function own(Request $request, int $invoiceId): Invoice
    {
        $inv = $this->base($this->merchantId($request))->whereKey($invoiceId)->first();
        abort_unless($inv, 404, 'Invoice not found.');

        return $inv;
    }

    private function validateNotice(Request $request): array
    {
        return $request->validate([
            'amount' => ['nullable', 'numeric'],
            'method' => ['required', 'string', 'max:64'],
            'reference' => ['required', 'string', 'max:191'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'request_id' => ['nullable', 'string', 'max:100'],
        ]);
    }

    private function requestId(Request $request, array $data): ?string
    {
        $raw = trim((string) ($data['request_id'] ?? $request->header('X-Request-Id', '')));

        return $raw !== '' ? 'mu:'.$request->user()->id.':'.$raw : null;
    }

    /** @return array<int, float> invoice id => pending notice amount */
    private function pendingByInvoice(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return BillingPaymentNotice::query()->where('target_type', 'invoice')->whereIn('target_id', $ids)
            ->where(fn ($q) => $q->where('status', 'pending_verification')
                ->orWhere(fn ($w) => $w->where('status', 'awaiting_gateway')->where('created_at', '>=', now()->subMinutes(30))))
            ->groupBy('target_id')->selectRaw('target_id, SUM(amount) s')->pluck('s', 'target_id')
            ->map(fn ($v) => round((float) $v, 2))->all();
    }

    private function presentRow(Invoice $i, float $pending): array
    {
        $total = round((float) $i->total_amount, 2);
        $status = strtolower((string) $i->status);
        $due = in_array($status, MerchantInvoicePaymentService::OPEN_STATUSES, true) ? $this->payments->outstanding($i) : 0.0;
        $paid = $status === 'paid' ? $total : round($total - $due, 2);

        return [
            'id' => $i->id,
            'invoice_number' => $i->invoice_number,
            'invoice_date' => $i->invoice_date?->toDateString(),
            'branch_id' => $i->branch_id,
            'branch_name' => $i->branch?->name,
            'tracking_number' => $i->shipment?->tracking_number,
            'delivered_at' => $i->shipment?->delivered_at,
            'status' => $status,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'outstanding_amount' => $due,
            'pending_verification_amount' => $pending,
            'claimable' => max(0.0, round($due - $pending, 2)),
            'paid_percent' => $total > 0 ? round(min(100, $paid / $total * 100), 1) : 0.0,
            'paid_at' => $i->paid_at?->toIso8601String(),
            'on_marketplace_bill' => (bool) $i->marketplace_bill_id,
        ];
    }
}