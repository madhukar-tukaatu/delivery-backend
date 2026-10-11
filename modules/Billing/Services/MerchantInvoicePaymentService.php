<?php

namespace Modules\Billing\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\MarketplaceBill;
use Modules\Billing\Models\MerchantInvoicePayment;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The ONLY place a payment on a merchant delivery-charge invoice is recorded or
 * reversed (mirrors MarketplaceBillPaymentService).
 *
 * Who records: the billing branch / HQ (directly, or by verifying a merchant
 * payment notice) and provider-confirmed HamroPay. A merchant never records a
 * payment itself; it submits a notice (BillingPaymentNoticeService).
 *
 * Rules: amount > 0 and <= outstanding (422); paid invoice -> 409; row lock;
 * idempotent by request_id; status unpaid -> partially_paid -> paid recomputed
 * from the recorded rows. Only a FULLY paid invoice is status=paid, which is what
 * releases its branch shares (BranchShareService::eligibleQuery). Reversal reopens.
 */
class MerchantInvoicePaymentService
{
    private const EPS = 0.004;

    public const OPEN_STATUSES = ['unpaid', 'partially_paid'];

    public function __construct(
        private MarketplaceBillingService $billing,
        private BillingNotifier $notifier,
    ) {
    }

    public static function isMerchantInvoice(Invoice $invoice): bool
    {
        $payer = strtolower((string) ($invoice->payer_type ?? 'merchant'));

        return (string) $invoice->type === 'delivery_charges'
            && in_array($payer, ['merchant', ''], true)
            && ! empty($invoice->merchant_id);
    }

    public function recordedSum(int $invoiceId): float
    {
        return round((float) MerchantInvoicePayment::query()->where('invoice_id', $invoiceId)->where('status', 'recorded')->sum('amount'), 2);
    }

    public function outstanding(Invoice $invoice): float
    {
        $status = strtolower((string) $invoice->status);
        if (in_array($status, ['paid', 'cancelled', 'canceled', 'void'], true)) {
            return 0.0;
        }

        return max(0.0, round((float) $invoice->total_amount - $this->recordedSum((int) $invoice->id), 2));
    }

    /**
     * @param  float|string|null  $amount  null = full outstanding
     * @param  array{type: string, id: ?int}  $actor  type: user | system
     * @return array{payment: MerchantInvoicePayment, invoice: Invoice, replay: bool, fully_paid: bool, became_paid: bool}
     */
    public function record(
        Invoice $invoice,
        $amount,
        ?string $method,
        ?string $reference,
        string $source,
        array $actor,
        ?string $requestId = null,
        $paidAt = null,
        ?string $note = null,
        ?string $batchReference = null,
        ?int $noticeId = null,
    ): array {
        if (! in_array($source, MerchantInvoicePayment::SOURCES, true)) {
            throw new \InvalidArgumentException('Unknown payment source '.$source);
        }
        $requestId = $requestId !== null && trim($requestId) !== '' ? mb_substr(trim($requestId), 0, 191) : null;
        if ($requestId !== null && ($replay = $this->replay($invoice, $requestId))) {
            return $replay;
        }

        try {
            $result = DB::transaction(function () use ($invoice, $amount, $method, $reference, $source, $actor, $requestId, $paidAt, $note, $batchReference, $noticeId) {
                /** @var Invoice $locked */
                $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
                if ($requestId !== null && ($replay = $this->replay($locked, $requestId))) {
                    return $replay;
                }
                $this->assertPayable($locked);

                $total = round((float) $locked->total_amount, 2);
                $outstanding = max(0.0, round($total - $this->recordedSum($locked->id), 2));
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

                $payment = MerchantInvoicePayment::create([
                    'invoice_id' => $locked->id,
                    'merchant_id' => $locked->merchant_id,
                    'branch_id' => $locked->branch_id,
                    'amount' => $value,
                    'method' => $method !== null ? mb_substr($method, 0, 64) : null,
                    'reference' => $reference !== null ? mb_substr($reference, 0, 191) : null,
                    'paid_at' => $when,
                    'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
                    'source' => $source,
                    'recorded_by_type' => $actor['type'] ?? null,
                    'recorded_by' => $actor['id'] ?? null,
                    'request_id' => $requestId,
                    'batch_reference' => $batchReference,
                    'notice_id' => $noticeId,
                    'status' => 'recorded',
                ]);

                $wasPaid = strtolower((string) $locked->status) === 'paid';
                $locked = $this->recompute($locked, $payment);
                $isPaid = strtolower((string) $locked->status) === 'paid';

                return ['payment' => $payment->fresh(), 'invoice' => $locked, 'replay' => false, 'fully_paid' => $isPaid, 'became_paid' => ! $wasPaid && $isPaid];
            });
        } catch (QueryException $e) {
            if ($requestId !== null && ($replay = $this->replay($invoice, $requestId))) {
                return $replay;
            }
            throw $e;
        }

        if (! $result['replay']) {
            $this->afterPayment($result['invoice'], $result['payment'], $result['became_paid']);
        }

        return $result;
    }

