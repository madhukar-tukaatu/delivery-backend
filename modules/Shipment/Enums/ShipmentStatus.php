<?php

namespace Modules\Shipment\Enums;

final class ShipmentStatus
{
    public const CREATED = 'created';

    public const AWAITING_PICKUP = 'awaiting_pickup';

    public const PICKUP_ASSIGNED = 'pickup_assigned';

    public const PICKED_UP = 'picked_up';

    public const RECEIVED_AT_ORIGIN_BRANCH =
        'received_at_origin_branch';

    public const DISPATCHED =
        'dispatched';

    public const IN_TRANSIT =
        'in_transit';

    public const RECEIVED_AT_DESTINATION_BRANCH =
        'received_at_destination_branch';

    public const OUT_FOR_DELIVERY =
        'out_for_delivery';

    public const DELIVERED =
        'delivered';

    public const CANCELLED =
        'cancelled';

    public const PICKUP_FAILED =
        'pickup_failed';
}