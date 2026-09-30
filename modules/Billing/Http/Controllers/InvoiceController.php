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
        $query = Invoice::with(['items', 'shipment'])->latest();
        
        // Merchant can only see their own invoices
        if ($request->user()->role === 'merchant') {
            $query->where('merchant_id', $request->user()->merchant_id);
        }
        
        // Admin filters
        if ($request->filled('merchant_id')) {
            $query->where('merchant_id', $request->merchant_id);
        }
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
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
        
        return ApiResponse::success($query->paginate($perPage));
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

        $invoice->update([
            'status' => 'paid',
        ]);

        return ApiResponse::success($invoice->fresh('items'), 'Invoice marked paid.');
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

        $sent = $this->billing->sendInvoiceEmail($invoice);
        
        if ($sent) {
            return ApiResponse::success(null, 'Invoice email sent successfully.');
        }
        
        return ApiResponse::error('Failed to send email. Merchant email may not be configured.', 500);
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

        // Dispatch job
        \Modules\Billing\Jobs\SendMerchantBillDigest::dispatch($merchantId, $period);

        return ApiResponse::success(null, "{$period} digest email queued for merchant.");
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

        $branchId = $invoice->branch_id;
        $branchAccount = $branchId
            ? $this->accounts->branchAccount((int) $branchId, 'hamropay')
            : null;

        if (! $branchAccount) {
            throw ValidationException::withMessages([
                'gateway' => ['Branch HamroPay account is not configured. Branch must save credentials under Payment gateways.'],
            ]);
        }

        $client = $this->accounts->hamroPayClientFromAccount($branchAccount);
        $branchMerchantId = $branchAccount->credential('merchant_id');
        if (! filled($branchMerchantId)) {
            throw ValidationException::withMessages([
                'gateway' => ['Branch HamroPay merchant_id is missing.'],
            ]);
        }

        $paisa = (int) round($amount * 100);
        $txnId = 'INVPAY-'.$invoice->id.'-'.Str::upper(Str::random(8));

        $session = $client->createSession([
            'merchantTxnId' => $txnId,
            'transactionAmount' => $paisa,
            'remarks' => 'Delivery bill '.$invoice->invoice_number,
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
            'checkout' => $params,
            'provider' => $session,
        ], 'HamroPay session created — merchant pays branch for delivery charges.');
    }
}
