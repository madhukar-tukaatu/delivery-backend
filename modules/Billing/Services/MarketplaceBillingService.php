<?php

namespace Modules\Billing\Services;

use App\Support\FinanceBranchScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Jobs\SendMarketplaceBillingWebhook;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\MarketplaceBill;
use Modules\Billing\Models\MarketplaceBillDelivery;
use Modules\Billing\Models\MarketplaceBillPayment;
use Modules\Billing\Models\ShipmentBranchShare;
use Modules\Branch\Models\Branch;
use Modules\Notification\Models\EmailLog;
use Modules\Notification\Models\NotificationLog;
use Modules\Setting\Models\Marketplace;
use Modules\Shipment\Models\Shipment;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Marketplace free-delivery billing, per branch.
 *
 *  1. Delivery creates a company invoice (payer_type=company) on the ORIGIN
 *     branch with invoices.marketplace_id frozen (MerchantDeliveryBillingService).
 *  2. Each branch groups its unbilled company invoices per marketplace into a
 *     MarketplaceBill (MB-<branch>-<yyyymmdd>-<seq>) and sends it: PUSH first
 *     (signed billing.bill.issued webhook to marketplaces.callback_url, delivery
 *     tracked in marketplace_bill_deliveries, retried with backoff), email second.
 *  3. The marketplace pays from its own side. It may tell us with a payment
 *     notice (POST .../payment-notice); a notice changes nothing until the
 *     billing branch manager verifies it. Branch/HQ can also record a payment
 *     directly (full or part). HamroPay (MBPAY-) is the only automatic path.
 *     Every payment goes through MarketplaceBillPaymentService.
 *  4. Only a FULLY paid bill marks its invoices paid, which releases the branch
 *     shares to inter-branch statements (BranchShareService::eligibleQuery).
 *     "Reverse payment" / "Report not received" reverse payment rows.
 */
class MarketplaceBillingService
{
    public const PAYER_TYPES = ['admin', 'branch', 'marketplace', 'hamropay', 'merchant'];

