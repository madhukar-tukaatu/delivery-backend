<?php

namespace Modules\Settlement\Services;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Shipment\Models\Shipment;

/**
 * Unified merchant settlement math.
 *
 * Payable to merchant ≈
 *   cash POD (branch-deposited or optionally still with rider)
 * − delivery charges owed by merchant (free delivery / merchant payer)
 * − POD service charges
 * + adjustments
 *
 * Online paid_direct POD cash is NOT payable again (already with merchant),
 * but merchant-owed delivery fees on those shipments still settle here.
 */
class SettlementWorkflowService
{
    public const PATH_AFTER_DEPOSIT = 'after_deposit';
    public const PATH_ON_COLLECTION = 'on_collection';

    public function merchantOwesDeliveryCharge(Shipment $shipment): bool
    {
        $payer = strtolower(trim((string) ($shipment->delivery_charge_paid_by ?? 'customer')));

        return in_array($payer, ['merchant', 'store', 'seller', 'free', 'free_delivery'], true)
            && (float) ($shipment->delivery_charge ?? 0) > 0;
    }

    public function merchantDeliveryCharge(Shipment $shipment): float
    {
        return $this->merchantOwesDeliveryCharge($shipment)
            ? (float) $shipment->delivery_charge
            : 0.0;
    }

    public function cashPodPayable(Shipment $shipment, string $cashPath): float
    {
        $podStatus = strtolower((string) ($shipment->pod_status ?? ''));

        // Already paid direct to merchant — not in cash payable pool.
        if (in_array($podStatus, ['paid_direct'], true)) {
            return 0.0;
        }

        $settlementStatus = strtolower((string) ($shipment->settlement_status ?? ''));
        $amount = (float) (
            $shipment->pod_amount
            ?: $shipment->total_collectable_amount
            ?: 0
        );

        if ($amount <= 0) {
            return 0.0;
        }

        if ($cashPath === self::PATH_ON_COLLECTION) {
            return ($settlementStatus === 'pending_deposit' && $podStatus === 'collected')
                ? $amount
                : 0.0;
        }

        // Preferred path: branch deposit completed → settlement_status ready + pod deposited.
        if ($settlementStatus === 'ready' && $podStatus === 'deposited') {
            return $amount;
        }

        return 0.0;
    }

    public function lineNet(Shipment $shipment, string $cashPath): array
    {
        $podCash = $this->cashPodPayable($shipment, $cashPath);
        $deliveryOwed = $this->merchantDeliveryCharge($shipment);
        $podCharge = (float) ($shipment->pod_charge ?? 0);
        $net = $podCash - $deliveryOwed - $podCharge;

        return [
            'pod_amount' => round($podCash, 2),
            'delivery_charge' => round($deliveryOwed, 2),
            'pod_charge' => round($podCharge, 2),
            'net_amount' => round($net, 2),
            'delivery_charge_paid_by' => $shipment->delivery_charge_paid_by,
            'include' => ($podCash > 0 || $deliveryOwed > 0 || $podCharge > 0),
        ];
    }

    /**
     * Shipments eligible for a merchant settlement batch.
     */
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

        $shipments = $query->orderBy('delivered_at')->get();

        return $shipments->filter(function (Shipment $shipment) use ($cashPath) {
            $line = $this->lineNet($shipment, $cashPath);

            return $line['include'];
        })->values();
    }

    /**
     * After delivery complete: what settlement_status should be?
     */
    public function settlementStatusAfterDelivery(
        bool $cashCollected,
        bool $directPayment,
        Shipment $shipment,
    ): array {
        $owesDelivery = $this->merchantOwesDeliveryCharge($shipment);

        if ($directPayment) {
            return [
                'pod_status' => 'paid_direct',
                // Fee-only settle still needed when merchant owes delivery charge.
                'settlement_status' => $owesDelivery ? 'ready' : 'not_required',
            ];
        }

        if ($cashCollected) {
            return [
                'pod_status' => 'collected',
                'settlement_status' => 'pending_deposit',
            ];
        }

        // Prepaid / nothing collected at door.
        return [
            'pod_status' => 'not_required',
            'settlement_status' => $owesDelivery ? 'ready' : 'not_required',
        ];
    }
}
