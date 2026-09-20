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
        $query = Invoice::with('items')->latest();
        if ($request->user()->role === 'merchant') {
            $query->where('merchant_id', $request->user()->merchant_id);
        }
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

        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
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
