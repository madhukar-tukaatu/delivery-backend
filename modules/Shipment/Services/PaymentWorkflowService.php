<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\DB;

class PaymentWorkflowService
{
    public function confirmCodDeposit(int $codId, int $actorId, array $payload = []): object
    {
        $cod = DB::table('cod_transactions')->where('id', $codId)->first();
        abort_unless($cod, 404, 'COD transaction not found.');

        $amount = (float) ($payload['amount'] ?? $cod->total_collected);

        DB::table('cod_transactions')->where('id', $codId)->update([
            'deposited_amount' => $amount,
            'status' => 'deposited',
            'deposited_at' => now(),
            'confirmed_by' => $actorId,
            'updated_at' => now(),
        ]);

        DB::table('shipment_tracking_events')->insert([
            'shipment_id' => $cod->shipment_id,
            'actor_id' => $actorId,
            'status' => 'cod_deposited',
            'title' => 'COD deposited',
            'description' => 'COD amount deposited and confirmed by accounts.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('cod_transactions')->where('id', $codId)->first();
    }

    public function createSettlement(int $merchantId, int $actorId, array $payload = []): object
    {
        $from = $payload['period_from'] ?? now()->startOfMonth()->toDateString();
        $to = $payload['period_to'] ?? now()->toDateString();

        $rows = DB::table('cod_transactions')
            ->join('shipments', 'shipments.id', '=', 'cod_transactions.shipment_id')
            ->where('cod_transactions.merchant_id', $merchantId)
            ->where('cod_transactions.status', 'deposited')
            ->whereBetween(DB::raw('DATE(cod_transactions.deposited_at)'), [$from, $to])
            ->select('cod_transactions.*', 'shipments.delivery_charge')
            ->get();

        $codTotal = (float) $rows->sum('cod_amount');
        $deliveryChargeTotal = (float) $rows->sum('delivery_charge');
        $payable = max($codTotal - $deliveryChargeTotal, 0);

        $number = app(TrackingNumberService::class)->settlementNumber();

        $id = DB::table('merchant_settlements')->insertGetId([
            'settlement_number' => $number,
            'merchant_id' => $merchantId,
            'period_from' => $from,
            'period_to' => $to,
            'shipment_count' => $rows->count(),
            'cod_total' => $codTotal,
            'delivery_charge_total' => $deliveryChargeTotal,
            'return_fee_total' => 0,
            'payable_amount' => $payable,
            'status' => 'pending',
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('merchant_settlements')->where('id', $id)->first();
    }

    public function markSettlementPaid(int $settlementId, int $actorId): object
    {
        DB::table('merchant_settlements')->where('id', $settlementId)->update([
            'status' => 'paid',
            'paid_by' => $actorId,
            'paid_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('merchant_settlements')->where('id', $settlementId)->first();
    }
}
