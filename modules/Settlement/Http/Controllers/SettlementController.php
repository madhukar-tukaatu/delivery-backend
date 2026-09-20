<?php

namespace Modules\Settlement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\POD\Models\PodRecord;
use Modules\Settlement\Models\MerchantSettlement;
use Modules\Settlement\Models\MerchantSettlementItem;
use Modules\Shipment\Models\Shipment;

class SettlementController extends Controller
{
    public function index(Request $request)
    {
        $query = MerchantSettlement::with('items')->latest();
        if ($request->user()->role === 'merchant') $query->where('merchant_id', $request->user()->merchant_id);
        if ($request->filled('merchant_id')) $query->where('merchant_id', $request->merchant_id);
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => ['required', 'exists:merchants,id'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date'],
            'adjustments' => ['nullable', 'numeric'],
            // after_deposit (default, preferred): only cash already deposited at branch.
            // on_collection: pay merchant from rider-collected cash before / without deposit.
            'cash_path' => ['nullable', 'string', 'in:after_deposit,on_collection'],
        ]);

        $cashPath = $data['cash_path'] ?? 'after_deposit';

        $settlement = DB::transaction(function () use ($data, $cashPath) {
            $query = Shipment::query()
                ->where('merchant_id', $data['merchant_id'])
                ->where('status', 'delivered')
                ->where(function ($q) {
                    $q->whereNull('pod_status')
                        ->orWhereNotIn('pod_status', ['paid_direct']);
                });

            if ($cashPath === 'on_collection') {
                // Rider holds cash for merchant; settle before branch deposit.
                $query->where('settlement_status', 'pending_deposit')
                    ->where('pod_status', 'collected');
            } else {
                // Preferred: cash already deposited at branch.
                $query->where('settlement_status', 'ready');
            }

            $shipments = $query->get();

            if ($shipments->isEmpty()) {
                $message = $cashPath === 'on_collection'
                    ? 'No collected cash POD shipments are waiting to settle for this merchant (before deposit).'
                    : 'No deposited cash POD shipments are ready to settle for this merchant.';

                throw ValidationException::withMessages([
                    'merchant_id' => [$message],
                ]);
            }

            $totalCod = $shipments->sum('pod_amount');
            $deliveryCharges = $shipments->sum('delivery_charge');
            $codCharges = $shipments->sum('pod_charge');
            $adjustments = (float) ($data['adjustments'] ?? 0);
            $final = $totalCod - $deliveryCharges - $codCharges + $adjustments;

            $payload = [
                'merchant_id' => $data['merchant_id'],
                'settlement_number' => 'SET-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'period_from' => $data['period_from'] ?? null,
                'period_to' => $data['period_to'] ?? null,
                'total_pod_collected' => $totalCod,
                'total_delivery_charges' => $deliveryCharges,
                'total_pod_charges' => $codCharges,
                'adjustments' => $adjustments,
                'final_payable_amount' => $final,
                'status' => 'pending',
            ];

            if (Schema::hasColumn('merchant_settlements', 'cash_path')) {
                $payload['cash_path'] = $cashPath;
            }

            $settlement = MerchantSettlement::create($payload);

            foreach ($shipments as $shipment) {
                MerchantSettlementItem::create([
                    'merchant_settlement_id' => $settlement->id,
                    'shipment_id' => $shipment->id,
                    'pod_amount' => $shipment->pod_amount,
                    'delivery_charge' => $shipment->delivery_charge,
                    'pod_charge' => $shipment->pod_charge,
                    'net_amount' => $shipment->pod_amount - $shipment->delivery_charge - $shipment->pod_charge,
                ]);
                $shipment->update(['settlement_status' => 'processing']);
            }

            return $settlement;
        });

        $label = $cashPath === 'on_collection'
            ? 'Settlement generated from collected cash (before deposit).'
            : 'Settlement generated from deposited cash shipments.';

        return ApiResponse::success($settlement->load('items'), $label, 201);
    }

    public function markPaid(Request $request, MerchantSettlement $settlement)
    {
        $data = $request->validate([
            'payment_method' => ['nullable', 'string'],
            'bank_reference_number' => ['nullable', 'string'],
        ]);
        DB::transaction(function () use ($settlement, $data, $request) {
            $settlement->update([
                'status' => 'settled',
                'payment_method' => $data['payment_method'] ?? null,
                'bank_reference_number' => $data['bank_reference_number'] ?? null,
                'settled_by' => $request->user()->id,
                'settled_at' => now(),
            ]);
            $shipmentIds = $settlement->items()->pluck('shipment_id');
            Shipment::whereIn('id', $shipmentIds)->update(['settlement_status' => 'settled']);

            // Cover both paths: deposited (after_deposit) and still-collected (on_collection).
            PodRecord::whereIn('shipment_id', $shipmentIds)
                ->whereIn('status', ['deposited', 'collected'])
                ->where(function ($q) {
                    $q->whereNull('payment_destination')
                        ->orWhere('payment_destination', '!=', 'merchant');
                })
                ->update(['status' => 'settled', 'settled_at' => now()]);
        });
        return ApiResponse::success($settlement->fresh('items'), 'Settlement marked paid.');
    }
}
