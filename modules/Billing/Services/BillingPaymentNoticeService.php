<?php

namespace Modules\Billing\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\BillingPaymentNotice;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\MarketplaceBill;
use Modules\Branch\Models\Branch;
use Modules\Setting\Models\Marketplace;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Payment notices: "we paid" from a marketplace (billing API) or a merchant
 * (portal). Rule: a notice NEVER changes paid / outstanding. The billing branch
 * manager verifies it (records the payment through the payment service, amount
 * editable, partial allowed) or rejects it with a reason. The only automatic
 * path is HamroPay: a transaction the provider confirms is recorded at once.
 *
 * Allocation for "pay all" (merchants): the amount is spread over the payer's
 * open invoices OLDEST FIRST (invoice_date, then id), optionally for one branch.
 * Each invoice gets its own notice; all share one batch_reference.
 * What can still be claimed on an item = outstanding - open notices on it, so
 * two notices can never claim the same money.
 */
class BillingPaymentNoticeService
{
    private const EPS = 0.004;

    /** HamroPay checkouts that were never paid stop blocking the amount after this. */
    private const GATEWAY_HOLD_MINUTES = 30;

    public function __construct(
        private MarketplaceBillingService $billing,
        private BillingNotifier $notifier,
    ) {
    }

    private function mpPayments(): MarketplaceBillPaymentService
    {
        return app(MarketplaceBillPaymentService::class);
    }

