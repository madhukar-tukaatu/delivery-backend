<?php

declare(strict_types=1);

namespace Modules\Pickup\Support;

final class PickupStatus
{
    public const REQUESTED = 'requested';

    public const ASSIGNED = 'assigned';

    public const STARTED = 'started';

    public const ARRIVED = 'arrived';

    public const COLLECTING = 'collecting';

    public const COLLECTED = 'collected';

    public const RECEIVED = 'received';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /**
     * Pickup requests which are operationally active.
     */
    public static function active(): array
    {
        return [
            self::REQUESTED,
            self::ASSIGNED,
            self::STARTED,
            self::ARRIVED,
            self::COLLECTING,
            self::COLLECTED,
        ];
    }

    /**
     * Pickup requests which may still accept shipments.
     */
    public static function acceptingShipments(): array
    {
        return [
            self::REQUESTED,
            self::ASSIGNED,
        ];
    }

    public static function terminal(): array
    {
        return [
            self::RECEIVED,
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
        ];
    }
}