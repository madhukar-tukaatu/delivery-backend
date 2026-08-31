<?php

declare(strict_types=1);

namespace Modules\Pickup\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class GatewayPickupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' =>
                $this->id,

            /*
             * Tukaatu's identifier.
             */
            'request_number' =>
                $this->request_number,

            /*
             * Store's identifier.
             *
             * Example:
             * Store One -> PR-001
             * Store Two -> PR-001
             *
             * This is unique only within the merchant/store context.
             */
            'store_reference' =>
                $this->store_reference,

            'merchant_id' =>
                $this->merchant_id,

            'pickup_location_id' =>
                $this->pickup_location_id,

            /*
             * Pickup branch.
             */
            'pickup_branch_id' =>
                $this->pickup_branch_id,

            'pickup_branch' =>
                $this->when(
                    $this->relationLoaded('pickupBranch') &&
                    $this->pickupBranch,
                    fn () => [
                        'id' =>
                            $this->pickupBranch->id,

                        'name' =>
                            $this->pickupBranch->name,

                        'code' =>
                            $this->pickupBranch->code
                            ?? null,
                    ]
                ),

            'pickup_sub_branch_id' =>
                $this->pickup_sub_branch_id,

            'pickup_sub_branch' =>
                $this->when(
                    $this->relationLoaded('pickupSubBranch') &&
                    $this->pickupSubBranch,
                    fn () => [
                        'id' =>
                            $this->pickupSubBranch->id,

                        'name' =>
                            $this->pickupSubBranch->name,

                        'code' =>
                            $this->pickupSubBranch->code
                            ?? null,
                    ]
                ),

            'pickup_name' =>
                $this->pickup_name,

            'pickup_phone' =>
                $this->pickup_phone,

            'pickup_email' =>
                $this->pickup_email,

            'pickup_address' =>
                $this->pickup_address,

            'pickup_city' =>
                $this->pickup_city,

            'pickup_area' =>
                $this->pickup_area,

            'pickup_lat' =>
                $this->pickup_lat,

            'pickup_lng' =>
                $this->pickup_lng,

            'preferred_pickup_at' =>
                $this->preferred_pickup_at,

            'parcel_quantity' =>
                $this->parcel_quantity,

            'status' =>
                $this->status,

            'remarks' =>
                $this->remarks,

            'requested_at' =>
                $this->requested_at,

            'assigned_at' =>
                $this->assigned_at,

            'arrived_at' =>
                $this->arrived_at,

            'completed_at' =>
                $this->completed_at,

            'shipments' =>
                $this->whenLoaded(
                    'shipments',
                    fn () => $this->shipments
                        ->map(
                            static function ($pickupShipment) {
                                return [
                                    'id' =>
                                        $pickupShipment->shipment_id,

                                    'tracking_number' =>
                                        $pickupShipment
                                            ->shipment
                                            ?->tracking_number,

                                    'status' =>
                                        $pickupShipment
                                            ->shipment
                                            ?->status,
                                ];
                            }
                        )
                        ->values()
                ),
        ];
    }
}