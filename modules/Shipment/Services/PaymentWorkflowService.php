<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\DB;

class PaymentWorkflowService
{
    public function confirmCodDeposit(int $codId, int $actorId, array $payload = []): object
    {
        $pod = DB::table('pod_transactions')->where('id', $codId)->first();
        abort_unless($pod, 404, 'POD transaction not found.');

        $amount = (float) ($payload['amount'] ?? $pod->total_collected);

        DB::table('pod_transactions')->where('id', $codId)->update([
            'deposited_amount' => $amount,
            'status' => 'deposited',
            'deposited_at' => now(),
            'confirmed_by' => $actorId,
            'updated_at' => now(),
        ]);

        DB::table('shipment_tracking_events')->insert([
            'shipment_id' => $pod->shipment_id,
            'actor_id' => $actorId,
            'status' => 'pod_deposited',
            'title' => 'POD deposited',
            'description' => 'POD amount deposited and confirmed by accounts.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('pod_transactions')->where('id', $codId)->first();
    }

    public function createSettlement(int $merchantId, int $actorId, array $payload = []): object
    {
        $from = $payload['period_from'] ?? now()->startOfMonth()->toDateString();
        $to = $payload['period_to'] ?? now()->toDateString();

        $rows = DB::table('pod_transactions')
            ->join('shipments', 'shipments.id', '=', 'pod_transactions.shipment_id')
            ->where('pod_transactions.merchant_id', $merchantId)
            ->where('pod_transactions.status', 'deposited')
            ->whereBetween(DB::raw('DATE(pod_transactions.deposited_at)'), [$from, $to])
            ->select('pod_transactions.*', 'shipments.delivery_charge')
            ->get();

        $codTotal = (float) $rows->sum('pod_amount');
        $deliveryChargeTotal = (float) $rows->sum('delivery_charge');
        $payable = max($codTotal - $deliveryChargeTotal, 0);

        $number = app(TrackingNumberService::class)->settlementNumber();

        $id = DB::table('merchant_settlements')->insertGetId([
            'settlement_number' => $number,
            'merchant_id' => $merchantId,
            'period_from' => $from,
            'period_to' => $to,
            'shipment_count' => $rows->count(),
            'pod_total' => $codTotal,
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
