<?php

declare(strict_types=1);

namespace Modules\Pickup\Support;

final class PickupStatus
{
    public const REQUESTED = 'requested';

    public const ACCEPTED = 'accepted';

    public const ASSIGNED = 'assigned';

    public const STARTED = 'started';

    public const ARRIVED = 'arrived';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /**
     * Pickups that are still operational.
     */
    public static function active(): array
    {
        return [
            self::REQUESTED,
            self::ACCEPTED,
            self::ASSIGNED,
            self::STARTED,
            self::ARRIVED,
        ];
    }

    /**
     * Pickups that can still receive shipments.
     */
    public static function acceptingShipments(): array
    {
        return [
            self::REQUESTED,
            self::ACCEPTED,
            self::ASSIGNED,
        ];
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