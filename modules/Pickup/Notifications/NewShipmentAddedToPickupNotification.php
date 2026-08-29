<?php

declare(strict_types=1);

namespace Modules\Pickup\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Modules\Pickup\Models\PickupRequest;
use Modules\Shipment\Models\Shipment;

final class NewShipmentAddedToPickupNotification
    extends Notification
    implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly PickupRequest $pickup,
        private readonly Shipment $shipment,
    ) {
    }

    public function via(
        object $notifiable
    ): array {
        return [
            'database',
        ];
    }

    public function toDatabase(
        object $notifiable
    ): array {
        return [
            'type' =>
                'pickup.shipment_added',

            'title' =>
                'New shipment added to pickup',

            'message' =>
                sprintf(
                    'Shipment %s has been added to pickup %s.',
                    $this->shipment->tracking_number,
                    $this->pickup->request_number
                ),

            'pickup_request_id' =>
                $this->pickup->id,

            'pickup_request_number' =>
                $this->pickup->request_number,

            'shipment_id' =>
                $this->shipment->id,

            'tracking_number' =>
                $this->shipment->tracking_number,

            'merchant_id' =>
                $this->shipment->merchant_id,

            'service_type' =>
                $this->shipment->service_type,
        ];
    }
}