<?php

declare(strict_types=1);

namespace Modules\POD\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\POD\Models\PodPaymentSession;

/**
 * Broadcast POD payment session changes to the assigned rider (and admin).
 * FE primarily polls GET pod-payment; this is the realtime stub matching
 * DeliveryStatusUpdated conventions.
 */
class PodPaymentUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public string $queue = 'broadcasts';

    public function __construct(public PodPaymentSession $session)
    {
        $this->session->loadMissing(['shipment']);
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('admin.dashboard')];

        $riderId = $this->session->delivery_assignment_id
            ? \Modules\Delivery\Models\DeliveryAssignment::query()
                ->where('id', $this->session->delivery_assignment_id)
                ->value('rider_id')
            : null;

        if (! $riderId && $this->session->shipment_id) {
            $riderId = \Modules\Delivery\Models\DeliveryAssignment::query()
                ->where('shipment_id', $this->session->shipment_id)
                ->whereIn('status', ['assigned', 'accepted', 'out_for_delivery'])
                ->latest('id')
                ->value('rider_id');
        }

        if ($riderId) {
            $channels[] = new PrivateChannel("staff.{$riderId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'pod.payment.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->session->payment_session_id,
            'payment_session_id' => $this->session->payment_session_id,
            'merchant_txn_id' => $this->session->merchant_txn_id ?: $this->session->payment_session_id,
            'status' => $this->session->status,
            'qr_string' => $this->session->qr_payload,
            'payment_url' => $this->session->checkout_url,
            'transaction_id' => $this->session->transaction_id ?: $this->session->provider_reference,
            'amount' => number_format((float) $this->session->amount, 2, '.', ''),
            'shipment_id' => $this->session->shipment_id,
            'delivery_assignment_id' => $this->session->delivery_assignment_id,
            'tracking_number' => $this->session->tracking_number,
            'paid_at' => $this->session->paid_at?->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
