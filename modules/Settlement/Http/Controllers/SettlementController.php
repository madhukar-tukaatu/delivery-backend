<?php

namespace Modules\Settlement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\COD\Models\CodRecord;
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
        ]);

        $settlement = DB::transaction(function () use ($data) {
            $shipments = Shipment::where('merchant_id', $data['merchant_id'])
                ->where('status', 'delivered')
                ->where('settlement_status', 'ready')
                ->get();

            $totalCod = $shipments->sum('cod_amount');
            $deliveryCharges = $shipments->sum('delivery_charge');
            $codCharges = $shipments->sum('cod_charge');
            $adjustments = (float) ($data['adjustments'] ?? 0);
            $final = $totalCod - $deliveryCharges - $codCharges + $adjustments;

            $settlement = MerchantSettlement::create([
                'merchant_id' => $data['merchant_id'],
                'settlement_number' => 'SET-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'period_from' => $data['period_from'] ?? null,
                'period_to' => $data['period_to'] ?? null,
                'total_cod_collected' => $totalCod,
                'total_delivery_charges' => $deliveryCharges,
                'total_cod_charges' => $codCharges,
                'adjustments' => $adjustments,
                'final_payable_amount' => $final,
                'status' => 'pending',
            ]);

            foreach ($shipments as $shipment) {
                MerchantSettlementItem::create([
                    'merchant_settlement_id' => $settlement->id,
                    'shipment_id' => $shipment->id,
                    'cod_amount' => $shipment->cod_amount,
                    'delivery_charge' => $shipment->delivery_charge,
                    'cod_charge' => $shipment->cod_charge,
                    'net_amount' => $shipment->cod_amount - $shipment->delivery_charge - $shipment->cod_charge,
                ]);
                $shipment->update(['settlement_status' => 'processing']);
            }

            return $settlement;
        });

        return ApiResponse::success($settlement->load('items'), 'Settlement generated.', 201);
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
            CodRecord::whereIn('shipment_id', $shipmentIds)->update(['status' => 'settled', 'settled_at' => now()]);
        });
        return ApiResponse::success($settlement->fresh('items'), 'Settlement marked paid.');
    }
}
