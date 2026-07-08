<?php

namespace App\Support;

class CourierStatus
{
    public const BOOKED = 'booked';
    public const PICKUP_ASSIGNED = 'pickup_assigned';
    public const PICKED_UP = 'picked_up';
    public const PICKUP_FAILED = 'pickup_failed';
    public const RECEIVED_AT_ORIGIN_SUB_BRANCH = 'received_at_origin_sub_branch';
    public const RECEIVED_AT_ORIGIN_BRANCH = 'received_at_origin_branch';
    public const IN_TRANSIT = 'in_transit';
    public const RECEIVED_AT_TRANSIT_HUB = 'received_at_transit_hub';
    public const DISPATCHED_TO_DESTINATION_BRANCH = 'dispatched_to_destination_branch';
    public const RECEIVED_AT_DESTINATION_BRANCH = 'received_at_destination_branch';
    public const RECEIVED_AT_DESTINATION_SUB_BRANCH = 'received_at_destination_sub_branch';
    public const ASSIGNED_TO_RIDER = 'assigned_to_rider';
    public const OUT_FOR_DELIVERY = 'out_for_delivery';
    public const DELIVERED = 'delivered';
    public const DELIVERY_FAILED = 'delivery_failed';
    public const RETURN_INITIATED = 'return_initiated';
    public const CANCELLED = 'cancelled';

    public static function merchantStatus(string $status): string
    {
        return match ($status) {
            self::BOOKED, self::PICKUP_ASSIGNED => 'pending',
            self::PICKED_UP, self::RECEIVED_AT_ORIGIN_SUB_BRANCH, self::RECEIVED_AT_ORIGIN_BRANCH => 'picked_up',
            self::IN_TRANSIT, self::RECEIVED_AT_TRANSIT_HUB, self::DISPATCHED_TO_DESTINATION_BRANCH, self::RECEIVED_AT_DESTINATION_BRANCH, self::RECEIVED_AT_DESTINATION_SUB_BRANCH => 'in_transit',
            self::ASSIGNED_TO_RIDER, self::OUT_FOR_DELIVERY => 'out_for_delivery',
            self::DELIVERED => 'delivered',
            self::DELIVERY_FAILED => 'failed',
            self::RETURN_INITIATED => 'returning',
            self::CANCELLED => 'cancelled',
            default => 'pending',
        };
    }
}
