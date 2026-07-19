<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Shipment\Services\PaymentWorkflowService;

class AccountsPaymentLifecycleController extends Controller
{
    public function codPending(): JsonResponse
    {
        $rows = DB::table('pod_transactions')
            ->join('shipments', 'shipments.id', '=', 'pod_transactions.shipment_id')
            ->whereIn('pod_transactions.status', ['collected_pending_deposit', 'pending_collection'])
            ->select('pod_transactions.*', 'shipments.tracking_number', 'shipments.customer_name')
            ->latest('pod_transactions.id')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function confirmDeposit(Request $request, int $pod, PaymentWorkflowService $service): JsonResponse
    {
        $payload = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string'],
        ]);

        $row = $service->confirmCodDeposit($pod, $request->user()->id, $payload);

        return response()->json(['message' => 'POD deposit confirmed.', 'data' => $row]);
    }

    public function settlements(): JsonResponse
    {
        $rows = DB::table('merchant_settlements')->latest('id')->get();

        return response()->json(['data' => $rows]);
    }

    public function createSettlement(Request $request, PaymentWorkflowService $service): JsonResponse
    {
        $payload = $request->validate([
            'merchant_id' => ['required', 'integer'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date'],
        ]);

        $row = $service->createSettlement((int) $payload['merchant_id'], $request->user()->id, $payload);

        return response()->json(['message' => 'Settlement created.', 'data' => $row]);
    }

    public function markSettlementPaid(Request $request, int $settlement, PaymentWorkflowService $service): JsonResponse
    {
        $row = $service->markSettlementPaid($settlement, $request->user()->id);

        return response()->json(['message' => 'Settlement marked paid.', 'data' => $row]);
    }
}
