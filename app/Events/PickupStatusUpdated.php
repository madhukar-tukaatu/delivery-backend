<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Pickup\Models\PickupRequest;

class PickupStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public string $queue = 'broadcasts';

    public function __construct(public PickupRequest $pickup)
    {
        $this->pickup->loadMissing(['shipment']);
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('admin.dashboard')];

        if ($this->pickup->assigned_to) {
            $channels[] = new PrivateChannel("staff.{$this->pickup->assigned_to}");
        }

        if ($this->pickup->branch_id) {
            $channels[] = new PrivateChannel("branch.{$this->pickup->branch_id}");
        }

        if ($this->pickup->sub_branch_id) {
            $channels[] = new PrivateChannel("sub_branch.{$this->pickup->sub_branch_id}");
        }

        if ($this->pickup->merchant_id) {
            $channels[] = new PrivateChannel("merchant.{$this->pickup->merchant_id}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'pickup.status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->pickup->id,
            'shipment_id' => $this->pickup->shipment_id,
            'tracking_number' => $this->pickup->shipment?->tracking_number,
            'status' => $this->pickup->status,
            'updated_at' => now()->toISOString(),
        ];
    }
}
