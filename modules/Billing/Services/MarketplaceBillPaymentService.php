<?php

namespace Modules\Billing\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\InterBranchStatementLine;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\MarketplaceBill;
use Modules\Billing\Models\MarketplaceBillPayment;
use Modules\Notification\Models\NotificationLog;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The ONLY place a marketplace bill payment is recorded or reversed.
 * Used by branch / HQ "Record payment", branch verification of a marketplace
 * payment notice (source notice_verified) and HamroPay confirm. The marketplace
 * itself never records a payment: its API calls create payment notices.
 *
 * Rules
 *  - amount > 0, rounded to 2 decimals, and <= outstanding (422 otherwise).
 *  - bill must be sent / partially_paid / reopened (paid -> 409, draft or cancelled -> 422).
 *  - DB transaction + lockForUpdate on the bill row: two concurrent payments
 *    can never take the bill past its total.
 *  - Idempotent by request_id: the same request_id returns the same payment
 *    (replay = true) and is never counted twice.
 *  - After every change: paid_amount = SUM(recorded payments),
 *    outstanding_amount = total - paid, status sent|reopened -> partially_paid -> paid.
 *  - Only a FULLY paid bill marks its invoices paid (that is what releases the
 *    inter-branch shares, BranchShareService::eligibleQuery). A partially paid
 *    bill keeps its invoices unpaid.
 */
class MarketplaceBillPaymentService
{
    private const EPS = 0.004;

    public function __construct(private MarketplaceBillingService $billing)
    {
    }

    /**
     * @param  float|string|null  $amount  null = pay the full outstanding amount
     * @param  array{type: string, id: ?int}  $actor  type: user | marketplace | system
     * @return array{payment: MarketplaceBillPayment, bill: MarketplaceBill, replay: bool, fully_paid: bool, became_paid: bool}
     */
    public function record(
        MarketplaceBill $bill,
        $amount,
        ?string $method,
        ?string $reference,
        string $source,
        array $actor,
        ?string $requestId = null,
        $paidAt = null,
        ?string $note = null,
        ?int $noticeId = null,
    ): array {
        if (! in_array($source, MarketplaceBillPayment::SOURCES, true)) {
            throw new \InvalidArgumentException('Unknown payment source '.$source);
        }
        $requestId = $requestId !== null && trim($requestId) !== '' ? mb_substr(trim($requestId), 0, 191) : null;

        if ($requestId !== null && ($replay = $this->replay($bill, $requestId))) {
            return $replay;
        }

        try {
            $result = DB::transaction(function () use ($bill, $amount, $method, $reference, $source, $actor, $requestId, $paidAt, $note, $noticeId) {
                /** @var MarketplaceBill $locked */
                $locked = MarketplaceBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();

                // Re-check under the lock (a concurrent call with the same id may have just committed).
                if ($requestId !== null && ($replay = $this->replay($locked, $requestId))) {
                    return $replay;
                }

                if ($locked->isPaid()) {
                    throw new HttpException(409, 'Bill '.$locked->bill_number.' is already fully paid.');
                }
                if ((string) $locked->status === 'cancelled') {
                    throw ValidationException::withMessages(['bill' => ['Bill '.$locked->bill_number.' was cancelled.']]);
                }
                if (! $locked->isPayable()) {
                    throw ValidationException::withMessages(['bill' => ['Bill '.$locked->bill_number.' has not been sent yet. Send it before recording a payment.']]);
                }

                $total = round((float) $locked->total_amount, 2);
                $paidSoFar = $this->recordedSum($locked->id);
                $outstanding = max(0.0, round($total - $paidSoFar, 2));

                $value = ($amount === null || $amount === '') ? $outstanding : round((float) $amount, 2);
                if (! is_finite($value) || $value <= 0) {
                    throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
                }
                if ($value - $outstanding > self::EPS) {
                    throw ValidationException::withMessages(['amount' => [
                        'Amount exceeds outstanding Rs '.number_format($outstanding, 2).' (received Rs '.number_format($value, 2).').',
                    ]]);
                }

                $when = $paidAt ? Carbon::parse($paidAt) : now();
                if ($when->isFuture()) {
                    $when = now();
                }

                $payment = MarketplaceBillPayment::create([
                    'marketplace_bill_id' => $locked->id,
                    'amount' => $value,
                    'method' => $method !== null ? mb_substr($method, 0, 64) : null,
                    'reference' => $reference !== null ? mb_substr($reference, 0, 191) : null,
                    'paid_at' => $when,
                    'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
                    'source' => $source,
                    'recorded_by_type' => $actor['type'] ?? null,
                    'recorded_by' => $actor['id'] ?? null,
                    'request_id' => $requestId,
                    'notice_id' => $noticeId,
                    'status' => 'recorded',
                ]);

                $wasPaid = $locked->isPaid();
                $locked = $this->recompute($locked, $payment);

                return [
                    'payment' => $payment->fresh(),
                    'bill' => $locked,
                    'replay' => false,
                    'fully_paid' => $locked->isPaid(),
                    'became_paid' => ! $wasPaid && $locked->isPaid(),
                ];
            });
        } catch (QueryException $e) {
            // Unique request_id raced past the replay check: return the stored payment.
            if ($requestId !== null && ($replay = $this->replay($bill, $requestId))) {
                return $replay;
            }
            throw $e;
        }

        if (! $result['replay']) {
            $this->afterPayment($result['bill'], $result['payment'], $result['became_paid']);
        }

        return $result;
    }

