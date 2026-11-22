<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
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
        public ?string $trackingNumber = null,
        public ?string $riderName = null,
        public ?int $runId = null,
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("rider.{$this->riderId}.location"),
            new Channel("rider.{$this->riderId}.public-location"),
        ];

        if ($this->runId) {
            $channels[] = new Channel("delivery-runs.{$this->runId}.location");
            $channels[] = new Channel("runs.{$this->runId}");
        }

        if ($this->shipmentId) {
            $channels[] = new PrivateChannel("shipments.{$this->shipmentId}");
            $channels[] = new Channel("public-shipments.{$this->shipmentId}");
        }

        if ($this->trackingNumber) {
            $channels[] = new Channel("tracking.{$this->trackingNumber}");
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
            'rider_name' => $this->riderName,
            'run_id' => $this->runId,
            'shipment_id' => $this->shipmentId,
            'tracking_number' => $this->trackingNumber,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'heading' => $this->heading,
            'speed' => $this->speed,
            'updated_at' => now()->toISOString(),
        ];
    }
}
