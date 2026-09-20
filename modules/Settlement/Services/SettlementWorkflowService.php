<?php

namespace Modules\Settlement\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Settlement\Models\MerchantSettlement;
use Modules\Settlement\Models\MerchantSettlementItem;
use Modules\Shipment\Models\Shipment;

/**
 * Unified merchant settlement math + auto batching after delivery.
 *
 * POD settlement (independent of delivery-fee billing):
 *   payable to merchant = deposited cash POD (+ adjustments)
 *
 * Delivery / POD service fees are billed separately via Billing invoices.
 * Online paid_direct POD cash is NOT in the cash settlement pool.
 * Cash POD settlements auto-open after branch deposit; Generate is catch-up.
 */
class SettlementWorkflowService
{
    public const PATH_AFTER_DEPOSIT = 'after_deposit';
    public const PATH_ON_COLLECTION = 'on_collection';

    public function merchantOwesDeliveryCharge(Shipment $shipment): bool
    {
        $payer = strtolower(trim((string) ($shipment->delivery_charge_paid_by ?? 'customer')));

        // Only deduct when merchant/store covers delivery (free delivery for customer).
        // Customer-paid delivery was already handled at checkout / door collection.
        return in_array($payer, ['merchant', 'store', 'seller', 'free', 'free_delivery'], true)
            && $this->checkoutDeliveryCharge($shipment) > 0;
    }

    /**
     * Locked delivery fee from checkout/pricing — not recomputed at settlement time.
     * Prefer shipment.delivery_charge, then charge/price breakdown snapshots, then quote.
     */
    public function checkoutDeliveryCharge(Shipment $shipment): float
    {
        $candidates = [];

        $onShipment = (float) ($shipment->delivery_charge ?? 0);
        if ($onShipment > 0) {
            $candidates[] = $onShipment;
        }

        $breakdown = $shipment->delivery_charge_breakdown;
        if (is_string($breakdown)) {
            $decoded = json_decode($breakdown, true);
            $breakdown = is_array($decoded) ? $decoded : null;
        }
        if (is_array($breakdown)) {
            foreach (['delivery_charge', 'total', 'final_price', 'total_amount', 'amount'] as $key) {
                if (isset($breakdown[$key]) && (float) $breakdown[$key] > 0) {
                    $candidates[] = (float) $breakdown[$key];
                    break;
                }
            }
        }

        if (Schema::hasTable('shipment_charge_breakdowns')) {
            $row = DB::table('shipment_charge_breakdowns')
                ->where('shipment_id', $shipment->id)
                ->orderByDesc('id')
                ->first();
            if ($row) {
                $val = (float) ($row->delivery_charge ?? 0);
                if ($val > 0) {
                    $candidates[] = $val;
                }
            }
        }

        if (Schema::hasTable('shipment_price_breakdowns')) {
            $row = DB::table('shipment_price_breakdowns')
                ->where('shipment_id', $shipment->id)
                ->orderByDesc('id')
                ->first();
            if ($row) {
                $val = (float) ($row->final_price ?? $row->base_delivery_fee ?? 0);
                if ($val > 0) {
                    $candidates[] = $val;
                }
            }
        }

        if (Schema::hasTable('pricing_quotes')) {
            $quote = null;
            if (Schema::hasColumn('shipments', 'pricing_quote_id') && ! empty($shipment->pricing_quote_id)) {
                $quote = DB::table('pricing_quotes')->where('id', $shipment->pricing_quote_id)->first();
            }
            if (! $quote && Schema::hasColumn('pricing_quotes', 'quoteable_type')) {
                $quote = DB::table('pricing_quotes')
                    ->where('quoteable_type', 'like', '%Shipment%')
                    ->where('quoteable_id', $shipment->id)
                    ->orderByDesc('id')
                    ->first();
            }
            if ($quote) {
                $val = (float) ($quote->total_amount ?? $quote->subtotal ?? 0);
                if ($val > 0) {
                    $candidates[] = $val;
                }
            }
        }

        return round($candidates[0] ?? 0.0, 2);
    }

    public function merchantDeliveryCharge(Shipment $shipment): float
    {
        return $this->merchantOwesDeliveryCharge($shipment)
            ? $this->checkoutDeliveryCharge($shipment)
            : 0.0;
    }

    public function cashPodPayable(Shipment $shipment, string $cashPath): float
    {
        $podStatus = strtolower((string) ($shipment->pod_status ?? ''));
        $settlementStatus = strtolower((string) ($shipment->settlement_status ?? ''));
        $amount = (float) (
            $shipment->pod_amount
            ?: $shipment->total_collectable_amount
            ?: 0
        );

        if (in_array($podStatus, ['paid_direct'], true) || $amount <= 0) {
            return 0.0;
        }

        if ($cashPath === self::PATH_ON_COLLECTION) {
            return ($settlementStatus === 'pending_deposit' && $podStatus === 'collected')
                ? $amount
                : 0.0;
        }

        if ($settlementStatus === 'ready' && $podStatus === 'deposited') {
            return $amount;
        }

        return 0.0;
    }