    /**
     * Reverse one payment (wrong entry, money never arrived, ...).
     *
     * @return array{bill: MarketplaceBill, payment: MarketplaceBillPayment, was_paid: bool, invoices_reopened: int, statement_lines_affected: int}
     */
    public function reverse(MarketplaceBillPayment $payment, string $reason, ?int $userId, bool $notReceived = false): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => ['Give a reason for reversing this payment.']]);
        }

        $result = DB::transaction(function () use ($payment, $reason, $userId, $notReceived) {
            $locked = MarketplaceBill::query()->whereKey($payment->marketplace_bill_id)->lockForUpdate()->firstOrFail();
            /** @var MarketplaceBillPayment $p */
            $p = MarketplaceBillPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! $p->isRecorded()) {
                throw ValidationException::withMessages(['payment' => ['This payment was already reversed.']]);
            }

            $wasPaid = $locked->isPaid();
            $p->forceFill([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => $userId,
                'reverse_reason' => mb_substr(($notReceived ? 'Not received: ' : '').$reason, 0, 500),
            ])->save();

            $locked = $this->recompute($locked, null, $reason, $userId);
            $reopened = $wasPaid && ! $locked->isPaid() ? $this->invoiceCount($locked) : 0;

            return ['bill' => $locked, 'payment' => $p->fresh(), 'was_paid' => $wasPaid, 'invoices_reopened' => $reopened];
        });

        $result['statement_lines_affected'] = $result['was_paid'] && ! $result['bill']->isPaid()
            ? $this->statementLinesFor($result['bill'])
            : 0;

        $this->afterReversal($result['bill'], $result['payment'], $reason, $notReceived);

        return $result;
    }

    /**
     * "Report not received": reverse the given payment, or every recorded payment.
     *
     * @return array{bill: MarketplaceBill, reversed: list<MarketplaceBillPayment>, was_paid: bool, invoices_reopened: int, statement_lines_affected: int}
     */
    public function reportNotReceived(MarketplaceBill $bill, string $reason, ?int $userId, ?int $paymentId = null): array
    {
        $q = MarketplaceBillPayment::query()->where('marketplace_bill_id', $bill->id)->where('status', 'recorded');
        if ($paymentId) {
            $q->whereKey($paymentId);
        }
        $payments = $q->orderByDesc('id')->get();
        if ($payments->isEmpty()) {
            throw ValidationException::withMessages(['payment' => [
                $paymentId ? 'That payment is not recorded on this bill (or was already reversed).' : 'This bill has no recorded payment to report as not received.',
            ]]);
        }

        $wasPaid = $bill->fresh()->isPaid();
        $reversed = [];
        $reopened = 0;
        $lines = 0;
        $last = null;
        foreach ($payments as $p) {
            $r = $this->reverse($p, $reason, $userId, true);
            $reversed[] = $r['payment'];
            $reopened = max($reopened, $r['invoices_reopened']);
            $lines = max($lines, $r['statement_lines_affected']);
            $last = $r['bill'];
        }

        return ['bill' => $last, 'reversed' => $reversed, 'was_paid' => $wasPaid, 'invoices_reopened' => $reopened, 'statement_lines_affected' => $lines];
    }

    /**
     * Recompute totals + status from the payment rows. Call inside a transaction
     * with the bill row locked.
     */
    public function recompute(MarketplaceBill $locked, ?MarketplaceBillPayment $latest = null, ?string $reversalReason = null, ?int $userId = null): MarketplaceBill
    {
        $total = round((float) $locked->total_amount, 2);
        $paid = $this->recordedSum($locked->id);
        $outstanding = max(0.0, round($total - $paid, 2));
        $before = (string) $locked->status;
        $last = MarketplaceBillPayment::query()->where('marketplace_bill_id', $locked->id)->where('status', 'recorded')
            ->orderByDesc('paid_at')->orderByDesc('id')->first();
        $final = $latest && $latest->isRecorded() ? $latest : $last;

        if ($before === 'cancelled') {
            $status = 'cancelled';
        } elseif ($total > 0 && $paid >= $total - self::EPS) {
            $status = 'paid';
        } elseif ($paid > 0) {
            $status = 'partially_paid';
        } elseif ($reversalReason !== null) {
            $status = 'reopened';
        } else {
            $status = in_array($before, ['partially_paid', 'paid'], true) ? 'reopened' : $before;
        }

        $patch = [
            'paid_amount' => $paid,
            'outstanding_amount' => $status === 'cancelled' ? 0 : $outstanding,
            'status' => $status,
            // Bill-level "last payment" columns (kept for older screens / exports).
            'paid_by_type' => $last?->payerType(),
            'paid_by' => $last?->recorded_by,
            'payment_method' => $last?->method,
            'payment_reference' => $last?->reference,
            'payment_note' => $last?->note,
            'payment_request_id' => $last?->request_id,
            'paid_at' => $status === 'paid'
                ? ($before === 'paid' && $locked->paid_at ? $locked->paid_at : ($final?->paid_at ?? now()))
                : null,
        ];
        if ($reversalReason !== null && $status !== 'paid') {
            $patch['reopened_at'] = now();
            $patch['reopen_reason'] = mb_substr($reversalReason, 0, 500);
            $patch['reopened_by'] = $userId;
        }
        $locked->forceFill($patch)->save();

        if ($status === 'paid' && $before !== 'paid') {
            // Fully paid: every invoice on the bill is paid -> branch shares become eligible.
            Invoice::query()->where('marketplace_bill_id', $locked->id)->update([
                'status' => 'paid',
                'paid_at' => $locked->paid_at,
                'paid_by_type' => $final?->payerType(),
                'paid_by' => $final?->recorded_by,
                'payment_method' => $final?->method ? mb_substr((string) $final->method, 0, 64) : null,
                'payment_reference' => $locked->bill_number,
                'paid_amount' => DB::raw('total_amount'),
                'outstanding_amount' => 0,
                'updated_at' => now(),
            ]);
        } elseif ($before === 'paid' && $status !== 'paid') {
            // Dropped below the total: invoices are unpaid again (shares no longer eligible).
            Invoice::query()->where('marketplace_bill_id', $locked->id)->update([
                'status' => 'unpaid',
                'paid_at' => null,
                'paid_by_type' => null,
                'paid_by' => null,
                'paid_amount' => null,
                'outstanding_amount' => DB::raw('total_amount'),
                'payment_method' => null,
                'payment_reference' => null,
                'reopened_at' => now(),
                'reopen_reason' => $reversalReason !== null ? mb_substr($reversalReason, 0, 500) : null,
                'updated_at' => now(),
            ]);
        }

        return $locked->fresh();
    }

    /** The "amount to pay" block the marketplace API returns (also used by the admin UI). */
    public function payable(MarketplaceBill $bill): array
    {
        $outstanding = $bill->isPayable() ? $bill->outstanding() : 0.0;
        $pay = $this->billing->paymentInstructions($bill);

        return [
            'bill_number' => $bill->bill_number,
            'currency' => 'NPR',
            'total_amount' => round((float) $bill->total_amount, 2),
            'paid_amount' => $bill->paidSoFar(),
            'amount_due' => $outstanding,
            'pending_verification_amount' => app(BillingPaymentNoticeService::class)->pendingSum('marketplace_bill', (int) $bill->id),
            'min' => $outstanding > 0 ? 0.01 : 0.0,
            'max' => $outstanding,
            'can_pay' => $outstanding > 0,
            'partial_allowed' => true,
            'reference_to_use' => $bill->bill_number,
            'instructions' => $pay['text'],
            'hamropay_merchant_id' => $pay['hamropay_merchant_id'],
            'contact' => $pay['contact'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function history(MarketplaceBill $bill, bool $internal = true): array
    {
        return MarketplaceBillPayment::query()->where('marketplace_bill_id', $bill->id)
            ->orderBy('paid_at')->orderBy('id')->get()
            ->map(function (MarketplaceBillPayment $p) use ($internal) {
                $row = [
                    'id' => $p->id,
                    'amount' => round((float) $p->amount, 2),
                    'method' => $p->method,
                    'reference' => $p->reference,
                    'paid_at' => $p->paid_at?->toIso8601String(),
                    'note' => $p->note,
                    'source' => $p->source,
                    'status' => $p->status,
                    'reversed_at' => $p->reversed_at?->toIso8601String(),
                    'reverse_reason' => $p->reverse_reason,
                    'recorded_at' => $p->created_at?->toIso8601String(),
                ];
                if ($internal) {
                    $row['recorded_by_type'] = $p->recorded_by_type;
                    $row['recorded_by'] = $p->recorded_by;
                    $row['recorded_by_label'] = $this->billing->payerLabel($p->payerType(), $p->recorded_by);
                    $row['reversed_by'] = $p->reversed_by;
                    $row['reversed_by_label'] = $p->reversed_by ? $this->billing->payerLabel('admin', $p->reversed_by) : null;
                } else {
                    unset($row['id']);
                    $row['payment_id'] = $p->id;
                }

                return $row;
            })->values()->all();
    }

    public function recordedSum(int $billId): float
    {
        return round((float) MarketplaceBillPayment::query()
            ->where('marketplace_bill_id', $billId)
            ->where('status', 'recorded')
            ->sum('amount'), 2);
    }

    /** Inter-branch statement lines already generated for the shipments on this bill. */
    public function statementLinesFor(MarketplaceBill $bill): int
    {
        $shipmentIds = Invoice::query()->where('marketplace_bill_id', $bill->id)->pluck('shipment_id')->filter()->unique();
        if ($shipmentIds->isEmpty()) {
            return 0;
        }

        return InterBranchStatementLine::query()->whereIn('shipment_id', $shipmentIds)->count();
    }

    private function invoiceCount(MarketplaceBill $bill): int
    {
        return Invoice::query()->where('marketplace_bill_id', $bill->id)->count();
    }

    /** @return array{payment: MarketplaceBillPayment, bill: MarketplaceBill, replay: bool, fully_paid: bool, became_paid: bool}|null */
    private function replay(MarketplaceBill $bill, string $requestId): ?array
    {
        $existing = MarketplaceBillPayment::query()->where('request_id', $requestId)->first();
        if (! $existing) {
            return null;
        }
        if ((int) $existing->marketplace_bill_id !== (int) $bill->id) {
            throw new HttpException(409, 'This request id was already used for another bill.');
        }
        $fresh = MarketplaceBill::query()->findOrFail($bill->id);

        return ['payment' => $existing, 'bill' => $fresh, 'replay' => true, 'fully_paid' => $fresh->isPaid(), 'became_paid' => false];
    }

    /*
    |--------------------------------------------------------------------------
    | Notifications (after commit)
    |--------------------------------------------------------------------------
    */

    private function afterPayment(MarketplaceBill $bill, MarketplaceBillPayment $payment, bool $becamePaid): void
    {
        $bill->loadMissing('marketplace', 'branch');
        $who = $this->billing->payerLabel($payment->payerType(), $payment->recorded_by) ?? 'Marketplace';
        $amount = number_format((float) $payment->amount, 2);
        $subject = $becamePaid
            ? 'Bill '.$bill->bill_number.' fully paid (last payment Rs '.$amount.' by '.$who.')'
            : 'Payment Rs '.$amount.' recorded on bill '.$bill->bill_number.' by '.$who;
        $message = ($bill->marketplace?->name ?? 'The marketplace').' bill '.$bill->bill_number.': payment of Rs '.$amount
            .' recorded by '.$who.' via '.($payment->method ?? '-').', reference '.($payment->reference ?? '-').'. '
            .'Total Rs '.number_format((float) $bill->total_amount, 2).', paid Rs '.number_format($bill->paidSoFar(), 2)
            .', outstanding Rs '.number_format($bill->outstanding(), 2).'. '
            .($becamePaid ? 'The bill is fully paid; its branch shares are released to inter-branch statements. ' : '')
            .'If this money has not arrived, open Finance -> Marketplace bills and reverse the payment ("Report not received").';

        $this->notifyBranch($bill, $becamePaid ? 'marketplace_bill.paid' : 'marketplace_bill.payment_recorded', $subject, $message, ['payment_id' => $payment->id]);
        if ($becamePaid) {
            // Keep the per-payment event too so a branch feed shows every payment.
            $this->notifyBranch($bill, 'marketplace_bill.payment_recorded', $subject, $message, ['payment_id' => $payment->id], false);
        }

        $extra = ['payment' => $this->webhookPayment($payment)];
        $this->billing->webhook($bill, 'billing.bill.payment_recorded', null, $extra);
        if ($becamePaid) {
            $this->billing->webhook($bill, 'billing.bill.paid', null, $extra);
        }
    }

    private function afterReversal(MarketplaceBill $bill, MarketplaceBillPayment $payment, string $reason, bool $notReceived): void
    {
        $bill->loadMissing('marketplace', 'branch');
        $subject = 'Payment Rs '.number_format((float) $payment->amount, 2).' reversed on bill '.$bill->bill_number;
        $message = $subject.($notReceived ? ' (reported not received)' : '').'. Reason: '.$reason.'. Outstanding is now Rs '
            .number_format($bill->outstanding(), 2).' (status '.$bill->status.').';
        $this->notifyBranch($bill, 'marketplace_bill.payment_reversed', $subject, $message, ['payment_id' => $payment->id], false);

        $extra = ['payment' => $this->webhookPayment($payment)];
        $this->billing->webhook($bill, 'billing.bill.payment_reversed', null, $extra);
        if ((string) $bill->status === 'reopened') {
            $this->billing->webhook($bill, 'billing.bill.reopened', null, $extra);
        }
        $this->billing->emailMarketplaceNotice(
            $bill,
            ($notReceived ? 'Payment not received' : 'Payment reversed').' for bill '.$bill->bill_number,
            '<p>'.e($bill->branch?->name ?? 'Tukaatu Express').' has '.($notReceived ? 'not received' : 'reversed').' the payment of <b>Rs '
            .number_format((float) $payment->amount, 2).'</b> (reference '.e((string) ($payment->reference ?? '-')).') recorded for bill <b>'.e($bill->bill_number).'</b>.</p>'
            .'<p>Reason: '.e($reason).'</p><p>Outstanding now: <b>Rs '.number_format($bill->outstanding(), 2).'</b>. '
            .'Please check the payment and pay again using the bill number as the payment reference.</p>'
        );
    }

    private function webhookPayment(MarketplaceBillPayment $p): array
    {
        return [
            'payment_id' => $p->id,
            'amount' => round((float) $p->amount, 2),
            'method' => $p->method,
            'reference' => $p->reference,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'source' => $p->source,
            'status' => $p->status,
            'reverse_reason' => $p->reverse_reason,
        ];
    }

    private function notifyBranch(MarketplaceBill $bill, string $event, string $subject, string $message, array $payload, bool $email = true): void
    {
        try {
            NotificationLog::create([
                'user_id' => $bill->branch?->manager_user_id,
                'channel' => 'system',
                'event' => $event,
                'recipient' => $bill->branch?->email,
                'subject' => $subject,
                'message' => $message,
                'payload' => $payload + [
                    'bill_id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'branch_id' => $bill->branch_id,
                    'marketplace_id' => $bill->marketplace_id,
                    'paid_amount' => $bill->paidSoFar(),
                    'outstanding_amount' => $bill->outstanding(),
                    'status' => $bill->status,
                ],
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::info('marketplace_bill.notification_log_skipped', ['error' => $e->getMessage()]);
        }

        if (! $email) {
            return;
        }
        $emails = app(InterBranchStatementService::class)->branchEmails((int) $bill->branch_id);
        if ($emails === []) {
            return;
        }
        try {
            Mail::raw($message, fn ($m) => $m->to($emails)->subject($subject));
            $this->billing->logEmail(implode(',', $emails), $subject, $message);
        } catch (Throwable $e) {
            Log::warning('marketplace_bill.branch_email_failed', ['bill' => $bill->bill_number, 'error' => $e->getMessage()]);
        }
    }
}
