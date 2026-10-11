<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\MarketplaceBill;
use Modules\Billing\Models\MarketplaceBillPayment;
use Modules\Billing\Services\BillingPaymentNoticeService;
use Modules\Billing\Services\MarketplaceBillPaymentService;
use Modules\Billing\Services\MarketplaceBillingService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Marketplace-facing billing API (marketplace.api-key:billing).
 * The marketplace id comes from the verified API key; a marketplace only ever
 * sees its own bills that a branch has sent (drafts are internal).
 * Bills are PUSHED to the marketplace (signed webhooks); this API is for
 * reading them and for payment notices. A marketplace never marks a bill paid:
 * the billing branch manager verifies the notice (HamroPay is the only
 * automatically confirmed payment).
 */
class MarketplaceBillingApiController extends Controller
{
    public function __construct(
        private MarketplaceBillingService $billing,
        private MarketplaceBillPaymentService $payments,
        private BillingPaymentNoticeService $notices,
    ) {
    }

    /** GET v1/marketplace/billing/bills?status=unpaid|partially_paid|paid|cancelled&from=&to=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $q = $this->visible($request)->select('marketplace_bills.*')->with('branch:id,name,code')->latest('sent_at')->latest('id');

        $status = strtolower(trim((string) $request->query('status', '')));
        if ($status === 'unpaid' || $status === 'outstanding') {
            // Anything with money still due (incl. partially paid).
            $q->whereIn('status', MarketplaceBill::PAYABLE_STATUSES);
        } elseif (in_array($status, ['paid', 'cancelled', 'sent', 'reopened', 'partially_paid'], true)) {
            $q->where('status', $status);
        }
        if ($request->filled('from')) {
            $q->whereDate('sent_at', '>=', $request->query('from'));
        }
        if ($request->filled('to')) {
            $q->whereDate('sent_at', '<=', $request->query('to'));
        }
        if ($request->filled('branch')) {
            $branch = trim((string) $request->query('branch'));
            $q->whereIn('marketplace_bills.branch_id', \Modules\Branch\Models\Branch::query()->where('code', $branch)->orWhere('id', ctype_digit($branch) ? (int) $branch : 0)->select('id'));
        }
        if ($request->filled('search')) {
            $q->where('marketplace_bills.bill_number', 'like', '%'.trim((string) $request->query('search')).'%');
        }

        $page = $q->paginate(min(max((int) $request->query('per_page', 50), 1), 200));

        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn ($b) => $this->billing->presentForMarketplace($b))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /** GET v1/marketplace/billing/bills/{billNumber} */
    public function show(Request $request, string $billNumber): JsonResponse
    {
        $bill = $this->find($request, $billNumber);

        return response()->json(['success' => true, 'data' => $this->billing->presentForMarketplace($bill, true)]);
    }

    /**
     * GET v1/marketplace/billing/bills/{billNumber}/payable
     * "Generate the amount to pay": total, paid so far, amount due (= max), min, reference.
     */
    public function payable(Request $request, string $billNumber): JsonResponse
    {
        $bill = $this->find($request, $billNumber);

        return response()->json(['success' => true, 'data' => $this->payments->payable($bill)]);
    }

