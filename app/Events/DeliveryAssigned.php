<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Delivery\Models\DeliveryAssignment;

class DeliveryAssigned implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public string $queue = 'broadcasts';

    public function __construct(public DeliveryAssignment $delivery)
    {
        $this->delivery->loadMissing(['shipment']);
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('admin.dashboard')];

        if ($this->delivery->rider_id) {
            $channels[] = new PrivateChannel("staff.{$this->delivery->rider_id}");
        }

        if ($this->delivery->branch_id) {
            $channels[] = new PrivateChannel("branch.{$this->delivery->branch_id}");
        }

        if ($this->delivery->sub_branch_id) {
            $channels[] = new PrivateChannel("sub_branch.{$this->delivery->sub_branch_id}");
        }

        if ($this->delivery->shipment?->merchant_id) {
            $channels[] = new PrivateChannel("merchant.{$this->delivery->shipment->merchant_id}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'delivery.assigned';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->delivery->id,
            'shipment_id' => $this->delivery->shipment_id,
            'tracking_number' => $this->delivery->shipment?->tracking_number,
            'rider_id' => $this->delivery->rider_id,
            'branch_id' => $this->delivery->branch_id,
            'sub_branch_id' => $this->delivery->sub_branch_id,
            'status' => $this->delivery->status,
            'delivery_address' => $this->delivery->shipment?->receiver_address,
            'created_at' => now()->toISOString(),
        ];
    }
}
