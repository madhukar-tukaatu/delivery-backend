<?php
// Verification for marketplace push + payment notices + merchant billing.
// Everything runs inside ONE DB transaction that is ROLLED BACK at the end.
// HTTP, queue and mail are faked; no users are created (merchant request user
// is an UNSAVED User model); no real gateway or webhook call happens.

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Http\Controllers\InvoiceController;
use Modules\Billing\Http\Controllers\MarketplaceBillController;
use Modules\Billing\Http\Controllers\MarketplaceBillingApiController;
use Modules\Billing\Http\Controllers\MerchantBillingController;
use Modules\Billing\Jobs\SendMarketplaceBillingWebhook;
use Modules\Billing\Models\BillingPaymentNotice;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\MarketplaceBill;
use Modules\Billing\Models\MarketplaceBillDelivery;
use Modules\Billing\Services\BranchShareService;
use Modules\Billing\Services\MarketplaceBillingService;
use Modules\Setting\Models\Marketplace;
use Symfony\Component\HttpKernel\Exception\HttpException;

const BRANCH = 19;        // Kathmandu Franchise
const OTHER_BRANCH = 18;  // Itahari
const MANAGER = 157;      // existing branch_manager of branch 19
const OTHER_MANAGER = 139;// existing branch_manager of branch 18
const MP = 4;             // Tukaatu Marketplace (has callback_url)
const MERCHANT = 50;      // existing store (Manohar Vastralaya)

/** Push target + HamroPay client faked (APP_KEY in this container cannot decrypt secrets / gateway creds). */
class FakeBilling extends MarketplaceBillingService
{
    public function pushTarget(?Marketplace $m): array
    {
        return ['url' => (string) ($m?->callback_url ?? ''), 'ready' => (int) $m?->id === MP, 'problem' => (int) $m?->id === MP ? null : 'test: no push'];
    }

    public function branchHamroPayClient(?int $branchId, ?Marketplace $marketplace = null): array
    {
        return [new FakeHamroPay(), 'TEST-GW-'.$branchId, null, 'https://fake.local'];
    }
}
class FakeHamroPay
{
    public static array $amounts = [];
    public function createSession(array $p, string $mid, $x = null): array { self::$amounts[$p['merchantTxnId']] = $p['transactionAmount']; return ['sessionId' => 'SESS-'.substr($p['merchantTxnId'], -6)]; }
    public function getTransaction(string $txn, $mid = null): array { return ['status' => 'success', 'transactionAmount' => self::$amounts[$txn] ?? 0]; }
    public function getGatewayUrl(): string { return 'https://fake.local/checkout'; }
    public function buildCheckoutParams(...$a): array { return ['sessionId' => $a[0], 'merchantTxnId' => $a[1], 'amount' => $a[2]]; }
}
class TestWebhookJob extends SendMarketplaceBillingWebhook
{
    protected function secretFor(Marketplace $marketplace): string { return 'test-secret'; }
}

function out(string $label, $v = null): void { echo str_pad($label, 58).($v === null ? '' : (is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_SLASHES))).PHP_EOL; }
function money($v): string { return number_format((float) $v, 2, '.', ''); }
function req(string $method, array $data = [], ?User $user = null, array $headers = [], array $attrs = []): Request
{
    $r = Request::create('/test', $method, $data);
    foreach ($headers as $k => $v) { $r->headers->set($k, $v); }
    foreach ($attrs as $k => $v) { $r->attributes->set($k, $v); }
    if ($user) { $r->setUserResolver(fn () => $user); }
    return $r;
}
/** Call a controller action, normalise errors to [status, message, data]. */
function call(callable $fn): array
{
    try {
        $res = $fn();
        return [$res->getStatusCode(), $res->getData(true)['message'] ?? null, $res->getData(true)];
    } catch (ValidationException $e) {
        return [422, collect($e->errors())->flatten()->first(), null];
    } catch (HttpException $e) {
        return [$e->getStatusCode(), $e->getMessage(), null];
    }
}
function billState(MarketplaceBill $b): string
{
    $b = $b->fresh();
    $pending = app(\Modules\Billing\Services\BillingPaymentNoticeService::class)->pendingSum('marketplace_bill', (int) $b->id);
    return 'status='.$b->status.' total='.money($b->total_amount).' paid='.money($b->paidSoFar()).' outstanding='.money($b->outstanding()).' pending_notices='.money($pending);
}
function invState(int $id): string
{
    $i = Invoice::query()->find($id);
    $p = app(\Modules\Billing\Services\MerchantInvoicePaymentService::class);
    $n = app(\Modules\Billing\Services\BillingPaymentNoticeService::class);
    return $i->invoice_number.' br'.$i->branch_id.' status='.$i->status.' total='.money($i->total_amount).' paid_amount='.money($i->paid_amount ?? 0).' outstanding='.money($p->outstanding($i)).' pending='.money($n->pendingSum('invoice', $id));
}
function pushed(string $event): array
{
    $jobs = Queue::pushed(SendMarketplaceBillingWebhook::class, fn ($j) => ($j->payload['event'] ?? null) === $event);
    return $jobs->all();
}

