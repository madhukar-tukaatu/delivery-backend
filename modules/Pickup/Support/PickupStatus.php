<?php

declare(strict_types=1);

namespace Modules\Pickup\Support;

final class PickupStatus
{
    /*
    |--------------------------------------------------------------------------
    | Main lifecycle
    |--------------------------------------------------------------------------
    */

    public const REQUESTED = 'requested';

    public const ASSIGNED = 'assigned';

    public const STARTED = 'started';

    public const ARRIVED = 'arrived';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /*
    |--------------------------------------------------------------------------
    | Active pickup
    |--------------------------------------------------------------------------
    |
    | A pickup remains the active container until it is completed,
    | failed or cancelled.
    |
    */

    public static function active(): array
    {
        return [
            self::REQUESTED,
            self::ASSIGNED,
            self::STARTED,
            self::ARRIVED,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Pickups accepting new shipments
    |--------------------------------------------------------------------------
    |
    | According to the agreed workflow, shipments can continue to
    | enter the same pickup container until the pickup is closed.
    |
    */

    public static function acceptingShipments(): array
    {
        return [
            self::REQUESTED,
            self::ASSIGNED,
            self::STARTED,
            self::ARRIVED,
        ];
    }

    public static function open(): array
    {
        return self::active();
    }

    public static function closed(): array
    {
        return [
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
        ];
    }
}