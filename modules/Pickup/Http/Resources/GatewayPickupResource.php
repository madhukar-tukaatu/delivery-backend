<?php

declare(strict_types=1);

namespace Modules\Pickup\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class GatewayPickupResource extends JsonResource
{
    /**
     * Transform the pickup request for the external Gateway API.
     *
     * IMPORTANT:
     *
     * Do not expose the complete PickupRequest model.
     * The Gateway is an external integration boundary.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' =>
                $this->id,

            'request_number' =>
                $this->request_number,

            'status' =>
                $this->status,

            'pickup_location_id' =>
                $this->pickup_location_id,

            'pickup_branch_id' =>
                $this->pickup_branch_id,

            'pickup_sub_branch_id' =>
                $this->pickup_sub_branch_id,

            'pickup_name' =>
                $this->pickup_name,

            'pickup_phone' =>
                $this->pickup_phone,

            'pickup_address' =>
                $this->pickup_address,

            'pickup_city' =>
                $this->pickup_city,

            'pickup_area' =>
                $this->pickup_area,

            'pickup_lat' =>
                $this->pickup_lat !== null
                    ? (float) $this->pickup_lat
                    : null,

            'pickup_lng' =>
                $this->pickup_lng !== null
                    ? (float) $this->pickup_lng
                    : null,

            'parcel_quantity' =>
                $this->parcel_quantity,

            'preferred_pickup_at' =>
                $this->preferred_pickup_at,

            'remarks' =>
                $this->remarks,

            'requested_at' =>
                $this->requested_at,

            /*
            |--------------------------------------------------------------------------
            | Shipments
            |--------------------------------------------------------------------------
            |
            | Only expose information the external merchant needs.
            |
            */

            'shipments' =>
                $this->whenLoaded(
                    'shipments',
                    fn () => $this->shipments
                        ->filter(
                            static fn ($pickupShipment) =>
                                $pickupShipment->removed_at === null
                        )
                        ->map(
                            static function ($pickupShipment): array {
                                $shipment =
                                    $pickupShipment->shipment;

                                return [
                                    'id' =>
                                        $shipment?->id,

                                    'tracking_number' =>
                                        $shipment?->tracking_number,

                                    'merchant_order_id' =>
                                        $shipment?->merchant_order_id,

                                    'status' =>
                                        $shipment?->status,
                                ];
                            }
                        )
                        ->values()
                        ->all()
                ),
        ];
    }
}