Queue::fake();
Mail::fake();
Http::fake(['*' => Http::response(['ok' => true], 200)]);
app()->instance(MarketplaceBillingService::class, new FakeBilling(app(\Modules\Billing\Services\PaymentGatewayAccountService::class)));

$manager = User::query()->findOrFail(MANAGER);
$other = User::query()->findOrFail(OTHER_MANAGER);
$merchantUser = new User(); // UNSAVED: no merchant users exist; nothing is created
$merchantUser->forceFill(['name' => 'unsaved merchant test user', 'role' => 'merchant', 'merchant_id' => MERCHANT]);

DB::beginTransaction();
try {
    echo "== FIXTURES (inside the rolled-back transaction) ==".PHP_EOL;
    $mk = function (array $a): Invoice {
        return Invoice::query()->create($a + ['type' => 'delivery_charges', 'status' => 'unpaid', 'subtotal' => $a['total_amount'], 'tax_amount' => 0, 'outstanding_amount' => $a['total_amount']]);
    };
    $c1 = $mk(['invoice_number' => 'TEST-MB-1', 'merchant_id' => null, 'branch_id' => BRANCH, 'marketplace_id' => MP, 'shipment_id' => 213, 'payer_type' => 'company', 'invoice_date' => '2026-10-01', 'total_amount' => 60000]);
    $c2 = $mk(['invoice_number' => 'TEST-MB-2', 'merchant_id' => null, 'branch_id' => BRANCH, 'marketplace_id' => MP, 'shipment_id' => 227, 'payer_type' => 'company', 'invoice_date' => '2026-10-02', 'total_amount' => 60000]);
    foreach ([213, 227] as $sid) {
        DB::table('shipment_branch_shares')->insert(['shipment_id' => $sid, 'branch_id' => BRANCH, 'role' => 'origin', 'position' => 1, 'branch_count' => 1, 'share_amount' => 100, 'net_amount' => 100,
            'collecting_branch_id' => BRANCH, 'collection_mode' => 'company_invoice', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }
    $billing = app(MarketplaceBillingService::class);
    $bill = $billing->createBill(BRANCH, MP, [$c1->id, $c2->id], null, null, MANAGER);
    out('bill created', $bill->bill_number.' total '.money($bill->total_amount));
    $eligible = fn () => app(BranchShareService::class)->eligibleQuery()->whereIn('shipment_branch_shares.shipment_id', [213, 227])->count();

    echo PHP_EOL."== 1. SEND -> push billing.bill.issued (email secondary; marketplace has no billing email) ==".PHP_EOL;
    $r = call(fn () => app(MarketplaceBillController::class)->send(req('POST', [], $manager), $bill));
    out('send HTTP / message', $r[0].' / '.$r[1]);
    $jobs = pushed('billing.bill.issued');
    out('queued billing.bill.issued jobs', count($jobs));
    $job = $jobs[0];
    $p = $job->payload;
    out('payload keys', array_keys($p));
    out('payload.bill keys', array_keys($p['bill']));
    out('bill_number / branch / period', $p['bill']['bill_number'].' / '.json_encode($p['bill']['branch'] ?? null).' / '.($p['bill']['period_start'] ?? '-').'..'.($p['bill']['period_end'] ?? '-'));
    out('lines', $p['bill']['lines']);
    out('totals (total/paid/outstanding)', json_encode([$p['bill']['total_amount'] ?? null, $p['bill']['paid_amount'] ?? null, $p['bill']['outstanding_amount'] ?? null]));
    out('how_to_pay', $p['bill']['how_to_pay']);
    $d = MarketplaceBillDelivery::query()->find($job->deliveryId);
    out('delivery row after queue', ['status' => $d->status, 'attempts' => $d->attempts, 'event_id' => $d->event_id]);
    // Run the job once with HTTP faked (secret stubbed) to show signing + delivery tracking.
    $t = new TestWebhookJob($job->marketplaceId, $job->payload, $job->deliveryId);
    $t->handle();
    $d->refresh();
    out('delivery row after run (Http faked 200)', ['status' => $d->status, 'http_status' => $d->http_status, 'attempts' => $d->attempts, 'delivered_at' => (string) $d->delivered_at]);
    $sent = Http::recorded()->last();
    $req0 = $sent[0];
    $sig = hash_hmac('sha256', $req0->header('X-Tukaatu-Timestamp')[0].'.'.$req0->body(), 'test-secret');
    out('signature header matches HMAC(ts.body)', $sig === $req0->header('X-Tukaatu-Signature')[0] ? 'yes' : 'NO');
    // failing endpoint -> attempts / last_error, row stays queued for retry
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(['*' => Http::response('down', 503)]);
    $t2 = new TestWebhookJob($job->marketplaceId, $job->payload, $job->deliveryId);
    try { $t2->handle(); } catch (\RuntimeException $e) { out('503 run throws (queue retries with backoff)', implode(',', $t2->backoff)); }
    $d->refresh();
    out('delivery row after 503', ['status' => $d->status, 'http_status' => $d->http_status, 'last_error' => $d->last_error]);
    $t2->failed(new \RuntimeException('final'));
    out('after failed() (tries exhausted)', MarketplaceBillDelivery::query()->find($job->deliveryId)->status);
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    out('bill after send', billState($bill));

    $api = app(MarketplaceBillingApiController::class);
    $mpReq = fn (array $data, string $rid) => req('POST', $data, null, ['X-Tukaatu-Request-Id' => $rid], ['marketplace_id' => MP]);

    echo PHP_EOL."== 2. Marketplace payment notice 50,000 ==".PHP_EOL;
    $r = call(fn () => $api->paymentNotice($mpReq(['amount' => 50000, 'method' => 'bank_transfer', 'reference' => 'NIC-TT-001', 'paid_at' => '2026-10-10'], 'n-1'), $bill->bill_number));
    out('HTTP / message', $r[0].' / '.$r[1]);
    $n1 = $r[2]['notice']['notice_id'];
    out('notice status', $r[2]['notice']['status']);
    out('bill', billState($bill));
    out('webhook billing.payment_notice.received queued', count(pushed('billing.payment_notice.received')));
    out('branch notification rows (notification_logs) for notice', DB::table('notification_logs')->where('created_at', '>=', now()->subMinute())->count());

    echo PHP_EOL."== 7. Manager of ANOTHER branch tries to verify -> 404 ==".PHP_EOL;
    $r = call(fn () => app(MarketplaceBillController::class)->verifyNotice(req('POST', [], $other), $bill->fresh(), $n1));
    out('HTTP / message', $r[0].' / '.$r[1]);
    out('bill unchanged', billState($bill));

    echo PHP_EOL."== 3. Branch manager verifies 50,000 ==".PHP_EOL;
    $r = call(fn () => app(MarketplaceBillController::class)->verifyNotice(req('POST', ['amount' => 50000], $manager), $bill->fresh(), $n1));
    out('HTTP / message', $r[0].' / '.$r[1]);
    out('bill', billState($bill));
    out('payment source', DB::table('marketplace_bill_payments')->where('notice_id', $n1)->value('source'));
    out('invoices', [invState($c1->id), invState($c2->id)]);
    out('shares eligible (must be 0 until fully paid)', $eligible());
    out('webhooks: payment_recorded / notice.verified', count(pushed('billing.bill.payment_recorded')).' / '.count(pushed('billing.payment_notice.verified')));

    echo PHP_EOL."== 8. Over-amount, zero, idempotent replay ==".PHP_EOL;
    $r = call(fn () => $api->paymentNotice($mpReq(['amount' => 70000.01, 'method' => 'bank_transfer', 'reference' => 'X'], 'n-over'), $bill->bill_number));
    out('over-amount 70,000.01 -> HTTP / message', $r[0].' / '.$r[1]);
    $r = call(fn () => $api->paymentNotice($mpReq(['amount' => 0, 'method' => 'bank_transfer', 'reference' => 'X'], 'n-zero'), $bill->bill_number));
    out('zero -> HTTP / message', $r[0].' / '.$r[1]);
    $r = call(fn () => $api->paymentNotice($mpReq(['amount' => 10000, 'method' => 'bank_transfer', 'reference' => 'NIC-TT-002'], 'n-2'), $bill->bill_number));
    out('notice 10,000 (to be rejected) HTTP', $r[0]);
    $n2 = $r[2]['notice']['notice_id'];
    $r = call(fn () => $api->paymentNotice($mpReq(['amount' => 10000, 'method' => 'bank_transfer', 'reference' => 'NIC-TT-002'], 'n-2'), $bill->bill_number));
    out('same X-Tukaatu-Request-Id again -> HTTP / replay / id', $r[0].' / '.json_encode($r[2]['idempotent_replay']).' / '.$r[2]['notice']['notice_id']);
    out('bill (pending counted once)', billState($bill));
    $r = call(fn () => $api->pay($mpReq(['amount' => 60000.01, 'method' => 'x', 'reference' => 'y'], 'n-legacy'), $bill->bill_number));
    out('legacy /pay over claimable (70,000 - 10,000 pending) -> HTTP', $r[0].' / '.$r[1]);

    echo PHP_EOL."== 5. Reject a notice ==".PHP_EOL;
    $before = billState($bill);
    $r = call(fn () => app(MarketplaceBillController::class)->rejectNotice(req('POST', ['reason' => ''], $manager), $bill->fresh(), $n2));
    out('reject without reason -> HTTP', $r[0].' / '.$r[1]);
    $r = call(fn () => app(MarketplaceBillController::class)->rejectNotice(req('POST', ['reason' => 'No credit in NIC statement for NIC-TT-002'], $manager), $bill->fresh(), $n2));
    out('reject HTTP / message', $r[0].' / '.$r[1]);
    out('bill before', $before);
    out('bill after', billState($bill));
    $rej = pushed('billing.payment_notice.rejected');
    out('webhook billing.payment_notice.rejected queued', count($rej).' notice.status='.($rej[0]->payload['notice']['status'] ?? '?').' reason='.($rej[0]->payload['notice']['reject_reason'] ?? '?'));

    echo PHP_EOL."== 4. Notice for the rest, manager verifies -> paid, shares eligible ==".PHP_EOL;
    $r = call(fn () => $api->paymentNotice($mpReq(['method' => 'bank_transfer', 'reference' => 'NIC-TT-003'], 'n-3'), $bill->bill_number));
    out('notice (amount omitted = all claimable) HTTP / amount', $r[0].' / '.$r[2]['notice']['amount']);
    $n3 = $r[2]['notice']['notice_id'];
    out('bill', billState($bill));
    $r = call(fn () => app(MarketplaceBillController::class)->verifyNotice(req('POST', [], $manager), $bill->fresh(), $n3));
    out('verify HTTP / message', $r[0].' / '.$r[1]);
    out('bill', billState($bill));
    out('invoices', [invState($c1->id), invState($c2->id)]);
    out('shares eligible', $eligible());
    out('webhooks payment_recorded / paid', count(pushed('billing.bill.payment_recorded')).' / '.count(pushed('billing.bill.paid')));
    $r = call(fn () => app(MarketplaceBillController::class)->verifyNotice(req('POST', [], $manager), $bill->fresh(), $n3));
    out('verify same notice again -> HTTP', $r[0].' / '.$r[1]);

    echo PHP_EOL."== Reverse (kept): reverse the 50,000 payment ==".PHP_EOL;
    $pid = DB::table('marketplace_bill_payments')->where('notice_id', $n1)->value('id');
    $r = call(fn () => app(MarketplaceBillController::class)->reversePayment(req('POST', ['reason' => 'Bounced cheque'], $manager), $bill->fresh(), $pid));
    out('reverse HTTP / message', $r[0].' / '.$r[1]);
    out('bill', billState($bill));
    out('shares eligible', $eligible());
    out('webhook payment_reversed', count(pushed('billing.bill.payment_reversed')));
    $deliv = MarketplaceBillDelivery::query()->where('marketplace_bill_id', $bill->id)->pluck('event')->countBy()->all();
    out('delivery rows per event (admin shows these)', $deliv);

    echo PHP_EOL."== 6. Merchant pay-all with amount (unsaved merchant user, store ".MERCHANT.") ==".PHP_EOL;
    $m1 = $mk(['invoice_number' => 'TEST-MI-1', 'merchant_id' => MERCHANT, 'branch_id' => BRANCH, 'payer_type' => 'merchant', 'invoice_date' => '2026-10-01', 'total_amount' => 500]);
    $m2 = $mk(['invoice_number' => 'TEST-MI-2', 'merchant_id' => MERCHANT, 'branch_id' => OTHER_BRANCH, 'payer_type' => 'merchant', 'invoice_date' => '2026-10-03', 'total_amount' => 300]);
    $mc = app(MerchantBillingController::class);
    $r = call(fn () => $mc->summary(req('GET', [], $merchantUser)));
    out('summary outstanding / by_branch', $r[2]['data']['outstanding'].' / '.json_encode(array_map(fn ($b) => [$b['branch_id'], $b['outstanding']], $r[2]['data']['by_branch'])));
    $r = call(fn () => $mc->payAllPreview(req('GET', ['amount' => 700], $merchantUser)));
    out('preview 700 (oldest first)', array_map(fn ($l) => $l['number'].':'.$l['amount'].' (due '.$l['due_before'].'->'.$l['due_after'].')', $r[2]['data']['lines']));
    out('preview by_branch', array_map(fn ($b) => $b['branch_id'].':'.$b['amount'], $r[2]['data']['by_branch']));
    $r = call(fn () => $mc->payAll(req('POST', ['amount' => 700, 'method' => 'bank_transfer', 'reference' => 'STORE-50-OCT', 'request_id' => 'pa-1'], $merchantUser)));
    out('pay-all HTTP / message', $r[0].' / '.$r[1]);
    $batch = $r[2]['data'];
    out('notices', array_map(fn ($n) => $n['number'].' '.$n['amount'].' '.$n['status'], $batch['notices']));
    out('invoices (unchanged money)', [invState(5), invState($m1->id), invState($m2->id)]);
    $r = call(fn () => $mc->payAll(req('POST', ['amount' => 700, 'method' => 'bank_transfer', 'reference' => 'STORE-50-OCT', 'request_id' => 'pa-1'], $merchantUser)));
    out('pay-all replay HTTP / replay', $r[0].' / '.json_encode($r[2]['data']['replay']));
    $r = call(fn () => $mc->payAll(req('POST', ['amount' => 500, 'method' => 'bank_transfer'], $merchantUser)));
    out('pay-all more than claimable (899-700=199) -> HTTP', $r[0].' / '.$r[1]);
    $r = call(fn () => $mc->payAll(req('POST', ['amount' => 0, 'method' => 'bank_transfer'], $merchantUser)));
    out('pay-all zero -> HTTP', $r[0].' / '.$r[1]);

    $ic = app(InvoiceController::class);
    $byInv = collect($batch['notices'])->keyBy('number');
    $r = call(fn () => $ic->verifyNotice(req('POST', [], $other), Invoice::find(5), $byInv['INV-DLV-20260930132518-229']['notice_id']));
    out('other-branch manager verifies branch-19 notice -> HTTP', $r[0].' / '.$r[1]);
    $r = call(fn () => $ic->verifyNotice(req('POST', [], $manager), Invoice::find(5), $byInv['INV-DLV-20260930132518-229']['notice_id']));
    out('manager verifies INV ...229 (99) -> HTTP', $r[0].' / '.$r[1]);
    $r = call(fn () => $ic->verifyNotice(req('POST', ['amount' => 250], $manager), $m1->fresh(), $byInv['TEST-MI-1']['notice_id']));
    out('manager verifies TEST-MI-1 with EDITED amount 250 (notice 500)', $r[0].' / '.$r[1]);
    $r = call(fn () => $ic->verifyNotice(req('POST', [], $other), $m2->fresh(), $byInv['TEST-MI-2']['notice_id']));
    out('branch-18 manager verifies TEST-MI-2 (101) -> HTTP', $r[0].' / '.$r[1]);
    out('invoices after verification', [invState(5), invState($m1->id), invState($m2->id)]);

    echo PHP_EOL."== Merchant single notice by invoice number + old mark-paid route ==".PHP_EOL;
    $r = call(fn () => $mc->lookup(req('GET', ['number' => 'TEST-MI-1'], $merchantUser)));
    out('lookup TEST-MI-1 claimable', $r[2]['data']['claimable']);
    $r = call(fn () => $mc->notice(req('POST', ['amount' => 100, 'method' => 'esewa', 'reference' => 'ES-1'], $merchantUser), $m1->id));
    out('partial notice 100 -> HTTP / status', $r[0].' / '.$r[2]['data']['notice']['status']);
    $r = call(fn () => $ic->markPaid(req('POST', ['paid_amount' => 50, 'payment_reference' => 'OLD-BTN'], $merchantUser), $m1->fresh()));
    out('old merchant mark-paid route -> HTTP / message', $r[0].' / '.$r[1]);
    out('TEST-MI-1', invState($m1->id));
    $r = call(fn () => $mc->show(req('GET', [], $merchantUser), 6));
    out('merchant opens another store\'s invoice #6 -> HTTP', $r[0].' / '.$r[1]);

    echo PHP_EOL."== Admin direct Record payment (partial) + Reverse on a store bill ==".PHP_EOL;
    $r = call(fn () => $ic->markPaid(req('POST', ['amount' => 50, 'method' => 'cash', 'reference' => 'CASH-1', 'request_id' => 'rp-1'], $manager), $m1->fresh()));
    out('record 50 -> HTTP / message', $r[0].' / '.$r[1]);
    $r = call(fn () => $ic->markPaid(req('POST', ['amount' => 50, 'method' => 'cash', 'reference' => 'CASH-1', 'request_id' => 'rp-1'], $manager), $m1->fresh()));
    out('same request_id again', $r[0].' / '.$r[1]);
    out('TEST-MI-1', invState($m1->id));
    $pid = DB::table('merchant_invoice_payments')->where('invoice_id', 5)->value('id');
    $r = call(fn () => $ic->reversePayment(req('POST', ['reason' => 'Money not in account'], $manager), Invoice::find(5), $pid));
    out('reverse INV ...229 payment -> HTTP / message', $r[0].' / '.$r[1]);
    out('INV ...229', invState(5));

    echo PHP_EOL."== HamroPay (only auto-confirmed path; client faked) ==".PHP_EOL;
    $r = call(fn () => $mc->hamroPay(req('POST', ['branch_id' => OTHER_BRANCH], $merchantUser)));
    out('start HTTP / message', $r[0].' / '.$r[1]);
    $txn = $r[2]['data']['sessions'][0]['merchant_txn_id'] ?? null;
    out('session', ['txn' => $txn, 'amount' => $r[2]['data']['sessions'][0]['amount'] ?? null]);
    out('TEST-MI-2 before confirm', invState($m2->id));
    $r = call(fn () => $mc->confirmHamroPay(req('POST', ['merchant_txn_id' => $txn], $merchantUser)));
    out('confirm HTTP / message', $r[0].' / '.$r[1]);
    out('TEST-MI-2 after confirm', invState($m2->id).' source='.DB::table('merchant_invoice_payments')->where('invoice_id', $m2->id)->orderByDesc('id')->value('source'));
    $r = call(fn () => $mc->confirmHamroPay(req('POST', ['merchant_txn_id' => $txn], $merchantUser)));
    out('confirm again', $r[0].' / '.$r[1]);

    echo PHP_EOL."== Admin presenters ==".PHP_EOL;
    $r = call(fn () => app(MarketplaceBillController::class)->show(req('GET', [], $manager), $bill->fresh()));
    out('bill detail keys incl.', array_values(array_intersect(array_keys($r[2]['data']), ['notices', 'deliveries', 'pending_notices', 'last_push', 'push_target'])));
    out('notices on bill', array_map(fn ($n) => $n['id'].':'.$n['amount'].':'.$n['status'], $r[2]['data']['notices']));
    $r = call(fn () => $ic->notices(req('GET', ['status' => 'pending_verification'], $manager)));
    out('admin pending store notices (branch 19 manager)', array_map(fn ($n) => $n['number'].':'.$n['amount'], $r[2]['data']['data']));
    out('emails captured by Mail::fake (not sent)', 'ok');
} finally {
    DB::rollBack();
    echo PHP_EOL.'ROLLED BACK. bills now: '.MarketplaceBill::query()->count().', notices: '.BillingPaymentNotice::query()->count().', merchant payments: '.DB::table('merchant_invoice_payments')->count().', TEST invoices: '.Invoice::query()->where('invoice_number', 'like', 'TEST-%')->count().PHP_EOL;
}