    /** @return array{invoice: Invoice, payment: MerchantInvoicePayment, was_paid: bool} */
    public function reverse(MerchantInvoicePayment $payment, string $reason, ?int $userId, bool $notReceived = false): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => ['Give a reason for reversing this payment.']]);
        }

        $result = DB::transaction(function () use ($payment, $reason, $userId, $notReceived) {
            $locked = Invoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();
            $p = MerchantInvoicePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! $p->isRecorded()) {
                throw ValidationException::withMessages(['payment' => ['This payment was already reversed.']]);
            }
            $wasPaid = strtolower((string) $locked->status) === 'paid';
            $full = ($notReceived ? 'Not received: ' : '').$reason;
            $p->forceFill([
                'status' => 'reversed',
                'reversed_at' => now(),
                'reversed_by' => $userId,
                'reverse_reason' => mb_substr($full, 0, 500),
            ])->save();
            $locked = $this->recompute($locked, null, $full);

            return ['invoice' => $locked, 'payment' => $p->fresh(), 'was_paid' => $wasPaid];
        });

        $inv = $result['invoice'];
        $subject = 'Payment Rs '.number_format((float) $result['payment']->amount, 2).' reversed on invoice '.$inv->invoice_number;
        $message = $subject.'. Reason: '.$reason.'. Outstanding is now Rs '.number_format($this->outstanding($inv), 2).' (status '.$inv->status.').';
        $this->notifier->notifyBranch((int) $inv->branch_id, 'merchant_invoice.payment_reversed', $subject, $message, ['invoice_id' => $inv->id, 'payment_id' => $result['payment']->id], false, (int) $inv->merchant_id);
        $this->notifier->notifyMerchant((int) $inv->merchant_id, 'merchant_invoice.payment_reversed', $subject, $message.' Please check the payment and pay again using the invoice number as the reference.', ['invoice_id' => $inv->id]);

        return $result;
    }

    /** Recompute paid / outstanding / status from recorded rows. Call with the invoice row locked. */
    public function recompute(Invoice $locked, ?MerchantInvoicePayment $latest = null, ?string $reversalReason = null): Invoice
    {
        $total = round((float) $locked->total_amount, 2);
        $paid = $this->recordedSum((int) $locked->id);
        $outstanding = max(0.0, round($total - $paid, 2));
        $before = strtolower((string) $locked->status);
        $last = MerchantInvoicePayment::query()->where('invoice_id', $locked->id)->where('status', 'recorded')
            ->orderByDesc('paid_at')->orderByDesc('id')->first();
        $final = $latest && $latest->isRecorded() ? $latest : $last;

        if ($total > 0 && $paid >= $total - self::EPS) {
            $status = 'paid';
        } elseif ($paid > 0) {
            $status = 'partially_paid';
        } else {
            $status = 'unpaid';
        }

        $patch = [
            'status' => $status,
            'paid_amount' => $paid > 0 ? $paid : null,
            'outstanding_amount' => $status === 'paid' ? 0 : $outstanding,
            'paid_by_type' => $last?->payerType(),
            'paid_by' => $last?->recorded_by,
            'payment_method' => $last?->method,
            'payment_reference' => $last?->reference,
            'paid_at' => $status === 'paid'
                ? ($before === 'paid' && $locked->paid_at ? $locked->paid_at : ($final?->paid_at ?? now()))
                : null,
        ];
        if ($reversalReason !== null && $status !== 'paid') {
            $patch['reopened_at'] = now();
            $patch['reopen_reason'] = mb_substr($reversalReason, 0, 500);
        }
        $locked->forceFill($patch)->save();

        return $locked->fresh();
    }

    /** "Amount to pay" block for one invoice. */
    public function payable(Invoice $invoice): array
    {
        $outstanding = $this->outstanding($invoice);
        $pending = app(BillingPaymentNoticeService::class)->pendingSum('invoice', (int) $invoice->id);

        return [
            'invoice_number' => $invoice->invoice_number,
            'currency' => 'NPR',
            'total_amount' => round((float) $invoice->total_amount, 2),
            'paid_amount' => $this->recordedSum((int) $invoice->id),
            'amount_due' => $outstanding,
            'pending_verification_amount' => $pending,
            'claimable' => max(0.0, round($outstanding - $pending, 2)),
            'can_pay' => $outstanding > 0,
            'partial_allowed' => true,
            'reference_to_use' => $invoice->invoice_number,
            'pay_to_branch_id' => $invoice->branch_id,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function history(Invoice $invoice, bool $internal = true): array
    {
        return MerchantInvoicePayment::query()->where('invoice_id', $invoice->id)
            ->orderBy('paid_at')->orderBy('id')->get()
            ->map(function (MerchantInvoicePayment $p) use ($internal) {
                $row = [
                    'id' => $p->id,
                    'amount' => round((float) $p->amount, 2),
                    'method' => $p->method,
                    'reference' => $p->reference,
                    'paid_at' => $p->paid_at?->toIso8601String(),
                    'note' => $p->note,
                    'source' => $p->source,
                    'batch_reference' => $p->batch_reference,
                    'notice_id' => $p->notice_id,
                    'status' => $p->status,
                    'reversed_at' => $p->reversed_at?->toIso8601String(),
                    'reverse_reason' => $p->reverse_reason,
                    'recorded_at' => $p->created_at?->toIso8601String(),
                ];
                if ($internal) {
                    $row['recorded_by'] = $p->recorded_by;
                    $row['recorded_by_label'] = $p->source === 'hamropay' ? 'HamroPay' : $this->billing->payerLabel($p->payerType(), $p->recorded_by);
                    $row['reversed_by_label'] = $p->reversed_by ? $this->billing->payerLabel('admin', $p->reversed_by) : null;
                }

                return $row;
            })->values()->all();
    }

    public function assertPayable(Invoice $invoice): void
    {
        if (! self::isMerchantInvoice($invoice)) {
            throw ValidationException::withMessages(['invoice' => ['Only store delivery-charge invoices can be paid here.']]);
        }
        $status = strtolower((string) $invoice->status);
        if ($status === 'paid') {
            throw new HttpException(409, 'Invoice '.$invoice->invoice_number.' is already fully paid.');
        }
        if (! in_array($status, self::OPEN_STATUSES, true)) {
            throw ValidationException::withMessages(['invoice' => ['Invoice '.$invoice->invoice_number.' is '.$status.' and cannot take a payment.']]);
        }
        if ($invoice->marketplace_bill_id) {
            $bill = MarketplaceBill::query()->find($invoice->marketplace_bill_id);
            if ($bill && $bill->isOpen()) {
                throw ValidationException::withMessages(['invoice' => ['This invoice is on marketplace bill '.$bill->bill_number.'. The marketplace pays the whole bill.']]);
            }
        }
    }

    /** @return array{payment: MerchantInvoicePayment, invoice: Invoice, replay: bool, fully_paid: bool, became_paid: bool}|null */
    private function replay(Invoice $invoice, string $requestId): ?array
    {
        $existing = MerchantInvoicePayment::query()->where('request_id', $requestId)->first();
        if (! $existing) {
            return null;
        }
        if ((int) $existing->invoice_id !== (int) $invoice->id) {
            throw new HttpException(409, 'This request id was already used for another invoice.');
        }
        $fresh = Invoice::query()->findOrFail($invoice->id);

        return ['payment' => $existing, 'invoice' => $fresh, 'replay' => true, 'fully_paid' => strtolower((string) $fresh->status) === 'paid', 'became_paid' => false];
    }

    private function afterPayment(Invoice $invoice, MerchantInvoicePayment $payment, bool $becamePaid): void
    {
        $amount = number_format((float) $payment->amount, 2);
        $outstanding = $this->outstanding($invoice);
        $who = $payment->source === 'hamropay' ? 'HamroPay' : ($this->billing->payerLabel($payment->payerType(), $payment->recorded_by) ?? 'Branch');
        $store = DB::table('merchants')->where('id', $invoice->merchant_id)->value('name') ?? 'Store';
        $subject = $becamePaid
            ? 'Invoice '.$invoice->invoice_number.' fully paid (Rs '.$amount.')'
            : 'Payment Rs '.$amount.' recorded on invoice '.$invoice->invoice_number;
        $message = $store.' invoice '.$invoice->invoice_number.': payment of Rs '.$amount.' recorded by '.$who
            .' via '.($payment->method ?? '-').', reference '.($payment->reference ?? '-').'. Total Rs '.number_format((float) $invoice->total_amount, 2)
            .', outstanding Rs '.number_format($outstanding, 2).'.'
            .($becamePaid ? ' The invoice is fully paid; its branch shares are released to inter-branch statements.' : '');
        $this->notifier->notifyBranch((int) $invoice->branch_id, $becamePaid ? 'merchant_invoice.paid' : 'merchant_invoice.payment_recorded', $subject, $message, [
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
            'source' => $payment->source,
            'outstanding_amount' => $outstanding,
        ], false, (int) $invoice->merchant_id);
        $this->notifier->notifyMerchant((int) $invoice->merchant_id, $becamePaid ? 'merchant_invoice.paid' : 'merchant_invoice.payment_recorded', $subject, $message, [
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
        ], false);
    }
}