    /**
     * POST v1/marketplace/billing/bills/{billNumber}/payment-notice
     * {amount?, method, reference, paid_at?, note?}
     *
     * The marketplace TELLS us it paid. This creates a payment notice with status
     * pending_verification. It does NOT change paid / outstanding: the billing
     * branch manager checks the money arrived and verifies it (then the payment
     * is recorded and billing.bill.payment_recorded / billing.bill.paid are
     * pushed) or rejects it (billing.payment_notice.rejected is pushed).
     * Partial amounts are allowed (0.01 .. outstanding minus notices already
     * waiting). amount omitted = everything still claimable.
     * Idempotent per X-Tukaatu-Request-Id (same id = same notice).
     */
    public function paymentNotice(Request $request, string $billNumber): JsonResponse
    {
        $bill = $this->find($request, $billNumber);

        $v = Validator::make($request->all(), [
            'amount' => ['nullable', 'numeric'],
            'method' => ['required', 'string', 'max:64'],
            'reference' => ['required', 'string', 'max:191'],
            'paid_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $v->errors()], 422);
        }
        $data = $v->validated();
        $marketplaceId = (int) $request->attributes->get('marketplace_id');
        $rawRequestId = trim((string) $request->header('X-Tukaatu-Request-Id'));
        // Namespaced per marketplace so two marketplaces can never collide.
        $requestId = $rawRequestId !== '' ? 'mp:'.$marketplaceId.':'.$rawRequestId : null;

        try {
            $result = $this->notices->submit(
                'marketplace_bill',
                $bill,
                $data['amount'] ?? null,
                $data['method'],
                $data['reference'],
                $data['paid_at'] ?? null,
                $data['note'] ?? null,
                'marketplace_api',
                ['type' => 'marketplace', 'id' => $marketplaceId],
                $requestId,
            );
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
                'data' => ['payable' => $this->payments->payable($bill->fresh())],
            ], 422);
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 409) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'data' => $this->billing->presentForMarketplace($bill->fresh()),
                ], 409);
            }
            throw $e;
        }

        $bill = $bill->fresh();

        return response()->json([
            'success' => true,
            'message' => $result['replay']
                ? 'Already received (same request id).'
                : 'Payment notice received. The billing branch will verify it; the bill is not marked paid until then.',
            'idempotent_replay' => $result['replay'],
            'notice' => $this->notices->present($result['notice'], false),
            'totals' => [
                'currency' => 'NPR',
                'total_amount' => round((float) $bill->total_amount, 2),
                'paid_amount' => $bill->paidSoFar(),
                'outstanding_amount' => $bill->outstanding(),
                'pending_verification_amount' => $this->notices->pendingSum('marketplace_bill', (int) $bill->id),
                'status' => $this->billing->presentForMarketplace($bill)['status'],
            ],
            'data' => $this->billing->presentForMarketplace($bill),
        ], $result['replay'] ? 200 : 202);
    }

    /**
     * POST v1/marketplace/billing/bills/{billNumber}/pay (kept for existing clients).
     * Same as payment-notice: it no longer records a payment, it creates a notice
     * that the branch verifies (HTTP 202).
     */
    public function pay(Request $request, string $billNumber): JsonResponse
    {
        return $this->paymentNotice($request, $billNumber);
    }

    /** GET v1/marketplace/billing/bills/{billNumber}/payment-notices */
    public function notices(Request $request, string $billNumber): JsonResponse
    {
        $bill = $this->find($request, $billNumber);

        return response()->json([
            'success' => true,
            'data' => $this->notices->forTarget('marketplace_bill', (int) $bill->id, false),
        ]);
    }

    /** POST v1/marketplace/billing/bills/{billNumber}/hamropay {amount?}: HamroPay checkout paying the billing branch. */
    public function hamroPay(Request $request, string $billNumber): JsonResponse
    {
        $bill = $this->find($request, $billNumber);
        $v = Validator::make($request->all(), ['amount' => ['nullable', 'numeric']]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed.', 'errors' => $v->errors()], 422);
        }

        try {
            $session = $this->billing->startBillHamroPay($bill, $v->validated()['amount'] ?? null);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }

        return response()->json(['success' => true, 'message' => 'HamroPay checkout created.', 'data' => $session], 201);
    }

    /** POST v1/marketplace/billing/bills/{billNumber}/hamropay/confirm {merchant_txn_id} */
    public function confirmHamroPay(Request $request, string $billNumber): JsonResponse
    {
        $bill = $this->find($request, $billNumber);
        $txn = trim((string) $request->input('merchant_txn_id', ''));
        if (! str_starts_with($txn, 'MBPAY-'.$bill->id.'-')) {
            return response()->json(['success' => false, 'message' => 'This transaction is not for this bill.'], 422);
        }

        try {
            $check = $this->billing->confirmHamroPayTransaction($txn);
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'message' => collect($e->errors())->flatten()->first()], 422);
        } catch (HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }

        if (! $check['paid']) {
            return response()->json(['success' => false, 'message' => 'HamroPay has not confirmed this payment yet.', 'status' => $check['status']], 422);
        }

        return response()->json([
            'success' => true,
            'message' => ! empty($check['replay']) ? 'Payment already recorded.' : 'Payment confirmed.',
            'payment' => isset($check['payment']) ? $this->presentPayment($check['payment']) : null,
            'data' => $this->billing->presentForMarketplace($check['bill']->fresh()),
        ]);
    }

    /** GET v1/marketplace/billing/summary */
    public function summary(Request $request): JsonResponse
    {
        $base = $this->visible($request);
        $outstanding = (clone $base)->whereIn('status', MarketplaceBill::PAYABLE_STATUSES)->selectRaw('COUNT(*) c, COALESCE(SUM(outstanding_amount),0) t')->first();
        $partial = (clone $base)->where('status', 'partially_paid')->selectRaw('COUNT(*) c, COALESCE(SUM(paid_amount),0) p, COALESCE(SUM(outstanding_amount),0) o')->first();
        $monthStart = now()->startOfMonth();
        $paidMonth = MarketplaceBillPayment::query()
            ->where('status', 'recorded')
            ->where('paid_at', '>=', $monthStart)
            ->whereIn('marketplace_bill_id', (clone $base)->select('marketplace_bills.id'))
            ->selectRaw('COUNT(*) c, COALESCE(SUM(amount),0) t')->first();
        $fullyPaidMonth = (clone $base)->where('status', 'paid')->where('paid_at', '>=', $monthStart)->count();
        $byBranch = (clone $base)->whereIn('marketplace_bills.status', MarketplaceBill::PAYABLE_STATUSES)
            ->join('branches', 'branches.id', '=', 'marketplace_bills.branch_id')
            ->groupBy('branches.code', 'branches.name')
            ->selectRaw('branches.code, branches.name, COUNT(*) c, COALESCE(SUM(marketplace_bills.outstanding_amount),0) t')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'currency' => 'NPR',
                'outstanding' => ['count' => (int) $outstanding->c, 'total' => round((float) $outstanding->t, 2)],
                'partially_paid' => ['count' => (int) $partial->c, 'paid' => round((float) $partial->p, 2), 'outstanding' => round((float) $partial->o, 2)],
                'paid_this_month' => [
                    'payments' => (int) $paidMonth->c,
                    'total' => round((float) $paidMonth->t, 2),
                    'bills_fully_paid' => $fullyPaidMonth,
                    'since' => $monthStart->toDateString(),
                ],
                'outstanding_by_branch' => $byBranch->map(fn ($r) => [
                    'branch_code' => $r->code,
                    'branch_name' => $r->name,
                    'count' => (int) $r->c,
                    'total' => round((float) $r->t, 2),
                ])->values(),
            ],
        ]);
    }

    private function presentPayment(MarketplaceBillPayment $p): array
    {
        return [
            'payment_id' => $p->id,
            'amount' => round((float) $p->amount, 2),
            'method' => $p->method,
            'reference' => $p->reference,
            'paid_at' => $p->paid_at?->toIso8601String(),
            'source' => $p->source,
            'status' => $p->status,
        ];
    }

    private function visible(Request $request)
    {
        $marketplaceId = (int) $request->attributes->get('marketplace_id');
        abort_if($marketplaceId <= 0, 401, 'Marketplace authentication is required.');

        return MarketplaceBill::query()
            ->where('marketplace_bills.marketplace_id', $marketplaceId)
            ->whereNotNull('marketplace_bills.sent_at');
    }

    private function find(Request $request, string $billNumber): MarketplaceBill
    {
        $bill = $this->visible($request)->select('marketplace_bills.*')->where('marketplace_bills.bill_number', $billNumber)->first();
        abort_unless($bill, 404, 'Bill not found.');

        return $bill;
    }
}
