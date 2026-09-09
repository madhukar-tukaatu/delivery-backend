<?php

declare(strict_types=1);

namespace App\Support;

final class CourierStatus
{
    public const BOOKED = 'booked';

    public const AWAITING_PICKUP = 'awaiting_pickup';

    public const PICKUP_ASSIGNED = 'pickup_assigned';

    public const PICKED_UP = 'picked_up';

    public const PICKUP_FAILED = 'pickup_failed';

    public const RECEIVED_AT_ORIGIN_SUB_BRANCH =
        'received_at_origin_sub_branch';

    public const RECEIVED_AT_ORIGIN_BRANCH =
        'received_at_origin_branch';

    /*
    |--------------------------------------------------------------------------
    | Sort outcome (set at origin branch after receiving)
    |--------------------------------------------------------------------------
    |
    | SORTED_FOR_DELIVERY
    |   Origin node == destination node. Same-branch last-mile delivery.
    |   Ready to be assigned to a delivery rider at this branch.
    |
    | SORTED_FOR_TRANSFER
    |   Origin node != destination node. Requires a branch-to-branch transfer.
    |   Ready to be added to a transfer batch.
    |
    */

    public const SORTED_FOR_DELIVERY =
        'sorted_for_delivery';

    public const SORTED_FOR_TRANSFER =
        'sorted_for_transfer';

    public const IN_TRANSIT =
        'in_transit';

    public const RECEIVED_AT_TRANSIT_HUB =
        'received_at_transit_hub';

    public const DISPATCHED_TO_DESTINATION_BRANCH =
        'dispatched_to_destination_branch';

    public const RECEIVED_AT_DESTINATION_BRANCH =
        'received_at_destination_branch';

    public const RECEIVED_AT_DESTINATION_SUB_BRANCH =
        'received_at_destination_sub_branch';

    public const ASSIGNED_TO_RIDER =
        'assigned_to_rider';

    public const OUT_FOR_DELIVERY =
        'out_for_delivery';

    public const DELIVERED =
        'delivered';

    public const DELIVERY_FAILED =
        'delivery_failed';

    public const RETURN_INITIATED =
        'return_initiated';

    public const CANCELLED =
        'cancelled';

    public static function merchantStatus(
        string $status
    ): string {
        return match ($status) {

            self::BOOKED,
            self::AWAITING_PICKUP,
            self::PICKUP_ASSIGNED
                => 'pending',

            self::PICKED_UP,
            self::RECEIVED_AT_ORIGIN_SUB_BRANCH,
            self::RECEIVED_AT_ORIGIN_BRANCH
                => 'picked_up',

            self::SORTED_FOR_DELIVERY,
            self::SORTED_FOR_TRANSFER,
            self::IN_TRANSIT,
            self::RECEIVED_AT_TRANSIT_HUB,
            self::DISPATCHED_TO_DESTINATION_BRANCH,
            self::RECEIVED_AT_DESTINATION_BRANCH,
            self::RECEIVED_AT_DESTINATION_SUB_BRANCH
                => 'in_transit',

            self::ASSIGNED_TO_RIDER,
            self::OUT_FOR_DELIVERY
                => 'out_for_delivery',

            self::DELIVERED
                => 'delivered',

            self::DELIVERY_FAILED
                => 'failed',

            self::RETURN_INITIATED
                => 'returning',

            self::CANCELLED
                => 'cancelled',

            default
                => 'pending',
        };
    }

    /**
     * Statuses set at the origin branch once a received shipment has been
     * sorted for its next leg. Both are "ready" hand-off states.
     */
    public static function sorted(): array
    {
        return [
            self::SORTED_FOR_DELIVERY,
            self::SORTED_FOR_TRANSFER,
        ];
    }

    /**
     * Whether the shipment has been sorted and is awaiting its next leg.
     */
    public static function isSorted(string $status): bool
    {
        return in_array(
            $status,
            self::sorted(),
            true
        );
    }
}
