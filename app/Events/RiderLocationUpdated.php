<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RiderLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $riderId,
        public float $lat,
        public float $lng,
        public ?int $shipmentId = null,
        public ?float $heading = null,
        public ?float $speed = null,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel("rider.{$this->riderId}.location")];

        if ($this->shipmentId) {
            $channels[] = new PrivateChannel("shipments.{$this->shipmentId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'rider.location.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'rider_id' => $this->riderId,
            'shipment_id' => $this->shipmentId,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'heading' => $this->heading,
            'speed' => $this->speed,
            'updated_at' => now()->toISOString(),
        ];
    }
}