    /**
     * Auto settlement cash POD:
     * - prepaid / online: no door cash in the pool
     * - cash POD: only AFTER branch deposit (pod_status=deposited)
     */
    public function cashPodAmount(Shipment $shipment): float
    {
        return (float) (
            $shipment->pod_amount
            ?: $shipment->total_collectable_amount
            ?: $shipment->total_collectable
            ?: 0
        );
    }

    public function cashPodPayableAuto(Shipment $shipment): float
    {
        $podStatus = strtolower((string) ($shipment->pod_status ?? ''));
        $amount = $this->cashPodAmount($shipment);

        if ($podStatus === 'paid_direct') {
            return 0.0;
        }

        // Cash POD enters the settlement list only after branch deposit.
        if ($podStatus === 'deposited' && $amount > 0) {
            return $amount;
        }

        return 0.0;
    }

    public function lineNet(Shipment $shipment, string $cashPath): array
    {
        $podCash = $this->cashPodPayable($shipment, $cashPath);
        // Delivery fees are billed on invoices — not deducted from POD payable.
        $checkoutFee = $this->checkoutDeliveryCharge($shipment);

        return [
            'pod_amount' => round($podCash, 2),
            'delivery_charge' => 0.0,
            'pod_charge' => 0.0,
            'net_amount' => round($podCash, 2),
            'delivery_charge_paid_by' => $shipment->delivery_charge_paid_by,
            'checkout_delivery_charge' => $checkoutFee,
            'include' => $podCash > 0,
        ];
    }

    public function isCashPodAwaitingDeposit(Shipment $shipment): bool
    {
        $podStatus = strtolower((string) ($shipment->pod_status ?? ''));
        $settlementStatus = strtolower((string) ($shipment->settlement_status ?? ''));

        return $podStatus === 'collected' || $settlementStatus === 'pending_deposit';
    }

    public function isPrepaidOrOnlineComplete(Shipment $shipment, ?string $completionType = null): bool
    {
        $completionType = strtolower(trim((string) $completionType));
        if (in_array($completionType, ['prepaid', 'pod_online'], true)) {
            return true;
        }
        if (in_array($completionType, ['pod_cash', 'after_deposit'], true)) {
            return false;
        }

        $podStatus = strtolower((string) ($shipment->pod_status ?? ''));
        $paymentType = strtolower((string) ($shipment->payment_type ?? ''));
        $isPodType = in_array($paymentType, ['pod', 'cod', 'to_pay'], true);
        $amount = $this->cashPodAmount($shipment);

        // Cash POD lifecycle — never treat as prepaid/online.
        if (in_array($podStatus, ['collected', 'deposited', 'pending_deposit'], true)) {
            return false;
        }

        // Online POD paid at door to merchant.
        if ($podStatus === 'paid_direct') {
            return true;
        }

        // Prepaid / non-POD / nothing collectable at door.
        if (! $isPodType || $amount <= 0) {
            return true;
        }

        return false;
    }

    public function lineNetAuto(Shipment $shipment, ?string $completionType = null): array
    {
        $completionType = strtolower(trim((string) $completionType));
        $podStatus = strtolower((string) ($shipment->pod_status ?? ''));
        $checkoutFee = $this->checkoutDeliveryCharge($shipment);

        // Settlement list is POD cash only (after branch deposit).
        if ($completionType === 'after_deposit' || $podStatus === 'deposited') {
            $podCash = $this->cashPodPayableAuto($shipment);
            if ($podCash <= 0) {
                $podCash = $this->cashPodAmount($shipment);
            }

            return [
                'pod_amount' => round(max($podCash, 0), 2),
                'delivery_charge' => 0.0,
                'pod_charge' => 0.0,
                'net_amount' => round(max($podCash, 0), 2),
                'delivery_charge_paid_by' => $shipment->delivery_charge_paid_by,
                'checkout_delivery_charge' => $checkoutFee,
                'include' => $podCash > 0,
            ];
        }

        // Before deposit / prepaid / online: no POD cash settlement line.
        return [
            'pod_amount' => 0.0,
            'delivery_charge' => 0.0,
            'pod_charge' => 0.0,
            'net_amount' => 0.0,
            'delivery_charge_paid_by' => $shipment->delivery_charge_paid_by,
            'checkout_delivery_charge' => $checkoutFee,
            'include' => false,
        ];
    }

