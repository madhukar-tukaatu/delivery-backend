<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Shipment\Models\Shipment;

class ShipmentStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public string $queue = 'broadcasts';

    public function __construct(public Shipment $shipment)
    {
        // Keep this event lightweight. MySQL remains the source of truth.
    }

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("shipments.{$this->shipment->id}"),
            new PrivateChannel('admin.dashboard'),
        ];

        if ($this->shipment->merchant_id) {
            $channels[] = new PrivateChannel("merchant.{$this->shipment->merchant_id}");
        }

        foreach ([
            $this->shipment->current_branch_id ?? null,
            $this->shipment->origin_branch_id ?? null,
            $this->shipment->destination_branch_id ?? null,
        ] as $branchId) {
            if ($branchId) {
                $channels[] = new PrivateChannel("branch.{$branchId}");
            }
        }

        foreach ([
            $this->shipment->current_sub_branch_id ?? null,
            $this->shipment->origin_sub_branch_id ?? null,
            $this->shipment->destination_sub_branch_id ?? null,
        ] as $subBranchId) {
            if ($subBranchId) {
                $channels[] = new PrivateChannel("sub_branch.{$subBranchId}");
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'shipment.status.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->shipment->id,
            'tracking_number' => $this->shipment->tracking_number,
            'merchant_id' => $this->shipment->merchant_id,
            'status' => $this->shipment->status,
            'merchant_status' => $this->shipment->merchant_status,
            'current_branch_id' => $this->shipment->current_branch_id,
            'current_sub_branch_id' => $this->shipment->current_sub_branch_id,
            'origin_branch_id' => $this->shipment->origin_branch_id,
            'origin_sub_branch_id' => $this->shipment->origin_sub_branch_id,
            'destination_branch_id' => $this->shipment->destination_branch_id,
            'destination_sub_branch_id' => $this->shipment->destination_sub_branch_id,
            'updated_at' => now()->toISOString(),
        ];
    }
}