    public function __construct(
        private PaymentGatewayAccountService $accounts,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Ready to send (company invoices not yet on a bill)
    |--------------------------------------------------------------------------
    */

    public function readyQuery(?Authenticatable $user): Builder
    {
        $q = Invoice::query()
            ->where('invoices.type', 'delivery_charges')
            ->where('invoices.payer_type', 'company')
            ->where('invoices.status', 'unpaid')
            ->whereNull('invoices.marketplace_bill_id');

        return FinanceBranchScope::scopeInvoices($q, $user);
    }

    /**
     * Fill marketplace_id / branch_id on legacy company invoices so they can be
     * grouped. Only NULLs are filled.
     */
    public function normalizeLegacyCompanyInvoices(?Authenticatable $user): int
    {
        $fixed = 0;
        $rows = $this->readyQuery($user)
            ->where(fn ($q) => $q->whereNull('invoices.marketplace_id')->orWhereNull('invoices.branch_id'))
            ->with('shipment.merchant')
            ->limit(500)
            ->get();

        foreach ($rows as $invoice) {
            $patch = [];
            if (! $invoice->marketplace_id && $invoice->shipment) {
                $mp = Marketplace::resolveForShipment($invoice->shipment, $invoice->shipment->merchant);
                if ($mp) {
                    $patch['marketplace_id'] = $mp->id;
                }
            }
            if (! $invoice->branch_id && $invoice->shipment) {
                $branchId = app(BranchShareService::class)->billingBranchId($invoice->shipment);
                if ($branchId) {
                    $patch['branch_id'] = $branchId;
                }
            }
            if ($patch) {
                $invoice->forceFill($patch)->save();
                $fixed++;
            }
        }

        return $fixed;
    }

    /**
     * Ready invoices grouped per (branch, marketplace).
     *
     * @return list<array<string, mixed>>
     */
    public function readyGroups(?Authenticatable $user, array $filters = []): array
    {
        $this->normalizeLegacyCompanyInvoices($user);

        $q = $this->applyReadyFilters($this->readyQuery($user), $user, $filters);

        $rows = $q->reorder()
            ->selectRaw('invoices.branch_id, invoices.marketplace_id, COUNT(*) as invoice_count, COALESCE(SUM(invoices.total_amount),0) as total, MIN(invoices.invoice_date) as first_date, MAX(invoices.invoice_date) as last_date, SUM(CASE WHEN invoices.sent_at IS NULL THEN 0 ELSE 1 END) as emailed_before')
            ->groupBy('invoices.branch_id', 'invoices.marketplace_id')
            ->get();

        $branches = Branch::query()->whereIn('id', $rows->pluck('branch_id')->filter()->unique())->get(['id', 'name', 'code'])->keyBy('id');
        $marketplaces = Marketplace::query()->whereIn('id', $rows->pluck('marketplace_id')->filter()->unique())->get(['id', 'name', 'code', 'email', 'callback_url', 'callback_secret'])->keyBy('id');
        $fallback = trim((string) config('billing.marketplace_email'));

        return $rows->map(function ($r) use ($branches, $marketplaces, $fallback) {
            $mp = $r->marketplace_id ? $marketplaces->get((int) $r->marketplace_id) : null;
            $branch = $r->branch_id ? $branches->get((int) $r->branch_id) : null;
            $email = trim((string) ($mp?->email ?? '')) ?: ($fallback !== '' ? $fallback : null);
            $push = $this->pushTarget($mp);

            return [
                'branch_id' => $r->branch_id ? (int) $r->branch_id : null,
                'branch_name' => $branch?->name,
                'branch_code' => $branch?->code,
                'marketplace_id' => $r->marketplace_id ? (int) $r->marketplace_id : null,
                'marketplace_name' => $mp?->name ?? 'Unknown marketplace',
                'marketplace_code' => $mp?->code,
                'invoice_count' => (int) $r->invoice_count,
                'total' => round((float) $r->total, 2),
                'first_date' => $r->first_date ? Carbon::parse($r->first_date)->toDateString() : null,
                'last_date' => $r->last_date ? Carbon::parse($r->last_date)->toDateString() : null,
                'emailed_before' => (int) $r->emailed_before,
                'bill_to_email' => $email,
                'push_ready' => $push['ready'],
                'push_problem' => $push['problem'],
                'can_send' => ($email !== null || $push['ready']) && (bool) $r->branch_id && (bool) $r->marketplace_id,
                'send_blocker' => ! $r->branch_id
                    ? 'These invoices have no billing branch.'
                    : (! $r->marketplace_id
                        ? 'Marketplace unknown for these invoices.'
                        : ($email === null && ! $push['ready'] ? 'Set the callback URL + secret (API push) or a billing email on marketplace '.($mp?->name ?? '#'.$r->marketplace_id).'.' : null)),
            ];
        })->sortByDesc('total')->values()->all();
    }

    public function readyInvoices(?Authenticatable $user, array $filters = []): EloquentCollection
    {
        return $this->applyReadyFilters($this->readyQuery($user), $user, $filters)
            ->with(['shipment:id,tracking_number,merchant_id,delivered_at,origin_branch_id,destination_branch_id', 'shipment.merchant:id,name,external_store_id'])
            ->orderBy('invoices.invoice_date')
            ->orderBy('invoices.id')
            ->limit(1000)
            ->get();
    }

    private function applyReadyFilters(Builder $q, ?Authenticatable $user, array $filters): Builder
    {
        if (! empty($filters['branch_id']) && FinanceBranchScope::canSeeBranch($user, $filters['branch_id'])) {
            $q->where('invoices.branch_id', (int) $filters['branch_id']);
        }
        if (! empty($filters['marketplace_id'])) {
            $q->where('invoices.marketplace_id', (int) $filters['marketplace_id']);
        }
        if (! empty($filters['from'])) {
            $q->whereDate('invoices.invoice_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('invoices.invoice_date', '<=', $filters['to']);
        }

        return $q;
    }

    /*
    |--------------------------------------------------------------------------
    | Bills: list / summary
    |--------------------------------------------------------------------------
    */

    public function billQuery(?Authenticatable $user): Builder
    {
        return FinanceBranchScope::scopeBranchColumn(MarketplaceBill::query(), $user);
    }

    public function applyBillFilters(Builder $q, ?Authenticatable $user, array $f): Builder
    {
        if (! empty($f['status'])) {
            $status = (string) $f['status'];
            if ($status === 'awaiting') {
                // Awaiting payment = sent + partially paid (reopened has its own tab).
                $q->whereIn('status', ['sent', 'partially_paid']);
            } elseif ($status === 'unpaid' || $status === 'outstanding') {
                $q->whereIn('status', MarketplaceBill::PAYABLE_STATUSES);
            } else {
                $q->whereIn('status', array_filter(explode(',', $status)));
            }
        }
        if (! empty($f['marketplace_id'])) {
            $q->where('marketplace_id', (int) $f['marketplace_id']);
        }
        if (! empty($f['branch_id']) && FinanceBranchScope::canSeeBranch($user, $f['branch_id'])) {
            $q->where('branch_id', (int) $f['branch_id']);
        }
        if (! empty($f['from'])) {
            $q->whereDate('period_end', '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->whereDate('period_start', '<=', $f['to']);
        }
        if (! empty($f['search'])) {
            $s = trim((string) $f['search']);
            $q->where(function ($w) use ($s) {
                $w->where('bill_number', 'like', "%{$s}%")
                    ->orWhere('payment_reference', 'like', "%{$s}%")
                    ->orWhereExists(function ($e) use ($s) {
                        $e->selectRaw('1')->from('invoices as bi')
                            ->join('shipments as bs', 'bs.id', '=', 'bi.shipment_id')
                            ->whereColumn('bi.marketplace_bill_id', 'marketplace_bills.id')
                            ->where('bs.tracking_number', 'like', "%{$s}%");
                    });
            });
        }

        return $q;
    }

    /** Summary cards: ready to send, awaiting, partially paid, outstanding, paid this month. */
    public function summary(?Authenticatable $user, array $filters = []): array
    {
        $readyQ = $this->applyReadyFilters($this->readyQuery($user), $user, $filters);
        $ready = (clone $readyQ)->reorder()->selectRaw('COUNT(*) c, COALESCE(SUM(invoices.total_amount),0) t')->first();

        $bills = $this->applyBillFilters($this->billQuery($user), $user, array_diff_key($filters, ['status' => 1, 'from' => 1, 'to' => 1, 'search' => 1]));
        $awaiting = (clone $bills)->whereIn('status', ['sent', 'partially_paid'])
            ->selectRaw('COUNT(*) c, COALESCE(SUM(total_amount),0) t, COALESCE(SUM(outstanding_amount),0) o')->first();
        $partial = (clone $bills)->where('status', 'partially_paid')
            ->selectRaw('COUNT(*) c, COALESCE(SUM(total_amount),0) t, COALESCE(SUM(paid_amount),0) p, COALESCE(SUM(outstanding_amount),0) o')->first();
        $outstanding = (clone $bills)->whereIn('status', MarketplaceBill::PAYABLE_STATUSES)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(outstanding_amount),0) o')->first();
        $drafts = (clone $bills)->where('status', 'draft')->selectRaw('COUNT(*) c, COALESCE(SUM(total_amount),0) t')->first();
        $monthStart = now()->startOfMonth();
        // Money received this month = recorded payments dated this month (partial payments included).
        $paidMonth = MarketplaceBillPayment::query()
            ->where('marketplace_bill_payments.status', 'recorded')
            ->where('marketplace_bill_payments.paid_at', '>=', $monthStart)
            ->whereIn('marketplace_bill_payments.marketplace_bill_id', (clone $bills)->select('marketplace_bills.id'))
            ->selectRaw('COUNT(*) c, COUNT(DISTINCT marketplace_bill_payments.marketplace_bill_id) b, COALESCE(SUM(marketplace_bill_payments.amount),0) t')->first();
        $paidAll = (clone $bills)->where('status', 'paid')->selectRaw('COUNT(*) c, COALESCE(SUM(COALESCE(paid_amount,total_amount)),0) t')->first();
        $reopened = (clone $bills)->where('status', 'reopened')->selectRaw('COUNT(*) c, COALESCE(SUM(outstanding_amount),0) o')->first();

        return [
            'ready' => ['count' => (int) $ready->c, 'total' => round((float) $ready->t, 2)],
            'draft' => ['count' => (int) $drafts->c, 'total' => round((float) $drafts->t, 2)],
            'awaiting' => ['count' => (int) $awaiting->c, 'total' => round((float) $awaiting->t, 2), 'outstanding' => round((float) $awaiting->o, 2)],
            'partially_paid' => ['count' => (int) $partial->c, 'total' => round((float) $partial->t, 2), 'paid' => round((float) $partial->p, 2), 'outstanding' => round((float) $partial->o, 2)],
            'outstanding' => ['count' => (int) $outstanding->c, 'total' => round((float) $outstanding->o, 2)],
            'paid_this_month' => ['count' => (int) $paidMonth->c, 'bills' => (int) $paidMonth->b, 'total' => round((float) $paidMonth->t, 2), 'since' => $monthStart->toDateString()],
            'paid' => ['count' => (int) $paidAll->c, 'total' => round((float) $paidAll->t, 2)],
            'reopened_count' => (int) $reopened->c,
            'reopened' => ['count' => (int) $reopened->c, 'outstanding' => round((float) $reopened->o, 2)],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Create / send
    |--------------------------------------------------------------------------
    */

    /**
     * @param  list<int>|null  $invoiceIds  null = every ready invoice of that branch + marketplace (+ period)
     */
    public function createBill(int $branchId, int $marketplaceId, ?array $invoiceIds, ?string $from, ?string $to, ?int $userId): MarketplaceBill
    {
        $marketplace = Marketplace::query()->find($marketplaceId);
        if (! $marketplace) {
            throw ValidationException::withMessages(['marketplace_id' => ['Marketplace not found.']]);
        }
        $branch = Branch::query()->find($branchId);
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => ['Branch not found.']]);
        }

        return DB::transaction(function () use ($branch, $marketplace, $invoiceIds, $from, $to, $userId) {
            $q = Invoice::query()
                ->where('type', 'delivery_charges')
                ->where('payer_type', 'company')
                ->where('status', 'unpaid')
                ->whereNull('marketplace_bill_id')
                ->where('branch_id', $branch->id)
                ->where('marketplace_id', $marketplace->id)
                ->lockForUpdate();

            if ($invoiceIds !== null) {
                $ids = array_values(array_unique(array_map('intval', $invoiceIds)));
                $q->whereIn('id', $ids);
            }
            if ($from) {
                $q->whereDate('invoice_date', '>=', $from);
            }
            if ($to) {
                $q->whereDate('invoice_date', '<=', $to);
            }

            $invoices = $q->orderBy('invoice_date')->get();

            if ($invoices->isEmpty()) {
                throw ValidationException::withMessages(['invoice_ids' => ['Nothing ready to bill for '.$marketplace->name.' from '.$branch->name.'.']]);
            }
            if ($invoiceIds !== null && $invoices->count() !== count($ids)) {
                $missing = array_values(array_diff($ids, $invoices->pluck('id')->all()));
                throw ValidationException::withMessages(['invoice_ids' => [
                    'Some invoices are not ready to bill (already on a bill, paid, another branch or another marketplace): #'.implode(', #', $missing),
                ]]);
            }

            $total = round((float) $invoices->sum(fn ($i) => (float) $i->total_amount), 2);
            $dates = $invoices->pluck('invoice_date')->filter()->map(fn ($d) => Carbon::parse($d));

            $bill = MarketplaceBill::create([
                'bill_number' => $this->nextBillNumber($branch),
                'branch_id' => $branch->id,
                'marketplace_id' => $marketplace->id,
                'period_start' => $from ?: ($dates->min()?->toDateString() ?? now()->toDateString()),
                'period_end' => $to ?: ($dates->max()?->toDateString() ?? now()->toDateString()),
                'total_amount' => $total,
                'invoice_count' => $invoices->count(),
                'status' => 'draft',
                'paid_amount' => 0,
                'outstanding_amount' => $total,
            ]);

            Invoice::query()->whereIn('id', $invoices->pluck('id'))->update([
                'marketplace_bill_id' => $bill->id,
                'updated_at' => now(),
            ]);

            Log::info('marketplace_bill.created', ['bill' => $bill->bill_number, 'invoices' => $invoices->count(), 'total' => $total, 'user_id' => $userId]);

            return $bill->fresh();
        });
    }

    public function nextBillNumber(Branch $branch): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) ($branch->code ?: 'BR'.$branch->id)));
        $prefix = 'MB-'.$code.'-'.now()->format('Ymd').'-';
        $seq = MarketplaceBill::query()->where('bill_number', 'like', $prefix.'%')->count() + 1;
        do {
            $number = $prefix.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
            $seq++;
        } while (MarketplaceBill::query()->where('bill_number', $number)->exists());

        return $number;
    }

    public function billingEmailFor(?Marketplace $marketplace): ?string
    {
        $email = trim((string) ($marketplace?->email ?? ''));
        if ($email !== '') {
            return $email;
        }
        $fallback = trim((string) config('billing.marketplace_email'));

        return $fallback !== '' ? $fallback : null;
    }

    /**
     * Email the bill to the marketplace billing email and post billing.bill.issued.
     *
     * @return array{to: string, bill: MarketplaceBill, webhook_queued: bool}
     */
    public function send(MarketplaceBill $bill, ?int $userId): array
    {
        if (! $bill->isOpen()) {
            throw ValidationException::withMessages(['bill' => [
                $bill->isPaid() ? 'This bill is already paid.' : 'A cancelled bill cannot be sent.',
            ]]);
        }

        $bill->loadMissing(['marketplace', 'branch']);
        $email = $this->billingEmailFor($bill->marketplace);
        $push = $this->pushTarget($bill->marketplace);
        if (! $email && ! $push['ready']) {
            throw ValidationException::withMessages(['email' => [
                'Marketplace '.($bill->marketplace?->name ?? '#'.$bill->marketplace_id).' cannot receive this bill: '
                .($push['problem'] ?? 'no callback URL').' and no billing email. Set the callback URL and secret (API push, preferred) or a billing email on Admin -> Marketplaces.',
            ]]);
        }

        $lines = $this->billLines($bill);
        $resend = $bill->sent_at !== null;
        $subject = ($resend ? 'Reminder: ' : '').'Delivery charge bill '.$bill->bill_number.' from '.($bill->branch?->name ?? 'Tukaatu Express')
            .' to '.($bill->marketplace?->name ?? 'marketplace');
        $html = $this->billEmailHtml($bill, $lines);

        // Email is the secondary channel: when the API push is configured an email
        // failure does not stop the send.
        $emailError = null;
        if ($email) {
            try {
                Mail::html($html, function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                });
                $this->logEmail($email, $subject, $html);
            } catch (Throwable $e) {
                Log::warning('marketplace_bill.email_failed', ['bill' => $bill->bill_number, 'error' => $e->getMessage()]);
                if (! $push['ready']) {
                    throw ValidationException::withMessages(['email' => ['Email to '.$email.' failed: '.$e->getMessage()]]);
                }
                $emailError = 'Email to '.$email.' failed: '.$e->getMessage();
            }
        }

        DB::transaction(function () use ($bill, $userId, $email) {
            $bill->forceFill([
                // A reminder keeps partially_paid / reopened; only a draft becomes "sent".
                'status' => (string) $bill->status === 'draft' ? 'sent' : $bill->status,
                'sent_at' => $bill->sent_at ?? now(),
                'sent_by' => $userId,
                'sent_to_email' => $email,
                'send_count' => (int) $bill->send_count + 1,
            ])->save();

            Invoice::query()->where('marketplace_bill_id', $bill->id)->whereNull('sent_at')->update(['sent_at' => now()]);
            Invoice::query()->where('marketplace_bill_id', $bill->id)->update([
                'sent_by' => $userId,
                'send_count' => DB::raw('send_count + 1'),
                'billed_to_email' => $email,
                'updated_at' => now(),
            ]);
        });

        $queued = $this->webhook($bill->fresh(), $resend ? 'billing.bill.reminder' : 'billing.bill.issued', $lines);

        $to = trim(implode(' + ', array_filter([$push['ready'] ? 'marketplace API (push)' : null, $email])));

        return ['to' => $to !== '' ? $to : 'marketplace', 'bill' => $bill->fresh(), 'webhook_queued' => $queued, 'email' => $email, 'email_error' => $emailError, 'push' => $push];
    }

    /*
    |--------------------------------------------------------------------------
    | Payment
    |--------------------------------------------------------------------------
    */

    /**
     * Record a payment (full or partial). Kept for older callers; everything goes
     * through MarketplaceBillPaymentService::record().
     *
     * @param  array{amount?: float|string|null, method?: ?string, reference?: ?string, paid_at?: ?string, note?: ?string}  $data
     */
    public function markPaid(MarketplaceBill $bill, array $data, string $byType, ?int $byId, ?string $requestId = null): MarketplaceBill
    {
        $source = match ($byType) {
            'marketplace' => 'marketplace_api',
            'hamropay' => 'hamropay',
            'branch' => 'branch',
            default => 'admin',
        };
        $result = $this->payments()->record(
            $bill,
            $data['amount'] ?? null,
            $data['method'] ?? null,
            $data['reference'] ?? null,
            $source,
            ['type' => $byType === 'marketplace' ? 'marketplace' : ($byId ? 'user' : 'system'), 'id' => $byId],
            $requestId,
            $data['paid_at'] ?? null,
            $data['note'] ?? null,
        );
        $out = $result['bill'];
        $out->setAttribute('idempotent_replay', $result['replay']);

        return $out;
    }

    /**
     * Billing branch / HQ: a recorded payment did not arrive. Reverses the given
     * payment (or every recorded payment) with the reason.
     *
     * @return array{bill: MarketplaceBill, reversed: list<MarketplaceBillPayment>, shares_already_on_statements: int, statement_lines_affected: int, invoices_reopened: int}
     */
    public function reportNotReceived(MarketplaceBill $bill, string $reason, ?int $userId, ?int $paymentId = null): array
    {
        $r = $this->payments()->reportNotReceived($bill, $reason, $userId, $paymentId);

        return [
            'bill' => $r['bill'],
            'reversed' => $r['reversed'],
            'shares_already_on_statements' => $r['statement_lines_affected'],
            'statement_lines_affected' => $r['statement_lines_affected'],
            'invoices_reopened' => $r['invoices_reopened'],
        ];
    }

    public function payments(): MarketplaceBillPaymentService
    {
        return app(MarketplaceBillPaymentService::class);
    }

    public function cancel(MarketplaceBill $bill, ?int $userId): MarketplaceBill
    {
        $bill = DB::transaction(function () use ($bill, $userId) {
            $locked = MarketplaceBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['bill' => ['Only an unpaid draft, sent or reopened bill can be cancelled.']]);
            }
            if (MarketplaceBillPayment::query()->where('marketplace_bill_id', $locked->id)->where('status', 'recorded')->exists()) {
                throw ValidationException::withMessages(['bill' => ['This bill has recorded payments (Rs '.number_format((float) $locked->paid_amount, 2).'). Reverse them before cancelling.']]);
            }
            Invoice::query()->where('marketplace_bill_id', $locked->id)->where('status', 'unpaid')->update([
                'marketplace_bill_id' => null,
                'updated_at' => now(),
            ]);
            $locked->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $userId, 'outstanding_amount' => 0])->save();

            return $locked->fresh();
        });

        if ($bill->sent_at) {
            $this->webhook($bill, 'billing.bill.cancelled', []);
        }

        return $bill;
    }

    /*
    |--------------------------------------------------------------------------
    | Invoice payment (store bills and single marketplace invoices)
    |--------------------------------------------------------------------------
    */

    public function settleInvoice(Invoice $invoice, array $data, string $byType, ?int $byId): Invoice
    {
        if (MerchantInvoicePaymentService::isMerchantInvoice($invoice)) {
            // Store bills keep a payment ledger (partial payments, reversals).
            $source = match ($byType) {
                'admin' => 'admin',
                'hamropay' => 'hamropay',
                default => 'branch',
            };
            $amount = isset($data['paid_amount']) && $data['paid_amount'] !== '' ? $data['paid_amount'] : null;
            $r = app(MerchantInvoicePaymentService::class)->record(
                $invoice, $amount, $data['payment_method'] ?? null, $data['payment_reference'] ?? null, $source,
                ['type' => $byType === 'admin' ? 'admin' : ($byType === 'hamropay' ? 'system' : 'branch'), 'id' => $byId],
                $data['request_id'] ?? null, $data['paid_at'] ?? null, $data['note'] ?? null,
            );

            return $r['invoice']->fresh('items');
        }

        if ($invoice->marketplace_bill_id) {
            $bill = MarketplaceBill::query()->find($invoice->marketplace_bill_id);
            if ($bill && $bill->isOpen()) {
                throw ValidationException::withMessages(['invoice' => [
                    'This invoice is on marketplace bill '.$bill->bill_number.'. Mark the bill paid instead.',
                ]]);
            }
        }

        $patch = ['status' => 'paid'];
        $optional = [
            'paid_at' => ! empty($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
            'paid_by_type' => $byType,
            'paid_by' => $byId,
            'payment_method' => isset($data['payment_method']) ? mb_substr((string) $data['payment_method'], 0, 64) : null,
            'payment_reference' => isset($data['payment_reference']) ? mb_substr((string) $data['payment_reference'], 0, 191) : null,
            'paid_amount' => isset($data['paid_amount']) && $data['paid_amount'] !== null && $data['paid_amount'] !== ''
                ? round((float) $data['paid_amount'], 2)
                : round((float) $invoice->total_amount, 2),
            'outstanding_amount' => 0,
        ];
        foreach ($optional as $col => $value) {
            if (Schema::hasColumn('invoices', $col)) {
                $patch[$col] = $value;
            }
        }

        $invoice->forceFill($patch)->save();

        return $invoice->fresh('items');
    }

    public static function actorType(?Authenticatable $user): string
    {
        if (FinanceBranchScope::isMerchant($user)) {
            return 'merchant';
        }

        return FinanceBranchScope::isHq($user) ? 'admin' : 'branch';
    }

    /*
    |--------------------------------------------------------------------------
    | HamroPay (money goes to the billing branch's HamroPay account)
    |--------------------------------------------------------------------------
    */

    /** HamroPay client on the billing branch account (+ marketplace host when set). */
    public function branchHamroPayClient(?int $branchId, ?Marketplace $marketplace = null): array
    {
        $account = $branchId ? $this->accounts->branchAccount($branchId, 'hamropay') : null;
        if (! $account) {
            $name = $branchId ? (Branch::query()->whereKey($branchId)->value('name') ?? 'Branch #'.$branchId) : 'The billing branch';
            throw ValidationException::withMessages(['gateway' => [$name.' has no HamroPay account. Save it under Payment gateways.']]);
        }
        $merchantId = $account->credential('merchant_id');
        if (! filled($merchantId)) {
            throw ValidationException::withMessages(['gateway' => ['Billing branch HamroPay merchant_id is missing.']]);
        }

        $client = $this->accounts->hamroPayClientFromAccount($account);
        $base = rtrim(trim((string) ($marketplace?->api_base_url ?? '')), '/');
        if ($base !== '') {
            $client = $client->withEndpoints($base);
        }

        return [$client, (string) $merchantId, $account, $base];
    }

    /**
     * Start a HamroPay checkout for $amount (default: the whole outstanding).
     * The session amount is stored on the bill (hamropay_sessions) so the
     * confirm step records exactly that amount.
     */
    public function startBillHamroPay(MarketplaceBill $bill, $amount = null): array
    {
        $bill = $bill->fresh();
        if (! $bill->isPayable()) {
            throw ValidationException::withMessages(['bill' => [$bill->isPaid() ? 'This bill is already paid.' : 'This bill is not open for payment.']]);
        }
        $outstanding = $bill->outstanding();
        $amount = ($amount === null || $amount === '') ? $outstanding : round((float) $amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
        }
        if ($amount - $outstanding > 0.004) {
            throw ValidationException::withMessages(['amount' => ['Amount exceeds outstanding Rs '.number_format($outstanding, 2).' (received Rs '.number_format($amount, 2).').']]);
        }
        $bill->loadMissing('marketplace', 'branch');
        [$client, $merchantId, , $base] = $this->branchHamroPayClient((int) $bill->branch_id, $bill->marketplace);

        $paisa = (int) round($amount * 100);
        $txnId = 'MBPAY-'.$bill->id.'-'.Str::upper(Str::random(8));
        $remarks = 'Delivery bill '.$bill->bill_number.($amount < $outstanding ? ' (part payment)' : '');

        $session = $client->createSession([
            'merchantTxnId' => $txnId,
            'transactionAmount' => $paisa,
            'remarks' => $remarks,
            'metadata' => ['bill_number' => $bill->bill_number, 'payment_api_base_url' => $base],
        ], $merchantId, null);

        $sessionId = data_get($session, 'sessionId') ?? data_get($session, 'data.sessionId') ?? data_get($session, 'session_id');
        if (! $sessionId) {
            Log::warning('hamropay.marketplace_bill_session_failed', ['bill' => $bill->bill_number, 'response' => $session]);
            throw ValidationException::withMessages(['hamropay' => [data_get($session, 'message', 'HamroPay did not return a session id.')]]);
        }

        DB::transaction(function () use ($bill, $txnId, $amount, $sessionId) {
            $locked = MarketplaceBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $sessions = (array) ($locked->hamropay_sessions ?? []);
            $sessions[$txnId] = ['amount' => $amount, 'session_id' => (string) $sessionId, 'created_at' => now()->toIso8601String()];
            $locked->forceFill(['hamropay_txn_id' => $txnId, 'hamropay_sessions' => $sessions])->save();
        });

        return [
            'bill_number' => $bill->bill_number,
            'merchant_txn_id' => $txnId,
            'session_id' => $sessionId,
            'amount' => $amount,
            'outstanding_before' => $outstanding,
            'outstanding_after_payment' => round($outstanding - $amount, 2),
            'payee' => 'branch',
            'branch_id' => $bill->branch_id,
            'branch_name' => $bill->branch?->name,
            'gateway_url' => $client->getGatewayUrl(),
            'checkout' => $client->buildCheckoutParams((string) $sessionId, $txnId, $paisa, $remarks, $merchantId),
        ];
    }

    /**
     * Result handler for INVPAY-<invoice id>- and MBPAY-<bill id>- transactions:
     * asks HamroPay for the transaction (on the billing branch account) and marks
     * the invoice / bill paid with paid_by_type=hamropay, reference = txn id.
     *
     * @return array{paid: bool, status: string, type: string, invoice?: Invoice, bill?: MarketplaceBill, provider: array}
     */
    public function confirmHamroPayTransaction(string $txnId): array
    {
        $txnId = trim($txnId);
        if (preg_match('/^MIPAY-\d+-[A-Z0-9]+$/', $txnId)) {
            $r = app(BillingPaymentNoticeService::class)->confirmMerchantHamroPay($txnId);

            return $r + ['type' => 'merchant_batch', 'provider' => []];
        }
        if (! preg_match('/^(INVPAY|MBPAY)-(\d+)-[A-Z0-9]+$/', $txnId, $m)) {
            throw ValidationException::withMessages(['merchant_txn_id' => ['Unknown HamroPay transaction id.']]);
        }
        $type = $m[1];
        $id = (int) $m[2];

        if ($type === 'MBPAY') {
            $bill = MarketplaceBill::query()->with('marketplace')->findOrFail($id);
            $requestId = 'hamropay:'.$txnId;
            $existing = MarketplaceBillPayment::query()->where('request_id', $requestId)->first();
            if ($existing) {
                return ['paid' => true, 'status' => 'already_recorded', 'type' => 'bill', 'bill' => $bill, 'payment' => $existing, 'replay' => true, 'provider' => []];
            }
            $sessionAmount = data_get($bill->hamropay_sessions ?? [], $txnId.'.amount');
            [$client, $merchantId] = $this->branchHamroPayClient((int) $bill->branch_id, $bill->marketplace);
            $tx = $client->getTransaction($txnId, $merchantId);
            $paid = $this->providerSaysPaid($tx);
            if (! $paid) {
                return ['paid' => false, 'status' => $this->providerStatus($tx), 'type' => 'bill', 'bill' => $bill, 'provider' => $tx];
            }

            $providerPaisa = data_get($tx, 'transactionAmount') ?? data_get($tx, 'data.transactionAmount');
            $providerAmount = is_numeric($providerPaisa) ? round(((float) $providerPaisa) / 100, 2) : null;
            if ($sessionAmount === null && $providerAmount === null) {
                throw ValidationException::withMessages(['merchant_txn_id' => ['Unknown HamroPay session for this bill; the paid amount cannot be determined. Record the payment manually.']]);
            }
            if ($sessionAmount !== null && $providerAmount !== null && abs((float) $sessionAmount - $providerAmount) > 0.004) {
                Log::warning('hamropay.marketplace_bill_amount_mismatch', ['txn' => $txnId, 'session' => $sessionAmount, 'provider' => $providerAmount]);
                throw ValidationException::withMessages(['merchant_txn_id' => ['HamroPay amount Rs '.number_format($providerAmount, 2).' does not match the checkout amount Rs '.number_format((float) $sessionAmount, 2).'. Record the payment manually.']]);
            }
            $amount = $providerAmount ?? round((float) $sessionAmount, 2);

            $result = $this->payments()->record(
                $bill, $amount, 'hamropay', $txnId, 'hamropay', ['type' => 'system', 'id' => null], $requestId, null, 'Confirmed by HamroPay'
            );

            return ['paid' => true, 'status' => $this->providerStatus($tx), 'type' => 'bill', 'bill' => $result['bill'], 'payment' => $result['payment'], 'replay' => $result['replay'], 'provider' => $tx];
        }

        $invoice = Invoice::query()->with('shipment.merchant')->findOrFail($id);
        if (strtolower((string) $invoice->status) === 'paid') {
            return ['paid' => true, 'status' => 'already_paid', 'type' => 'invoice', 'invoice' => $invoice, 'provider' => []];
        }
        $marketplace = $invoice->marketplace_id
            ? Marketplace::query()->find($invoice->marketplace_id)
            : Marketplace::resolveForShipment($invoice->shipment, $invoice->shipment?->merchant);
        [$client, $merchantId] = $this->branchHamroPayClient($invoice->branch_id ? (int) $invoice->branch_id : null, $marketplace);
        if (MerchantInvoicePaymentService::isMerchantInvoice($invoice)) {
            // Store bill: record the checkout amount as a payment (part payments allowed).
            $requestId = 'hamropay:'.$txnId;
            if (\Modules\Billing\Models\MerchantInvoicePayment::query()->where('request_id', $requestId)->exists()) {
                return ['paid' => true, 'status' => 'already_recorded', 'type' => 'invoice', 'invoice' => $invoice->fresh(), 'provider' => []];
            }
            $tx = $client->getTransaction($txnId, $merchantId);
            if (! $this->providerSaysPaid($tx)) {
                return ['paid' => false, 'status' => $this->providerStatus($tx), 'type' => 'invoice', 'invoice' => $invoice, 'provider' => $tx];
            }
            $invPayments = app(MerchantInvoicePaymentService::class);
            $sessionAmount = data_get($invoice->hamropay_sessions ?? [], $txnId.'.amount');
            $providerPaisa = data_get($tx, 'transactionAmount') ?? data_get($tx, 'data.transactionAmount');
            $amount = is_numeric($providerPaisa) ? round(((float) $providerPaisa) / 100, 2) : ($sessionAmount !== null ? round((float) $sessionAmount, 2) : $invPayments->outstanding($invoice));
            $due = $invPayments->outstanding($invoice);
            if ($amount - $due > 0.004) {
                Log::warning('hamropay.invoice_overpaid', ['txn' => $txnId, 'amount' => $amount, 'outstanding' => $due]);
                $amount = $due;
            }
            $r = $invPayments->record($invoice, $amount, 'hamropay', $txnId, 'hamropay', ['type' => 'system', 'id' => null], $requestId, null, 'Confirmed by HamroPay');

            return ['paid' => true, 'status' => $this->providerStatus($tx), 'type' => 'invoice', 'invoice' => $r['invoice'], 'payment' => $r['payment'], 'provider' => $tx];
        }
        $tx = $client->getTransaction($txnId, $merchantId);
        $paid = $this->providerSaysPaid($tx);
        if ($paid) {
            $invoice = $this->settleInvoice($invoice, [
                'payment_method' => 'hamropay',
                'payment_reference' => $txnId,
            ], 'hamropay', null);
        }

        return ['paid' => $paid, 'status' => $this->providerStatus($tx), 'type' => 'invoice', 'invoice' => $invoice, 'provider' => $tx];
    }

    private function providerStatus(array $tx): string
    {
        return strtolower((string) (data_get($tx, 'status') ?? data_get($tx, 'transactionStatus') ?? data_get($tx, 'data.status') ?? ''));
    }

    private function providerSaysPaid(array $tx): bool
    {
        return in_array($this->providerStatus($tx), ['success', 'successful', 'paid', 'completed', 'complete'], true)
            || data_get($tx, 'success') === true;
    }

    /*
    |--------------------------------------------------------------------------
    | Presenters
    |--------------------------------------------------------------------------
    */

    /** @return list<array<string, mixed>> */
    public function billLines(MarketplaceBill $bill): array
    {
        return Invoice::query()
            ->where('marketplace_bill_id', $bill->id)
            ->with(['shipment:id,tracking_number,merchant_id,delivered_at,origin_branch_id,destination_branch_id', 'shipment.merchant:id,name,external_store_id'])
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Invoice $i) => [
                'invoice_id' => $i->id,
                'invoice_number' => $i->invoice_number,
                'tracking_number' => $i->shipment?->tracking_number,
                'shipment_id' => $i->shipment_id,
                'store_name' => $i->shipment?->merchant?->name,
                'store_id' => $i->shipment?->merchant?->external_store_id,
                'delivered_at' => $i->shipment?->delivered_at ? Carbon::parse($i->shipment->delivered_at)->toIso8601String() : null,
                'invoice_date' => $i->invoice_date?->toDateString(),
                'amount' => round((float) $i->total_amount, 2),
                'status' => $i->status,
            ])->all();
    }

    public function presentBill(MarketplaceBill $bill, bool $withLines = false): array
    {
        $bill->loadMissing(['branch:id,name,code', 'marketplace:id,name,code,email']);
        $out = [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'branch_id' => $bill->branch_id,
            'branch_name' => $bill->branch?->name,
            'branch_code' => $bill->branch?->code,
            'marketplace_id' => $bill->marketplace_id,
            'marketplace_name' => $bill->marketplace?->name,
            'marketplace_code' => $bill->marketplace?->code,
            'period_start' => $bill->period_start?->toDateString(),
            'period_end' => $bill->period_end?->toDateString(),
            'total_amount' => round((float) $bill->total_amount, 2),
            'outstanding_amount' => $bill->outstanding(),
            'paid_percent' => (float) $bill->total_amount > 0 ? round(min(100, $bill->paidSoFar() / (float) $bill->total_amount * 100), 1) : 0.0,
            'invoice_count' => (int) $bill->invoice_count,
            'status' => $bill->status,
            'sent_at' => $bill->sent_at?->toIso8601String(),
            'sent_by' => $bill->sent_by,
            'sent_to_email' => $bill->sent_to_email,
            'send_count' => (int) $bill->send_count,
            'paid_at' => $bill->paid_at?->toIso8601String(),
            'paid_by_type' => $bill->paid_by_type,
            'paid_by' => $bill->paid_by,
            'paid_by_label' => $this->payerLabel($bill->paid_by_type, $bill->paid_by),
            'paid_amount' => $bill->paidSoFar(),
            'payment_method' => $bill->payment_method,
            'payment_reference' => $bill->payment_reference,
            'payment_note' => $bill->payment_note,
            'reopened_at' => $bill->reopened_at?->toIso8601String(),
            'reopen_reason' => $bill->reopen_reason,
            'cancelled_at' => $bill->cancelled_at?->toIso8601String(),
            'payment_history' => $bill->payment_history ?? [],
            'days_outstanding' => $bill->isPayable() && $bill->sent_at
                ? (int) $bill->sent_at->diffInDays(now())
                : null,
            'created_at' => $bill->created_at?->toIso8601String(),
        ];
        $notices = app(BillingPaymentNoticeService::class);
        $out['pending_notices'] = [
            'count' => $notices->openQuery('marketplace_bill', (int) $bill->id)->count(),
            'amount' => $notices->pendingSum('marketplace_bill', (int) $bill->id),
        ];
        $lastPush = MarketplaceBillDelivery::query()->where('marketplace_bill_id', $bill->id)->orderByDesc('id')->first();
        $out['last_push'] = $lastPush ? [
            'event' => $lastPush->event, 'status' => $lastPush->status, 'http_status' => $lastPush->http_status,
            'attempts' => $lastPush->attempts, 'last_error' => $lastPush->last_error,
            'at' => ($lastPush->delivered_at ?? $lastPush->last_attempt_at ?? $lastPush->queued_at)?->toIso8601String(),
        ] : null;
        if ($withLines) {
            $out['notices'] = $notices->forTarget('marketplace_bill', (int) $bill->id, true);
            $out['deliveries'] = $this->deliveries($bill);
            $out['push_target'] = $this->pushTarget($bill->marketplace);
            $out['lines'] = $this->billLines($bill);
            $out['bill_to_email'] = $this->billingEmailFor($bill->marketplace);
            $out['how_to_pay'] = $this->paymentInstructions($bill);
            $out['payments'] = $this->payments()->history($bill, true);
            $out['payable'] = $this->payments()->payable($bill);
        }

        return $out;
    }

    /** Marketplace API view: no internal user ids. */
    public function presentForMarketplace(MarketplaceBill $bill, bool $withLines = false): array
    {
        $bill->loadMissing(['branch:id,name,code,email,phone']);
        $status = match ((string) $bill->status) {
            'sent', 'reopened' => 'unpaid',
            default => (string) $bill->status, // partially_paid | paid | cancelled
        };
        $out = [
            'bill_number' => $bill->bill_number,
            'status' => $status,
            'status_detail' => $bill->status,
            'branch' => ['code' => $bill->branch?->code, 'name' => $bill->branch?->name],
            'period_start' => $bill->period_start?->toDateString(),
            'period_end' => $bill->period_end?->toDateString(),
            'currency' => 'NPR',
            'total_amount' => round((float) $bill->total_amount, 2),
            'paid_amount' => $bill->paidSoFar(),
            'outstanding_amount' => $bill->isPayable() ? $bill->outstanding() : 0.0,
            'invoice_count' => (int) $bill->invoice_count,
            'issued_at' => $bill->sent_at?->toIso8601String(),
            'paid_at' => $bill->paid_at?->toIso8601String(),
            'last_payment' => $bill->payment_reference || $bill->payment_method ? [
                'paid_by' => $bill->paid_by_type,
                'method' => $bill->payment_method,
                'reference' => $bill->payment_reference,
            ] : null,
            'reopened_at' => $bill->reopened_at?->toIso8601String(),
            'reopen_reason' => $bill->reopen_reason,
            'payments' => $this->payments()->history($bill, false),
            'payable' => $this->payments()->payable($bill),
            'payment_notices' => app(BillingPaymentNoticeService::class)->forTarget('marketplace_bill', (int) $bill->id, false),
        ];
        if ($withLines) {
            $out['lines'] = array_map(fn ($l) => [
                'tracking_number' => $l['tracking_number'],
                'delivered_at' => $l['delivered_at'],
                'amount' => $l['amount'],
                'branch' => $bill->branch?->name,
                'store_id' => $l['store_id'],
            ], $this->billLines($bill));
            $out['how_to_pay'] = $this->paymentInstructions($bill);
        }

        return $out;
    }

    public function payerLabel(?string $type, $id): ?string
    {
        if (! $type) {
            return null;
        }
        if ($type === 'marketplace' && $id) {
            return (Marketplace::query()->whereKey($id)->value('name') ?? 'Marketplace').' (marketplace)';
        }
        if (in_array($type, ['admin', 'branch', 'merchant'], true) && $id) {
            $name = DB::table('users')->where('id', $id)->value('name');

            return ($name ?: 'User #'.$id).' ('.$type.')';
        }

        return $type === 'hamropay' ? 'HamroPay' : ucfirst($type);
    }

    /** @return array{text: string, hamropay_merchant_id: ?string, contact: array<string, ?string>, reference: string} */
    public function paymentInstructions(MarketplaceBill $bill): array
    {
        $branch = $bill->branch ?: Branch::query()->find($bill->branch_id);
        $account = $this->accounts->branchAccount((int) $bill->branch_id, 'hamropay');
        $merchantId = $account?->credential('merchant_id');
        $name = $branch?->name ?? 'the billing branch';
        $contact = [
            'name' => $name,
            'email' => $branch?->email,
            'phone' => $branch?->phone,
        ];

        $due = $bill->isPayable() ? $bill->outstanding() : round((float) $bill->total_amount, 2);
        $part = $bill->paidSoFar() > 0 ? ' (outstanding of Rs '.number_format((float) $bill->total_amount, 2).'; part payments are accepted)' : ' (part payments are accepted)';
        $text = filled($merchantId)
            ? 'Pay Rs '.number_format($due, 2).$part.' to '.$name.' via HamroPay (merchant ID '.$merchantId.'), or start a HamroPay checkout with the billing API. Use '.$bill->bill_number.' as the payment reference.'
            : 'Pay Rs '.number_format($due, 2).$part.'. Contact '.$name.($branch?->phone ? ' ('.$branch->phone.')' : '').($branch?->email ? ' <'.$branch->email.'>' : '').' for payment details. Use '.$bill->bill_number.' as the payment reference.';

        return [
            'text' => $text,
            'hamropay_merchant_id' => filled($merchantId) ? (string) $merchantId : null,
            'contact' => $contact,
            'reference' => $bill->bill_number,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Email / webhook / notifications
    |--------------------------------------------------------------------------
    */

    public function billEmailHtml(MarketplaceBill $bill, array $lines): string
    {
        $mp = e($bill->marketplace?->name ?? 'Marketplace');
        $branch = e($bill->branch?->name ?? 'Tukaatu Express');
        $pay = $this->paymentInstructions($bill);
        $rows = '';
        foreach ($lines as $i => $l) {
            $date = $l['delivered_at'] ? Carbon::parse($l['delivered_at'])->timezone('Asia/Kathmandu')->format('Y-m-d') : ($l['invoice_date'] ?? '');
            $rows .= '<tr><td style="padding:6px;border:1px solid #ddd">'.($i + 1).'</td>'
                .'<td style="padding:6px;border:1px solid #ddd">'.e((string) $l['tracking_number']).'</td>'
                .'<td style="padding:6px;border:1px solid #ddd">'.e((string) $date).'</td>'
                .'<td style="padding:6px;border:1px solid #ddd;text-align:right">Rs '.number_format((float) $l['amount'], 2).'</td></tr>';
        }
        $period = e(($bill->period_start?->toDateString() ?? '').' to '.($bill->period_end?->toDateString() ?? ''));
        $total = number_format((float) $bill->total_amount, 2);
        $paidSoFar = $bill->paidSoFar();
        $due = number_format($bill->outstanding(), 2);
        $paidRow = $paidSoFar > 0
            ? '<tr><td style="padding:4px 12px 4px 0"><b>Paid so far</b></td><td>Rs '.number_format($paidSoFar, 2).'</td></tr>'
            : '';

        return <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222">
<p>Dear {$mp} billing team,</p>
<p>{$branch} (Tukaatu Express) has delivered the shipments below with <b>free delivery offered by {$mp}</b>. Please pay the delivery charges for this bill.</p>
<table style="border-collapse:collapse;margin:8px 0">
<tr><td style="padding:4px 12px 4px 0"><b>Bill number</b></td><td>{$bill->bill_number}</td></tr>
<tr><td style="padding:4px 12px 4px 0"><b>Billing branch</b></td><td>{$branch}</td></tr>
<tr><td style="padding:4px 12px 4px 0"><b>Period</b></td><td>{$period}</td></tr>
<tr><td style="padding:4px 12px 4px 0"><b>Shipments</b></td><td>{$bill->invoice_count}</td></tr>
<tr><td style="padding:4px 12px 4px 0"><b>Bill total</b></td><td>Rs {$total}</td></tr>
{$paidRow}<tr><td style="padding:4px 12px 4px 0"><b>Amount due</b></td><td><b>Rs {$due}</b></td></tr>
</table>
<table style="border-collapse:collapse;min-width:420px">
<thead><tr style="background:#f3f4f6"><th style="padding:6px;border:1px solid #ddd">#</th><th style="padding:6px;border:1px solid #ddd">Tracking</th><th style="padding:6px;border:1px solid #ddd">Delivered</th><th style="padding:6px;border:1px solid #ddd">Amount</th></tr></thead>
<tbody>{$rows}</tbody>
<tfoot><tr><td colspan="3" style="padding:6px;border:1px solid #ddd;text-align:right"><b>Total</b></td><td style="padding:6px;border:1px solid #ddd;text-align:right"><b>Rs {$total}</b></td></tr></tfoot>
</table>
<p><b>How to pay:</b> {$this->escape($pay['text'])}</p>
<p>Please use the bill number <b>{$bill->bill_number}</b> as the payment reference. You can pay the full amount or in parts. GET /api/v1/marketplace/billing/bills/{$bill->bill_number}/payable returns the amount due; after paying, record it with POST /api/v1/marketplace/billing/bills/{$bill->bill_number}/pay.</p>
<p style="color:#666;font-size:12px">This bill covers delivery charges only. It is separate from POD cash and HQ commission.</p>
</div>
HTML;
    }

    private function escape(string $v): string
    {
        return e($v);
    }

    /** Queue a signed billing webhook to the marketplace callback_url (if set). */
    public function webhook(MarketplaceBill $bill, string $event, ?array $lines = null, array $extra = []): bool
    {
        $bill->loadMissing(['marketplace', 'branch']);
        $push = $this->pushTarget($bill->marketplace);
        $eventId = 'evt_'.Str::lower(Str::random(32));

        // Every push is tracked (also the ones that cannot be sent) so the branch
        // sees in admin whether the marketplace actually received the bill.
        $delivery = null;
        try {
            $delivery = MarketplaceBillDelivery::create([
                'marketplace_bill_id' => $bill->id,
                'marketplace_id' => (int) $bill->marketplace_id,
                'event' => $event,
                'event_id' => $eventId,
                'url' => $push['url'] !== '' ? mb_substr($push['url'], 0, 500) : null,
                'status' => $push['ready'] ? 'queued' : 'skipped',
                'last_error' => $push['problem'],
                'queued_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::info('marketplace_bill.delivery_row_skipped', ['error' => $e->getMessage()]);
        }
        if (! $push['ready']) {
            return false;
        }

        $payload = [
            'event_id' => $eventId,
            'event' => $event,
            'occurred_at' => now()->toIso8601String(),
            'bill' => $this->presentForMarketplace($bill, false),
        ] + $extra;
        if ($lines !== null) {
            $payload['bill']['lines'] = array_map(fn ($l) => [
                'tracking_number' => $l['tracking_number'] ?? null,
                'invoice_number' => $l['invoice_number'] ?? null,
                'delivered_at' => $l['delivered_at'] ?? null,
                'invoice_date' => $l['invoice_date'] ?? null,
                'store_id' => $l['store_id'] ?? null,
                'amount' => $l['amount'] ?? null,
                'branch' => $bill->branch?->name,
            ], $lines);
            $payload['bill']['how_to_pay'] = $this->paymentInstructions($bill);
        }

        try {
            SendMarketplaceBillingWebhook::dispatch((int) $bill->marketplace_id, $payload, $delivery?->id);

            return true;
        } catch (Throwable $e) {
            Log::warning('marketplace_bill.webhook_dispatch_failed', ['bill' => $bill->bill_number, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Where billing pushes go: marketplaces.callback_url signed with callback_secret. */
    public function pushTarget(?Marketplace $marketplace): array
    {
        $url = trim((string) ($marketplace?->callback_url ?? ''));
        $secret = '';
        try {
            $secret = (string) ($marketplace?->callback_secret ?? '');
        } catch (Throwable) {
            $secret = '';
        }
        $problem = $url === '' ? 'No callback URL is set on the marketplace' : ($secret === '' ? 'No callback secret is set on the marketplace (or it cannot be decrypted)' : null);

        return ['url' => $url, 'ready' => $problem === null, 'problem' => $problem];
    }

    /** @return list<array<string, mixed>> */
    public function deliveries(MarketplaceBill $bill, int $limit = 20): array
    {
        try {
            return MarketplaceBillDelivery::query()->where('marketplace_bill_id', $bill->id)->orderByDesc('id')->limit($limit)->get()
                ->map(fn (MarketplaceBillDelivery $d) => [
                    'id' => $d->id,
                    'event' => $d->event,
                    'event_id' => $d->event_id,
                    'url' => $d->url,
                    'status' => $d->status,
                    'attempts' => $d->attempts,
                    'http_status' => $d->http_status,
                    'last_error' => $d->last_error,
                    'queued_at' => $d->queued_at?->toIso8601String(),
                    'last_attempt_at' => $d->last_attempt_at?->toIso8601String(),
                    'delivered_at' => $d->delivered_at?->toIso8601String(),
                ])->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function emailMarketplaceNotice(MarketplaceBill $bill, string $subject, string $html): void
    {
        $bill->loadMissing('marketplace', 'branch');
        $email = $this->billingEmailFor($bill->marketplace);
        if (! $email) {
            return;
        }
        try {
            Mail::html('<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px">'.$html.'</div>', function ($m) use ($email, $subject) {
                $m->to($email)->subject($subject);
            });
            $this->logEmail($email, $subject, $html);
        } catch (Throwable $e) {
            Log::warning('marketplace_bill.notice_email_failed', ['bill' => $bill->bill_number, 'error' => $e->getMessage()]);
        }
    }

    public function logEmail(string $to, string $subject, string $body): void
    {
        try {
            if (class_exists(EmailLog::class)) {
                EmailLog::create([
                    'to_email' => mb_substr($to, 0, 191),
                    'subject' => $subject,
                    'body' => $body,
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            Log::info('marketplace_bill.email_log_skipped', ['error' => $e->getMessage()]);
        }
    }
}