    public function eligibleShipments(int $merchantId, string $cashPath, ?string $periodFrom = null, ?string $periodTo = null): Collection
    {
        $query = Shipment::query()
            ->where('merchant_id', $merchantId)
            ->where('status', 'delivered')
            ->whereNotIn('settlement_status', ['processing', 'settled']);

        if ($periodFrom) {
            $query->whereDate('delivered_at', '>=', $periodFrom);
        }
        if ($periodTo) {
            $query->whereDate('delivered_at', '<=', $periodTo);
        }

        return $query->orderBy('delivered_at')->get()->filter(function (Shipment $shipment) use ($cashPath) {
            return $this->lineNet($shipment, $cashPath)['include'];
        })->values();
    }

    public function settlementStatusAfterDelivery(
        bool $cashCollected,
        bool $directPayment,
        Shipment $shipment,
    ): array {
        $owesDelivery = $this->merchantOwesDeliveryCharge($shipment);

        if ($directPayment) {
            return [
                'pod_status' => 'paid_direct',
                'settlement_status' => $owesDelivery ? 'ready' : 'not_required',
            ];
        }

        if ($cashCollected) {
            return [
                'pod_status' => 'collected',
                'settlement_status' => 'pending_deposit',
            ];
        }

        return [
            'pod_status' => 'not_required',
            'settlement_status' => $owesDelivery ? 'ready' : 'not_required',
        ];
    }

    /**
     * Open/append a pending settlement batch when eligible:
     * - prepaid / online POD: right after successful delivery (fees and/or record)
     * - cash POD: only after branch deposit
     * Idempotent.
     */
    public function autoEnsureForShipment(Shipment $shipment, ?string $completionType = null): ?MerchantSettlement
    {
        $shipment->refresh();

        if (strtolower((string) $shipment->status) !== 'delivered') {
            return null;
        }

        if (in_array(strtolower((string) $shipment->settlement_status), ['settled'], true)) {
            return null;
        }

        $completionType = strtolower(trim((string) $completionType));

        // Explicit: cash POD waits for branch deposit (unless this call is after_deposit).
        if ($completionType === 'pod_cash') {
            return null;
        }

        $line = $this->lineNetAuto($shipment, $completionType ?: null);
        if (! $line['include']) {
            return null;
        }

        return DB::transaction(function () use ($shipment, $line) {
            $existingItem = MerchantSettlementItem::query()
                ->where('shipment_id', $shipment->id)
                ->first();

            if ($existingItem) {
                // Refresh line amounts (e.g. after deposit) while batch still pending.
                $settlement = MerchantSettlement::query()->lockForUpdate()->find($existingItem->merchant_settlement_id);
                if ($settlement && $settlement->status === 'pending') {
                    $existingItem->update([
                        'pod_amount' => $line['pod_amount'],
                        'delivery_charge' => $line['delivery_charge'],
                        'pod_charge' => $line['pod_charge'],
                        'net_amount' => $line['net_amount'],
                    ]);
                    $this->recalcSettlementTotals($settlement);
                    $shipment->update(['settlement_status' => 'processing']);

                    return $settlement->fresh('items');
                }

                return $settlement?->fresh('items');
            }

            $settlement = MerchantSettlement::query()
                ->where('merchant_id', $shipment->merchant_id)
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $settlement) {
                $payload = [
                    'merchant_id' => $shipment->merchant_id,
                    'settlement_number' => 'SET-'.now()->format('YmdHis').'-'.random_int(100, 999),
                    'period_from' => now()->toDateString(),
                    'period_to' => null,
                    'total_pod_collected' => 0,
                    'total_delivery_charges' => 0,
                    'total_pod_charges' => 0,
                    'adjustments' => 0,
                    'final_payable_amount' => 0,
                    'status' => 'pending',
                ];
                if (Schema::hasColumn('merchant_settlements', 'cash_path')) {
                    $payload['cash_path'] = 'auto';
                }
                $settlement = MerchantSettlement::create($payload);
            }

            MerchantSettlementItem::create([
                'merchant_settlement_id' => $settlement->id,
                'shipment_id' => $shipment->id,
                'pod_amount' => $line['pod_amount'],
                'delivery_charge' => $line['delivery_charge'],
                'pod_charge' => $line['pod_charge'],
                'net_amount' => $line['net_amount'],
            ]);

            $shipment->update(['settlement_status' => 'processing']);
            $this->recalcSettlementTotals($settlement->fresh());

            return $settlement->fresh('items');
        });
    }

    public function recalcSettlementTotals(MerchantSettlement $settlement): void
    {
        $items = $settlement->items()->get();
        $totalPod = (float) $items->sum('pod_amount');
        $adjustments = (float) ($settlement->adjustments ?? 0);

        // POD settlement payable = cash owed to merchant (fees billed separately).
        $settlement->update([
            'total_pod_collected' => round($totalPod, 2),
            'total_delivery_charges' => 0,
            'total_pod_charges' => 0,
            'final_payable_amount' => round($totalPod + $adjustments, 2),
            'period_to' => now()->toDateString(),
        ]);
    }
}