    private function invPayments(): MerchantInvoicePaymentService
    {
        return app(MerchantInvoicePaymentService::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Amounts
    |--------------------------------------------------------------------------
    */

    public function openQuery(string $targetType, int $targetId): Builder
    {
        return BillingPaymentNotice::query()
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where(function ($q) {
                $q->where('status', 'pending_verification')
                    ->orWhere(function ($g) {
                        $g->where('status', 'awaiting_gateway')
                            ->where('created_at', '>=', now()->subMinutes(self::GATEWAY_HOLD_MINUTES));
                    });
            });
    }

    public function pendingSum(string $targetType, int $targetId): float
    {
        return round((float) $this->openQuery($targetType, $targetId)->sum('amount'), 2);
    }

    public function outstandingOf(string $targetType, Model $target): float
    {
        if ($targetType === 'marketplace_bill') {
            /** @var MarketplaceBill $target */
            return $target->isPayable() ? $target->outstanding() : 0.0;
        }

        /** @var Invoice $target */
        return $this->invPayments()->outstanding($target);
    }

    public function claimable(string $targetType, Model $target): float
    {
        return max(0.0, round($this->outstandingOf($targetType, $target) - $this->pendingSum($targetType, (int) $target->getKey()), 2));
    }

    /** Merchant delivery-charge invoices with money due, oldest first. */
    public function openMerchantInvoicesQuery(int $merchantId, ?int $branchId = null): Builder
    {
        return Invoice::query()
            ->where('merchant_id', $merchantId)
            ->where('type', 'delivery_charges')
            ->where(fn ($q) => $q->where('payer_type', 'merchant')->orWhereNull('payer_type'))
            ->whereIn('status', MerchantInvoicePaymentService::OPEN_STATUSES)
            ->whereNull('marketplace_bill_id')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('invoice_date')
            ->orderBy('id');
    }

    /**
     * Oldest-first allocation. $amount null = everything that can be claimed.
     *
     * @param  iterable<Model>  $items  already ordered oldest first
     * @return array{total_due: float, amount: float, allocations: list<array{item: Model, due_before: float, amount: float, due_after: float}>}
     */
    public function allocate(iterable $items, string $targetType, $amount): array
    {
        $rows = [];
        $total = 0.0;
        foreach ($items as $item) {
            $c = $this->claimable($targetType, $item);
            if ($c > self::EPS) {
                $rows[] = [$item, $c];
                $total = round($total + $c, 2);
            }
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['amount' => ['Nothing is due (or every due amount already has a payment notice waiting for verification).']]);
        }
        $value = ($amount === null || $amount === '') ? $total : round((float) $amount, 2);
        if (! is_finite($value) || $value <= 0) {
            throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
        }
        if ($value - $total > self::EPS) {
            throw ValidationException::withMessages(['amount' => ['Amount Rs '.number_format($value, 2).' is more than the total due Rs '.number_format($total, 2).'.']]);
        }

        $remaining = $value;
        $allocations = [];
        foreach ($rows as [$item, $c]) {
            if ($remaining <= self::EPS) {
                break;
            }
            $take = round(min($remaining, $c), 2);
            $allocations[] = ['item' => $item, 'due_before' => $c, 'amount' => $take, 'due_after' => round($c - $take, 2)];
            $remaining = round($remaining - $take, 2);
        }

        return ['total_due' => $total, 'amount' => $value, 'allocations' => $allocations];
    }

    /** Allocation as JSON: lines + per-branch totals (each branch is paid separately). */
    public function presentAllocation(array $alloc): array
    {
        $branchNames = Branch::query()->whereIn('id', collect($alloc['allocations'])->map(fn ($a) => $a['item']->branch_id)->filter()->unique())->pluck('name', 'id');
        $lines = [];
        $byBranch = [];
        foreach ($alloc['allocations'] as $a) {
            $item = $a['item'];
            $isBill = $item instanceof MarketplaceBill;
            $bid = (int) $item->branch_id;
            $lines[] = [
                'id' => $item->getKey(),
                'number' => $isBill ? $item->bill_number : $item->invoice_number,
                'date' => $isBill ? $item->sent_at?->toDateString() : $item->invoice_date?->toDateString(),
                'branch_id' => $bid,
                'branch_name' => $branchNames[$bid] ?? null,
                'due_before' => $a['due_before'],
                'amount' => $a['amount'],
                'due_after' => $a['due_after'],
            ];
            $byBranch[$bid] ??= ['branch_id' => $bid, 'branch_name' => $branchNames[$bid] ?? null, 'count' => 0, 'amount' => 0.0];
            $byBranch[$bid]['count']++;
            $byBranch[$bid]['amount'] = round($byBranch[$bid]['amount'] + $a['amount'], 2);
        }

        return [
            'currency' => 'NPR',
            'rule' => 'oldest_first',
            'total_due' => $alloc['total_due'],
            'amount' => $alloc['amount'],
            'remaining_after' => round($alloc['total_due'] - $alloc['amount'], 2),
            'lines' => $lines,
            'by_branch' => array_values($byBranch),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Submit
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array{type: string, id: ?int}  $submittedBy  marketplace | user | system
     * @return array{notice: BillingPaymentNotice, replay: bool}
     */
    public function submit(
        string $targetType,
        Model $target,
        $amount,
        ?string $method,
        ?string $reference,
        $paidAt,
        ?string $note,
        string $source,
        array $submittedBy,
        ?string $requestId = null,
        ?string $batch = null,
        string $status = 'pending_verification',
        ?string $gatewayTxn = null,
        bool $notify = true,
    ): array {
        $requestId = $requestId !== null && trim($requestId) !== '' ? mb_substr(trim($requestId), 0, 191) : null;
        if ($requestId !== null && ($existing = BillingPaymentNotice::query()->where('request_id', $requestId)->first())) {
            if ($existing->target_type !== $targetType || (int) $existing->target_id !== (int) $target->getKey()) {
                throw new HttpException(409, 'This request id was already used for another bill.');
            }

            return ['notice' => $existing, 'replay' => true];
        }

        $notice = DB::transaction(function () use ($targetType, $target, $amount, $method, $reference, $paidAt, $note, $source, $submittedBy, $requestId, $batch, $status, $gatewayTxn) {
            if ($targetType === 'marketplace_bill') {
                /** @var MarketplaceBill $locked */
                $locked = MarketplaceBill::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
                if ($locked->isPaid()) {
                    throw new HttpException(409, 'Bill '.$locked->bill_number.' is already fully paid.');
                }
                if (! $locked->isPayable()) {
                    throw ValidationException::withMessages(['bill' => ['Bill '.$locked->bill_number.' is not open for payment.']]);
                }
                $payerType = 'marketplace';
                $payerId = (int) $locked->marketplace_id;
                $number = $locked->bill_number;
            } else {
                /** @var Invoice $locked */
                $locked = Invoice::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
                $this->invPayments()->assertPayable($locked);
                $payerType = 'merchant';
                $payerId = (int) $locked->merchant_id;
                $number = $locked->invoice_number;
            }

            $outstanding = $this->outstandingOf($targetType, $locked);
            $pending = $this->pendingSum($targetType, (int) $locked->getKey());
            $claimable = max(0.0, round($outstanding - $pending, 2));
            $value = ($amount === null || $amount === '') ? $claimable : round((float) $amount, 2);
            if (! is_finite($value) || $value <= 0) {
                throw ValidationException::withMessages(['amount' => [$claimable <= 0 && $pending > 0
                    ? 'Rs '.number_format($pending, 2).' is already waiting for verification on '.$number.'; nothing more can be claimed.'
                    : 'Amount must be greater than zero.']]);
            }
            if ($value - $claimable > self::EPS) {
                throw ValidationException::withMessages(['amount' => [
                    'Amount exceeds what is due on '.$number.': Rs '.number_format($claimable, 2)
                    .($pending > 0 ? ' (outstanding Rs '.number_format($outstanding, 2).' minus Rs '.number_format($pending, 2).' waiting for verification)' : '')
                    .'; received Rs '.number_format($value, 2).'.',
                ]]);
            }
            $when = $paidAt ? Carbon::parse($paidAt) : now();
            if ($when->isFuture()) {
                $when = now();
            }

            return BillingPaymentNotice::create([
                'payer_type' => $payerType,
                'payer_id' => $payerId,
                'target_type' => $targetType,
                'target_id' => $locked->getKey(),
                'branch_id' => $locked->branch_id,
                'amount' => $value,
                'method' => $method !== null ? mb_substr($method, 0, 64) : null,
                'reference' => $reference !== null ? mb_substr($reference, 0, 191) : null,
                'paid_at' => $when,
                'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
                'status' => $status,
                'source' => $source,
                'batch_reference' => $batch,
                'request_id' => $requestId,
                'submitted_by_type' => $submittedBy['type'] ?? null,
                'submitted_by' => $submittedBy['id'] ?? null,
                'gateway_txn_id' => $gatewayTxn,
            ]);
        });

        if ($notify) {
            $this->afterSubmit($notice);
        }

        return ['notice' => $notice, 'replay' => false];
    }

    /**
     * Merchant "pay all": one notice per invoice (oldest first), shared batch reference.
     *
     * @return array<string, mixed>
     */
    public function submitMerchantPayAll(int $merchantId, $amount, ?int $branchId, ?string $method, ?string $reference, $paidAt, ?string $note, array $submittedBy, ?string $requestId = null): array
    {
        $requestId = $requestId !== null && trim($requestId) !== '' ? mb_substr(trim($requestId), 0, 150) : null;
        if ($requestId !== null) {
            $existing = BillingPaymentNotice::query()->where('request_id', 'like', $requestId.'#%')->orderBy('id')->get();
            if ($existing->isNotEmpty()) {
                if ($existing->contains(fn ($n) => $n->payer_type !== 'merchant' || (int) $n->payer_id !== $merchantId)) {
                    throw new HttpException(409, 'This request id was already used.');
                }

                return [
                    'replay' => true,
                    'batch_reference' => $existing->first()->batch_reference,
                    'amount' => round((float) $existing->sum('amount'), 2),
                    'notices' => $existing->map(fn ($n) => $this->present($n, false))->values()->all(),
                ];
            }
        }

        $batch = 'MPN-'.now()->format('ymd').'-'.Str::upper(Str::random(6));
        [$alloc, $notices] = DB::transaction(function () use ($merchantId, $amount, $branchId, $method, $reference, $paidAt, $note, $submittedBy, $requestId, $batch) {
            $invoices = $this->openMerchantInvoicesQuery($merchantId, $branchId)->lockForUpdate()->get();
            $alloc = $this->allocate($invoices, 'invoice', $amount);
            $notices = [];
            foreach ($alloc['allocations'] as $a) {
                $notices[] = $this->submit(
                    'invoice', $a['item'], $a['amount'], $method, $reference ?: $batch, $paidAt, $note,
                    'merchant_portal', $submittedBy, $requestId !== null ? $requestId.'#'.$a['item']->id : null, $batch,
                    'pending_verification', null, false
                )['notice'];
            }

            return [$alloc, $notices];
        });

        // One notification per branch (each branch verifies its own invoices).
        foreach (collect($notices)->groupBy('branch_id') as $bid => $group) {
            $sum = round((float) $group->sum('amount'), 2);
            $store = DB::table('merchants')->where('id', $merchantId)->value('name') ?? 'Store #'.$merchantId;
            $subject = 'Verify payment notice Rs '.number_format($sum, 2).' from '.$store.' ('.$group->count().' invoice(s), '.$batch.')';
            $message = $store.' reports paying Rs '.number_format($sum, 2).' for '.$group->count().' delivery-charge invoice(s): '
                .$group->map(fn ($n) => $this->targetNumber($n).' Rs '.number_format((float) $n->amount, 2))->implode(', ')
                .'. Method '.($method ?? '-').', reference '.($reference ?: $batch).'. Nothing is marked paid until you verify it: open Finance -> Delivery charges -> Payment notices.';
            $this->notifier->notifyBranch((int) $bid, 'billing.payment_notice.received', $subject, $message, ['batch_reference' => $batch, 'notice_ids' => $group->pluck('id')->all()], true, $merchantId);
        }

        return [
            'replay' => false,
            'batch_reference' => $batch,
            'allocation' => $this->presentAllocation($alloc),
            'amount' => $alloc['amount'],
            'notices' => collect($notices)->map(fn ($n) => $this->present($n, false))->values()->all(),
        ];
    }

    private function afterSubmit(BillingPaymentNotice $n): void
    {
        $number = $this->targetNumber($n);
        $payer = $this->payerName($n);
        $amount = number_format((float) $n->amount, 2);
        $where = $n->target_type === 'marketplace_bill' ? 'Finance -> Marketplace bills' : 'Finance -> Delivery charges -> Payment notices';
        $subject = 'Verify payment notice Rs '.$amount.' from '.$payer.' for '.$number;
        $message = $payer.' reports paying Rs '.$amount.' on '.$number.' via '.($n->method ?? '-').', reference '.($n->reference ?? '-')
            .', paid on '.($n->paid_at?->toDateString() ?? '-').'. Nothing is marked paid until you check the money arrived and verify it in '.$where.'.';
        $this->notifier->notifyBranch((int) $n->branch_id, 'billing.payment_notice.received', $subject, $message, [
            'notice_id' => $n->id, 'target_type' => $n->target_type, 'target_id' => $n->target_id, 'amount' => (float) $n->amount,
        ], true, $n->payer_type === 'merchant' ? (int) $n->payer_id : null);

        if ($n->target_type === 'marketplace_bill' && ($bill = MarketplaceBill::query()->find($n->target_id))) {
            $this->billing->webhook($bill, 'billing.payment_notice.received', null, ['notice' => $this->present($n, false)]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Verify / reject (billing branch)
    |--------------------------------------------------------------------------
    */

    /** @return array{notice: BillingPaymentNotice, payment: Model, target: Model, fully_paid: bool, replay: bool} */
    public function verify(BillingPaymentNotice $notice, $amount, ?string $method, ?string $reference, $paidAt, ?string $note, $user): array
    {
        $actorType = MarketplaceBillingService::actorType($user) === 'admin' ? 'admin' : 'branch';
        $result = DB::transaction(function () use ($notice, $amount, $method, $reference, $paidAt, $note, $user, $actorType) {
            /** @var BillingPaymentNotice $n */
            $n = BillingPaymentNotice::query()->whereKey($notice->id)->lockForUpdate()->firstOrFail();
            if (! $n->isPending()) {
                throw ValidationException::withMessages(['notice' => ['This payment notice is already '.str_replace('_', ' ', (string) $n->status).'.']]);
            }
            $value = ($amount === null || $amount === '') ? (float) $n->amount : $amount;
            $actor = ['type' => $actorType, 'id' => $user?->id];
            $noteText = $note !== null && $note !== '' ? $note : 'Verified payment notice #'.$n->id;
            if ($n->target_type === 'marketplace_bill') {
                $bill = MarketplaceBill::query()->findOrFail($n->target_id);
                $r = $this->mpPayments()->record($bill, $value, $method ?: $n->method, $reference ?: $n->reference, 'notice_verified', $actor, 'notice:'.$n->id, $paidAt ?: $n->paid_at, $noteText, $n->id);
                $target = $r['bill'];
            } else {
                $inv = Invoice::query()->findOrFail($n->target_id);
                $r = $this->invPayments()->record($inv, $value, $method ?: $n->method, $reference ?: $n->reference, 'notice_verified', $actor, 'notice:'.$n->id, $paidAt ?: $n->paid_at, $noteText, $n->batch_reference, $n->id);
                $target = $r['invoice'];
            }
            $n->forceFill([
                'status' => 'verified',
                'verified_amount' => round((float) $r['payment']->amount, 2),
                'verified_by' => $user?->id,
                'verified_at' => now(),
                'payment_id' => $r['payment']->id,
            ])->save();

            return ['notice' => $n->fresh(), 'payment' => $r['payment'], 'target' => $target, 'fully_paid' => $r['fully_paid'], 'replay' => $r['replay']];
        });

        $n = $result['notice'];
        if ($n->target_type === 'marketplace_bill') {
            $this->billing->webhook($result['target'], 'billing.payment_notice.verified', null, ['notice' => $this->present($n, false)]);
        } else {
            $this->notifier->notifyMerchant((int) $n->payer_id, 'billing.payment_notice.verified',
                'Payment Rs '.number_format((float) $n->verified_amount, 2).' verified for '.$this->targetNumber($n),
                'Your payment notice for '.$this->targetNumber($n).' was verified by the branch. Rs '.number_format((float) $n->verified_amount, 2).' is recorded as paid.',
                ['notice_id' => $n->id], false);
        }

        return $result;
    }

    public function reject(BillingPaymentNotice $notice, string $reason, $user): BillingPaymentNotice
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => ['Give a reason for rejecting this payment notice.']]);
        }
        $n = DB::transaction(function () use ($notice, $reason, $user) {
            $n = BillingPaymentNotice::query()->whereKey($notice->id)->lockForUpdate()->firstOrFail();
            if (! $n->isPending()) {
                throw ValidationException::withMessages(['notice' => ['This payment notice is already '.str_replace('_', ' ', (string) $n->status).'.']]);
            }
            $n->forceFill(['status' => 'rejected', 'rejected_by' => $user?->id, 'rejected_at' => now(), 'reject_reason' => mb_substr($reason, 0, 500)])->save();

            return $n->fresh();
        });

        $number = $this->targetNumber($n);
        $text = 'Your payment notice of Rs '.number_format((float) $n->amount, 2).' (reference '.($n->reference ?? '-').') for '.$number
            .' was rejected by the branch: '.$reason.'. Nothing was recorded as paid. Please check the payment and send a new notice.';
        if ($n->target_type === 'marketplace_bill' && ($bill = MarketplaceBill::query()->find($n->target_id))) {
            $this->billing->webhook($bill, 'billing.payment_notice.rejected', null, ['notice' => $this->present($n, false)]);
            $this->billing->emailMarketplaceNotice($bill, 'Payment notice rejected for bill '.$number, '<p>'.e($text).'</p>');
        } else {
            $this->notifier->notifyMerchant((int) $n->payer_id, 'billing.payment_notice.rejected', 'Payment notice rejected for '.$number, $text, ['notice_id' => $n->id]);
        }

        return $n;
    }

    /*
    |--------------------------------------------------------------------------
    | HamroPay (merchant) - provider-verified, recorded automatically
    |--------------------------------------------------------------------------
    */

    /**
     * One HamroPay checkout per billing branch (each branch has its own receiving
     * account). $invoice set = that invoice only; otherwise oldest-first over all
     * open invoices (optionally one branch).
     *
     * @return array{amount: float, allocation: array, sessions: list<array<string, mixed>>}
     */
    public function startMerchantHamroPay(int $merchantId, ?Invoice $invoice, $amount, ?int $branchId, array $submittedBy): array
    {
        $items = $invoice ? collect([$invoice]) : $this->openMerchantInvoicesQuery($merchantId, $branchId)->get();
        $alloc = $this->allocate($items, 'invoice', $amount);
        $marketplaceId = DB::table('merchants')->where('id', $merchantId)->value('marketplace_id');
        $marketplace = $marketplaceId ? Marketplace::query()->find($marketplaceId) : null;

        $sessions = [];
        foreach (collect($alloc['allocations'])->groupBy(fn ($a) => (int) $a['item']->branch_id) as $bid => $group) {
            $sum = round((float) $group->sum('amount'), 2);
            $branchName = Branch::query()->whereKey($bid)->value('name');
            $row = ['branch_id' => (int) $bid, 'branch_name' => $branchName, 'amount' => $sum,
                'invoices' => $group->map(fn ($a) => ['invoice_number' => $a['item']->invoice_number, 'amount' => $a['amount']])->values()->all()];
            try {
                [$client, $gwMerchantId] = $this->billing->branchHamroPayClient((int) $bid, $marketplace);
                $txn = 'MIPAY-'.$merchantId.'-'.Str::upper(Str::random(10));
                $paisa = (int) round($sum * 100);
                $remarks = 'Delivery charges, '.$group->count().' invoice(s)';
                $session = $client->createSession([
                    'merchantTxnId' => $txn,
                    'transactionAmount' => $paisa,
                    'remarks' => $remarks,
                    'metadata' => ['merchant_id' => $merchantId, 'invoices' => $group->map(fn ($a) => $a['item']->invoice_number)->values()->all()],
                ], (string) $gwMerchantId, null);
                $sessionId = data_get($session, 'sessionId') ?? data_get($session, 'data.sessionId') ?? data_get($session, 'session_id');
                if (! $sessionId) {
                    throw ValidationException::withMessages(['hamropay' => [data_get($session, 'message', 'HamroPay did not return a session id.')]]);
                }
                DB::transaction(function () use ($group, $txn, $submittedBy) {
                    foreach ($group as $a) {
                        $this->submit('invoice', $a['item'], $a['amount'], 'hamropay', $txn, null, 'HamroPay checkout', 'hamropay', $submittedBy, null, $txn, 'awaiting_gateway', $txn, false);
                    }
                });
                $sessions[] = $row + [
                    'merchant_txn_id' => $txn,
                    'session_id' => (string) $sessionId,
                    'payee' => 'branch',
                    'receiving_merchant_id' => (string) $gwMerchantId,
                    'gateway_url' => $client->getGatewayUrl(),
                    'checkout' => [
                        'url' => $client->getGatewayUrl(),
                        'params' => $client->buildCheckoutParams((string) $sessionId, $txn, $paisa, $remarks, (string) $gwMerchantId),
                    ],
                ];
            } catch (ValidationException $e) {
                $sessions[] = $row + ['error' => collect($e->errors())->flatten()->first()];
            } catch (Throwable $e) {
                Log::warning('hamropay.merchant_invoice_session_failed', ['branch_id' => $bid, 'error' => $e->getMessage()]);
                $sessions[] = $row + ['error' => 'HamroPay checkout failed: '.$e->getMessage()];
            }
        }

        return ['amount' => $alloc['amount'], 'allocation' => $this->presentAllocation($alloc), 'sessions' => $sessions];
    }

    /** @return array<string, mixed> */
    public function confirmMerchantHamroPay(string $txn, ?int $merchantId = null): array
    {
        $txn = trim($txn);
        $notices = BillingPaymentNotice::query()->where('gateway_txn_id', $txn)->where('source', 'hamropay')->orderBy('id')->get();
        abort_if($notices->isEmpty(), 404, 'Unknown HamroPay transaction.');
        abort_if($merchantId !== null && $notices->contains(fn ($n) => (int) $n->payer_id !== $merchantId), 404, 'Unknown HamroPay transaction.');

        $awaiting = $notices->where('status', 'awaiting_gateway');
        if ($awaiting->isEmpty()) {
            return ['paid' => true, 'replay' => true, 'status' => 'already_recorded', 'notices' => $notices->map(fn ($n) => $this->present($n, false))->values()->all()];
        }
        $branchId = (int) $awaiting->first()->branch_id;
        $marketplaceId = DB::table('merchants')->where('id', $awaiting->first()->payer_id)->value('marketplace_id');
        [$client, $gwMerchantId] = $this->billing->branchHamroPayClient($branchId, $marketplaceId ? Marketplace::query()->find($marketplaceId) : null);
        $tx = $client->getTransaction($txn, (string) $gwMerchantId);
        $status = strtolower((string) (data_get($tx, 'status') ?? data_get($tx, 'transactionStatus') ?? data_get($tx, 'data.status') ?? ''));
        $paid = in_array($status, ['success', 'successful', 'paid', 'completed', 'complete'], true) || data_get($tx, 'success') === true;
        if (! $paid) {
            return ['paid' => false, 'status' => $status];
        }
        $expected = round((float) $awaiting->sum('amount'), 2);
        $providerPaisa = data_get($tx, 'transactionAmount') ?? data_get($tx, 'data.transactionAmount');
        $providerAmount = is_numeric($providerPaisa) ? round(((float) $providerPaisa) / 100, 2) : null;
        if ($providerAmount !== null && abs($providerAmount - $expected) > self::EPS) {
            throw ValidationException::withMessages(['merchant_txn_id' => ['HamroPay amount Rs '.number_format($providerAmount, 2).' does not match the checkout amount Rs '.number_format($expected, 2).'. Record the payment manually.']]);
        }

        $applied = 0.0;
        $unapplied = 0.0;
        foreach ($awaiting as $n) {
            $inv = Invoice::query()->find($n->target_id);
            $due = $inv ? $this->invPayments()->outstanding($inv) : 0.0;
            $apply = round(min((float) $n->amount, $due), 2);
            $payment = null;
            if ($apply > self::EPS) {
                $r = $this->invPayments()->record($inv, $apply, 'hamropay', $txn, 'hamropay', ['type' => 'system', 'id' => null], 'hamropay:'.$txn.':'.$inv->id, null, 'Confirmed by HamroPay', $txn, $n->id);
                $payment = $r['payment'];
                $applied = round($applied + (float) $payment->amount, 2);
            }
            $left = round((float) $n->amount - $apply, 2);
            $unapplied = round($unapplied + max(0, $left), 2);
            $n->forceFill([
                'status' => 'verified',
                'verified_amount' => $apply,
                'verified_at' => now(),
                'payment_id' => $payment?->id,
                'note' => $left > self::EPS ? mb_substr(trim(($n->note ?? '').' Rs '.number_format($left, 2).' not applied: invoice was already paid.'), 0, 500) : $n->note,
            ])->save();
        }

        $subject = 'HamroPay payment Rs '.number_format($expected, 2).' received ('.$txn.')';
        $message = 'HamroPay confirmed '.$txn.': Rs '.number_format($applied, 2).' recorded on '.$awaiting->count().' invoice(s).'
            .($unapplied > 0 ? ' Rs '.number_format($unapplied, 2).' could not be applied (those invoices were paid meanwhile): refund or apply it manually.' : '');
        $this->notifier->notifyBranch($branchId, 'merchant_invoice.hamropay_confirmed', $subject, $message, ['txn' => $txn, 'applied' => $applied, 'unapplied' => $unapplied], $unapplied > 0, (int) $awaiting->first()->payer_id);

        return ['paid' => true, 'replay' => false, 'status' => $status, 'applied' => $applied, 'unapplied' => $unapplied,
            'notices' => BillingPaymentNotice::query()->where('gateway_txn_id', $txn)->get()->map(fn ($n) => $this->present($n, false))->values()->all()];
    }

    /*
    |--------------------------------------------------------------------------
    | Presenters
    |--------------------------------------------------------------------------
    */

    public function present(BillingPaymentNotice $n, bool $internal = true): array
    {
        $row = [
            'id' => $n->id,
            'notice_id' => $n->id,
            'target_type' => $n->target_type,
            'target_id' => $n->target_id,
            'number' => $this->targetNumber($n),
            'branch_id' => $n->branch_id,
            'branch_name' => $n->branch_id ? Branch::query()->whereKey($n->branch_id)->value('name') : null,
            'payer_type' => $n->payer_type,
            'payer_name' => $this->payerName($n),
            'amount' => round((float) $n->amount, 2),
            'method' => $n->method,
            'reference' => $n->reference,
            'paid_at' => $n->paid_at?->toIso8601String(),
            'note' => $n->note,
            'status' => $n->status,
            'source' => $n->source,
            'batch_reference' => $n->batch_reference,
            'gateway_txn_id' => $n->gateway_txn_id,
            'submitted_at' => $n->created_at?->toIso8601String(),
            'verified_amount' => $n->verified_amount !== null ? round((float) $n->verified_amount, 2) : null,
            'verified_at' => $n->verified_at?->toIso8601String(),
            'rejected_at' => $n->rejected_at?->toIso8601String(),
            'reject_reason' => $n->reject_reason,
        ];
        if ($internal) {
            $row['payment_id'] = $n->payment_id;
            $row['verified_by_label'] = $n->verified_by ? $this->billing->payerLabel('admin', $n->verified_by) : null;
            $row['rejected_by_label'] = $n->rejected_by ? $this->billing->payerLabel('admin', $n->rejected_by) : null;
            $row['submitted_by_label'] = $n->submitted_by_type === 'marketplace' ? $this->payerName($n).' (API)' : ($n->submitted_by ? $this->billing->payerLabel('merchant', $n->submitted_by) : $n->submitted_by_type);
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function forTarget(string $targetType, int $targetId, bool $internal = true): array
    {
        return BillingPaymentNotice::query()->where('target_type', $targetType)->where('target_id', $targetId)
            ->orderByDesc('id')->get()->map(fn ($n) => $this->present($n, $internal))->values()->all();
    }

    public function targetNumber(BillingPaymentNotice $n): ?string
    {
        return $n->target_type === 'marketplace_bill'
            ? MarketplaceBill::query()->whereKey($n->target_id)->value('bill_number')
            : Invoice::query()->whereKey($n->target_id)->value('invoice_number');
    }

    public function payerName(BillingPaymentNotice $n): string
    {
        return $n->payer_type === 'marketplace'
            ? (Marketplace::query()->whereKey($n->payer_id)->value('name') ?? 'Marketplace #'.$n->payer_id)
            : (DB::table('merchants')->where('id', $n->payer_id)->value('name') ?? 'Store #'.$n->payer_id);
    }
}