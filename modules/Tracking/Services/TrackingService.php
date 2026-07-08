<?php

namespace Modules\Tracking\Services;

use App\Support\CourierStatus;
use Modules\Shipment\Models\Shipment;
use Modules\Tracking\Models\TrackingEvent;

class TrackingService
{
    public function record(Shipment $shipment, string $status, ?string $description = null, ?int $userId = null, string $visibility = 'public'): TrackingEvent
    {
        return TrackingEvent::create([
            'shipment_id' => $shipment->id,
            'tracking_number' => $shipment->tracking_number,
            'status' => $status,
            'merchant_status' => CourierStatus::merchantStatus($status),
            'branch_id' => $shipment->current_branch_id,
            'sub_branch_id' => $shipment->current_sub_branch_id,
            'location_text' => $this->locationText($shipment),
            'description' => $description ?: str_replace('_', ' ', ucfirst($status)),
            'visibility' => $visibility,
            'created_by' => $userId,
        ]);
    }

    private function locationText(Shipment $shipment): ?string
    {
        $parts = [];
        if ($shipment->relationLoaded('currentBranch') && $shipment->currentBranch) {
            $parts[] = $shipment->currentBranch->name;
        }
        if (!$parts && $shipment->receiver_city) {
            $parts[] = $shipment->receiver_city;
        }
        if ($shipment->receiver_area) {
            $parts[] = $shipment->receiver_area;
        }
        return $parts ? implode(', ', $parts) : null;
    }
}
