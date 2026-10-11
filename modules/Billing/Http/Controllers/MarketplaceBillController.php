<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\FinanceBranchScope;
use Illuminate\Http\Request;
use Modules\Billing\Models\MarketplaceBill;
use Modules\Billing\Models\BillingPaymentNotice;
use Modules\Billing\Models\MarketplaceBillPayment;
use Modules\Billing\Services\BillingPaymentNoticeService;
use Modules\Billing\Services\MarketplaceBillPaymentService;
use Modules\Billing\Services\MarketplaceBillingService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Admin / branch side of marketplace free-delivery bills.
 * Branch users only see and act on their own branch's bills (404 otherwise).
 * HQ (super_admin / main_admin / admin) sees every branch.
 */
class MarketplaceBillController extends Controller
{
    public function __construct(
        private MarketplaceBillingService $billing,
        private MarketplaceBillPaymentService $payments,
        private BillingPaymentNoticeService $notices,
    ) {
    }

    /** GET admin/marketplace-bills */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);

        $filters = $request->only(['status', 'marketplace_id', 'branch_id', 'from', 'to', 'search']);
        $hasNotices = ($filters['status'] ?? null) === 'has_notices';
        if ($hasNotices) {
            unset($filters['status']);
        }
        $q = $this->billing->applyBillFilters($this->billing->billQuery($user), $user, $filters)
            ->with(['branch:id,name,code', 'marketplace:id,name,code,email'])
            ->latest('id');
        $openNotices = BillingPaymentNotice::query()->where('target_type', 'marketplace_bill')->where('status', 'pending_verification');
        if ($hasNotices) {
            $q->whereIn('marketplace_bills.id', (clone $openNotices)->select('target_id'));
        }

        $totals = (clone $q)->reorder()->setEagerLoads([])
            ->selectRaw("COUNT(*) c, COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total_amount ELSE 0 END),0) t, COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN COALESCE(paid_amount,0) ELSE 0 END),0) p, COALESCE(SUM(CASE WHEN status IN ('sent','partially_paid','reopened') THEN outstanding_amount ELSE 0 END),0) o")
            ->first();

        $paginator = $q->paginate(min((int) $request->get('per_page', 20), 100));
        $paginator->getCollection()->transform(fn (MarketplaceBill $b) => $this->billing->presentBill($b));

        $payload = $paginator->toArray();
        $payload['totals'] = [
            'count' => (int) ($totals->c ?? 0),
            'total' => round((float) ($totals->t ?? 0), 2),
            'paid' => round((float) ($totals->p ?? 0), 2),
            'outstanding' => round((float) ($totals->o ?? 0), 2),
        ];
        $payload['summary'] = $this->billing->summary($user, $request->only(['marketplace_id', 'branch_id']));
        $scoped = $this->billing->applyBillFilters($this->billing->billQuery($user), $user, $request->only(['marketplace_id', 'branch_id']))->reorder()->setEagerLoads([])->select('marketplace_bills.id');
        $pending = (clone $openNotices)->whereIn('target_id', $scoped)->selectRaw('COUNT(*) c, COALESCE(SUM(amount),0) t, COUNT(DISTINCT target_id) b')->first();
        $payload['summary']['notices_pending'] = [
            'count' => (int) ($pending->c ?? 0),
            'amount' => round((float) ($pending->t ?? 0), 2),
            'bills' => (int) ($pending->b ?? 0),
        ];
        $payload['scope'] = [
            'is_hq' => ! FinanceBranchScope::applies($user),
            'branch_ids' => FinanceBranchScope::applies($user) ? FinanceBranchScope::branchIds($user) : null,
        ];

        return ApiResponse::success($payload);
    }

    /**
     * GET admin/marketplace-bills/ready
     * Grouped per branch + marketplace. With branch_id + marketplace_id (+ with_invoices=1)
     * it also returns the invoices for the Review & send drawer.
     */
    public function ready(Request $request)
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);

        $filters = $request->only(['branch_id', 'marketplace_id', 'from', 'to']);
        $data = ['groups' => $this->billing->readyGroups($user, $filters)];

        if ($request->boolean('with_invoices') && $request->filled('marketplace_id')) {
            $data['invoices'] = $this->billing->readyInvoices($user, $filters)->map(fn ($i) => [
                'id' => $i->id,
                'invoice_number' => $i->invoice_number,
                'invoice_date' => $i->invoice_date?->toDateString(),
                'branch_id' => $i->branch_id,
                'marketplace_id' => $i->marketplace_id,
                'tracking_number' => $i->shipment?->tracking_number,
                'shipment_id' => $i->shipment_id,
                'store_name' => $i->shipment?->merchant?->name,
                'store_id' => $i->shipment?->merchant?->external_store_id,
                'delivered_at' => $i->shipment?->delivered_at,
                'amount' => round((float) $i->total_amount, 2),
                'emailed_before' => $i->sent_at !== null,
            ])->values();
        }

        return ApiResponse::success($data);
    }

    /** POST admin/marketplace-bills {marketplace_id, branch_id?, invoice_ids?, from?, to?, send_now?} */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);

        $data = $request->validate([
            'marketplace_id' => ['required', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'invoice_ids' => ['nullable', 'array', 'max:1000'],
            'invoice_ids.*' => ['integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'send_now' => ['nullable', 'boolean'],
        ]);

        $branchId = $this->resolveBranch($request, $data['branch_id'] ?? null);

        $bill = $this->billing->createBill(
            $branchId,
            (int) $data['marketplace_id'],
            array_key_exists('invoice_ids', $data) && $data['invoice_ids'] !== null ? $data['invoice_ids'] : null,
            $data['from'] ?? null,
            $data['to'] ?? null,
            $user->id,
        );

        $message = 'Bill '.$bill->bill_number.' created (draft).';
        $sentTo = null;
        if ($request->boolean('send_now')) {
            try {
                $sent = $this->billing->send($bill, $user->id);
                $bill = $sent['bill'];
                $sentTo = $sent['to'];
                $message = 'Bill '.$bill->bill_number.' sent to '.$sentTo.'.';
            } catch (\Illuminate\Validation\ValidationException $e) {
                // Keep the draft so nothing is lost; tell the user why it was not sent.
                return ApiResponse::success(
                    $this->billing->presentBill($bill->fresh(), true) + ['sent_to' => null, 'send_error' => collect($e->errors())->flatten()->first()],
                    'Bill '.$bill->bill_number.' created as draft but not sent: '.collect($e->errors())->flatten()->first(),
                    201
                );
            }
        }

        return ApiResponse::success($this->billing->presentBill($bill->fresh(), true) + ['sent_to' => $sentTo], $message, 201);
    }

    public function show(Request $request, MarketplaceBill $bill)
    {
        $this->authorizeBill($request, $bill);

        return ApiResponse::success($this->billing->presentBill($bill, true));
    }

    /** POST admin/marketplace-bills/{bill}/send and /resend */
    public function send(Request $request, MarketplaceBill $bill)
    {
        $this->authorizeBill($request, $bill);
        $result = $this->billing->send($bill, $request->user()->id);

        return ApiResponse::success(
            $this->billing->presentBill($result['bill'], true) + ['sent_to' => $result['to'], 'webhook_queued' => $result['webhook_queued'], 'email_error' => $result['email_error'] ?? null],
            'Bill '.$bill->bill_number.' sent to '.$result['to'].'.'
                .(! empty($result['email_error']) ? ' Email failed ('.$result['email_error'].'); the API push was queued.' : '')
        );
    }

    /**
     * POST admin/marketplace-bills/{bill}/push
     * Push the bill to the marketplace API again (same payload as on send, with
     * lines and payment instructions). Delivery is tracked on the bill.
     */
    public function push(Request $request, MarketplaceBill $bill)
    {
        $this->authorizeBill($request, $bill);
        abort_unless($bill->sent_at, 422, 'Send the bill first.');
        abort_if((string) $bill->status === 'cancelled', 422, 'This bill is cancelled.');
        $bill->loadMissing(['marketplace', 'branch']);
        $target = $this->billing->pushTarget($bill->marketplace);
        abort_unless($target['ready'], 422, ($target['problem'] ?? 'Push is not configured').'. Set it on Admin -> Marketplaces.');

        $event = $bill->isPaid() ? 'billing.bill.paid' : 'billing.bill.issued';
        $queued = $this->billing->webhook($bill, $event, $this->billing->billLines($bill));

        return ApiResponse::success($this->billing->presentBill($bill->fresh(), true) + ['webhook_queued' => $queued],
            $queued ? 'Bill '.$bill->bill_number.' queued to push to '.($bill->marketplace?->name ?? 'the marketplace').' ('.$event.').' : 'Push could not be queued.');
    }

    /**
     * POST admin/marketplace-bills/{bill}/notices/{notice}/verify
     * {amount?, method?, reference?, paid_at?, note?}. The branch manager checked
     * the money arrived: records the (possibly partial) payment, source
     * notice_verified. Only a fully paid bill releases invoices and shares.
     */
    public function verifyNotice(Request $request, MarketplaceBill $bill, int $notice)
    {
        $this->authorizeBill($request, $bill);
        $row = $this->noticeOf($bill, $notice);
        $data = $request->validate([
            'amount' => ['nullable', 'numeric'],
            'method' => ['nullable', 'string', 'max:64'],
            'reference' => ['nullable', 'string', 'max:191'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        try {
            $r = $this->notices->verify($row, $data['amount'] ?? null, $data['method'] ?? null, $data['reference'] ?? null, $data['paid_at'] ?? null, $data['note'] ?? null, $request->user());
        } catch (HttpException $e) {
            return ApiResponse::error($e->getMessage(), $e->getStatusCode());
        }
        $fresh = $r['target'];

        return ApiResponse::success($this->billing->presentBill($fresh, true) + ['payment_id' => $r['payment']->id],
            'Notice verified: Rs '.number_format((float) $r['payment']->amount, 2).' recorded.'
            .($r['fully_paid'] ? ' Bill '.$fresh->bill_number.' is fully paid; branch shares are released.' : ' Rs '.number_format($fresh->outstanding(), 2).' still outstanding.'));
    }

    /** POST admin/marketplace-bills/{bill}/notices/{notice}/reject {reason} */
    public function rejectNotice(Request $request, MarketplaceBill $bill, int $notice)
    {
        $this->authorizeBill($request, $bill);
        $row = $this->noticeOf($bill, $notice);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $this->notices->reject($row, $data['reason'], $request->user());

        return ApiResponse::success($this->billing->presentBill($bill->fresh(), true), 'Payment notice rejected. The marketplace was notified; nothing was recorded.');
    }

    private function noticeOf(MarketplaceBill $bill, int $notice): BillingPaymentNotice
    {
        $row = BillingPaymentNotice::query()->where('target_type', 'marketplace_bill')->where('target_id', $bill->id)->whereKey($notice)->first();
        abort_unless($row, 404, 'Payment notice not found on this bill.');

        return $row;
    }

    /**
     * POST admin/marketplace-bills/{bill}/mark-paid  ("Record payment")
     * {amount?, method, reference, paid_at?, note?, request_id?}
     * amount omitted = full outstanding; any amount 0.01 .. outstanding (part payment).
     * request_id (UUID from the modal) makes double clicks harmless.
     */
    public function markPaid(Request $request, MarketplaceBill $bill)
    {
        $this->authorizeBill($request, $bill);
        $data = $request->validate([
            'amount' => ['nullable', 'numeric'],
            'method' => ['required', 'string', 'max:64'],
            'reference' => ['required', 'string', 'max:191'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
            'request_id' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $type = MarketplaceBillingService::actorType($user) === 'admin' ? 'admin' : 'branch';
        try {
            $result = $this->payments->record(
                $bill,
                $data['amount'] ?? null,
                $data['method'],
                $data['reference'],
                $type,
                ['type' => 'user', 'id' => $user->id],
                ! empty($data['request_id']) ? 'ui:'.$user->id.':'.$data['request_id'] : null,
                $data['paid_at'] ?? null,
                $data['note'] ?? null,
            );
        } catch (HttpException $e) {
            return ApiResponse::error($e->getMessage(), $e->getStatusCode());
        }

        $fresh = $result['bill'];
        $msg = $result['replay']
            ? 'This payment was already recorded.'
            : ($result['fully_paid']
                ? 'Payment Rs '.number_format((float) $result['payment']->amount, 2).' recorded. Bill '.$fresh->bill_number.' is fully paid; branch shares are released.'
                : 'Payment Rs '.number_format((float) $result['payment']->amount, 2).' recorded. Rs '.number_format($fresh->outstanding(), 2).' still outstanding.');

        return ApiResponse::success($this->billing->presentBill($fresh, true) + [
            'payment_id' => $result['payment']->id,
            'idempotent_replay' => $result['replay'],
        ], $msg);
    }

    /** POST admin/marketplace-bills/{bill}/payments/{payment}/reverse {reason, not_received?} */
    public function reversePayment(Request $request, MarketplaceBill $bill, int $payment)
    {
        $this->authorizeBill($request, $bill);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'not_received' => ['nullable', 'boolean'],
        ]);
        $row = MarketplaceBillPayment::query()->where('marketplace_bill_id', $bill->id)->whereKey($payment)->first();
        abort_unless($row, 404, 'Payment not found on this bill.');

        $r = $this->payments->reverse($row, $data['reason'], $request->user()->id, (bool) ($data['not_received'] ?? false));

        return ApiResponse::success($this->billing->presentBill($r['bill'], true) + [
            'invoices_reopened' => $r['invoices_reopened'],
            'statement_lines_affected' => $r['statement_lines_affected'],
        ], $this->reversalMessage($r['bill'], 1, (float) $r['payment']->amount, $r['invoices_reopened'], $r['statement_lines_affected']));
    }

    /**
     * POST admin/marketplace-bills/{bill}/report-not-received {reason, payment_id?}
     * Reverses that payment (or every recorded payment) as "not received".
     */
    public function reportNotReceived(Request $request, MarketplaceBill $bill)
    {
        $this->authorizeBill($request, $bill);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'payment_id' => ['nullable', 'integer'],
        ]);

        $result = $this->billing->reportNotReceived($bill, $data['reason'], $request->user()->id, $data['payment_id'] ?? null);
        $amount = array_sum(array_map(fn ($p) => (float) $p->amount, $result['reversed']));

        return ApiResponse::success($this->billing->presentBill($result['bill'], true) + [
            'reversed_payments' => count($result['reversed']),
            'invoices_reopened' => $result['invoices_reopened'],
            'statement_lines_affected' => $result['statement_lines_affected'],
            'shares_already_on_statements' => $result['shares_already_on_statements'],
        ], $this->reversalMessage($result['bill'], count($result['reversed']), $amount, $result['invoices_reopened'], $result['statement_lines_affected']).' The marketplace was notified.');
    }

    private function reversalMessage(MarketplaceBill $bill, int $count, float $amount, int $invoicesReopened, int $lines): string
    {
        $msg = $count.' payment(s) of Rs '.number_format($amount, 2).' reversed on '.$bill->bill_number.'. Outstanding: Rs '.number_format($bill->outstanding(), 2).'.';
        if ($invoicesReopened > 0) {
            $msg .= ' '.$invoicesReopened.' invoice(s) are unpaid again.';
        }
        if ($lines > 0) {
            $msg .= ' Note: '.$lines.' inter-branch statement line(s) were already generated for these shipments while the bill was paid; they were not changed.';
        }

        return $msg;
    }

    /** POST admin/marketplace-bills/{bill}/cancel-bill */
    public function cancel(Request $request, MarketplaceBill $bill)
    {
        $this->authorizeBill($request, $bill);

        $bill = $this->billing->cancel($bill, $request->user()->id);

        return ApiResponse::success($this->billing->presentBill($bill), 'Bill '.$bill->bill_number.' cancelled. Its invoices are ready to send again.');
    }

    /** POST admin/marketplace-bills/{bill}/confirm-hamropay {merchant_txn_id} */
    public function confirmHamroPay(Request $request, MarketplaceBill $bill)
    {
        $this->authorizeBill($request, $bill);
        $data = $request->validate(['merchant_txn_id' => ['required', 'string', 'max:64']]);
        abort_unless(str_starts_with($data['merchant_txn_id'], 'MBPAY-'.$bill->id.'-'), 422, 'This transaction is not for this bill.');

        try {
            $check = $this->billing->confirmHamroPayTransaction($data['merchant_txn_id']);
        } catch (HttpException $e) {
            return ApiResponse::error($e->getMessage(), $e->getStatusCode());
        }
        if (! $check['paid']) {
            return ApiResponse::error('HamroPay has not confirmed this payment yet.', 422, ['status' => $check['status']]);
        }
        $bill = $check['bill']->fresh();

        return ApiResponse::success(
            $this->billing->presentBill($bill, true),
            ! empty($check['replay'])
                ? 'This HamroPay payment was already recorded.'
                : 'HamroPay payment Rs '.number_format((float) ($check['payment']->amount ?? 0), 2).' recorded.'.($bill->isPaid() ? ' Bill fully paid.' : ' Rs '.number_format($bill->outstanding(), 2).' still outstanding.')
        );
    }

    private function authorizeBill(Request $request, MarketplaceBill $bill): void
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);
        abort_unless(FinanceBranchScope::canSeeBranch($user, $bill->branch_id), 404, 'Bill not found.');
    }

    private function resolveBranch(Request $request, $requested): int
    {
        $user = $request->user();
        if (FinanceBranchScope::applies($user)) {
            $ids = FinanceBranchScope::branchIds($user);
            abort_if($ids === [], 403, 'No branch is assigned to your account.');
            if ($requested !== null && $requested !== '') {
                abort_unless(in_array((int) $requested, $ids, true), 404, 'Branch not found.');

                return (int) $requested;
            }

            return (int) $user->branch_id;
        }

        abort_if($requested === null || $requested === '', 422, 'Choose the billing branch.');

        return (int) $requested;
    }
}
