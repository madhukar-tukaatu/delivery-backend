<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use Illuminate\Support\Str;
use Modules\Shipment\Jobs\SendShipmentCallback;
use Modules\Shipment\Models\Shipment;

/**
 * Builds shipment-lifecycle callback payloads and dispatches them to the store
 * partner's integration_callback_url (same channel + signing as pickups).
 *
 * Every payload uses a consistent envelope:
 *   {
 *     event, event_id, occurred_at, merchant_id,
 *     shipment: { id, tracking_number, merchant_order_id, status,
 *                 merchant_status, origin/destination branch ids,
 *                 pod_amount, delivery_charge, total_collectable_amount },
 *     data: { ...event-specific... }
 *   }
 */
final class ShipmentCallbackService
{
    public const EVENTS = [
        'shipment.sorted_for_delivery',
        'shipment.sorted_for_transfer',
        'shipment.in_transit',
        'shipment.received_at_destination',
        'delivery.assigned',
        'delivery.accepted',
        'delivery.out_for_delivery',
        'delivery.delivered',
        'delivery.failed',
    ];

    public function sortedForDelivery(Shipment $shipment): void
    {
        $this->dispatch($shipment, 'shipment.sorted_for_delivery');
    }

    public function sortedForTransfer(Shipment $shipment): void
    {
        $this->dispatch($shipment, 'shipment.sorted_for_transfer');
    }

    public function inTransit(Shipment $shipment): void
    {
        $this->dispatch($shipment, 'shipment.in_transit');
    }

    public function receivedAtDestination(Shipment $shipment): void
    {
        $this->dispatch($shipment, 'shipment.received_at_destination');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function deliveryAssigned(Shipment $shipment, array $data = []): void
    {
        $this->dispatch($shipment, 'delivery.assigned', $data);
    }

    public function deliveryAccepted(Shipment $shipment, array $data = []): void
    {
        $this->dispatch($shipment, 'delivery.accepted', $data);
    }

    public function deliveryOutForDelivery(Shipment $shipment, array $data = []): void
    {
        $this->dispatch($shipment, 'delivery.out_for_delivery', $data);
    }

    public function deliveryDelivered(Shipment $shipment, array $data = []): void
    {
        $this->dispatch($shipment, 'delivery.delivered', $data);
    }

    public function deliveryFailed(Shipment $shipment, array $data = []): void
    {
        $this->dispatch($shipment, 'delivery.failed', $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dispatch(Shipment $shipment, string $event, array $data = []): void
    {
        $merchantId = (int) $shipment->merchant_id;

        if ($merchantId <= 0) {
            return;
        }

        $payload = [
            'event_id' => 'evt_' . str_replace('.', '_', $event) . '_' . Str::lower(Str::random(24)),
            'event' => $event,
            'occurred_at' => now()->toIso8601String(),
            'merchant_id' => $merchantId,
            'shipment' => [
                'id' => (int) $shipment->id,
                'tracking_number' => $shipment->tracking_number,
                'merchant_order_id' => $shipment->merchant_order_id,
                'status' => $shipment->status,
                'merchant_status' => $shipment->merchant_status,
                'origin_branch_id' => $shipment->origin_branch_id !== null ? (int) $shipment->origin_branch_id : null,
                'destination_branch_id' => $shipment->destination_branch_id !== null ? (int) $shipment->destination_branch_id : null,
                'current_branch_id' => $shipment->current_branch_id !== null ? (int) $shipment->current_branch_id : null,
                'payment_type' => $shipment->payment_type,
                'pod_amount' => (float) $shipment->pod_amount,
                'delivery_charge' => (float) $shipment->delivery_charge,
                'total_collectable_amount' => (float) $shipment->total_collectable_amount,
            ],
            'data' => $data,
        ];

        SendShipmentCallback::dispatch($merchantId, $payload)
            ->afterCommit()
            ->onQueue('webhooks');
    }
}
