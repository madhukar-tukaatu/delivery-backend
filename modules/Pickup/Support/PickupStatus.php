<?php

declare(strict_types=1);

namespace Modules\Pickup\Support;

final class PickupStatus
{
    public const REQUESTED = 'requested';

    public const RIDER_ASSIGNED = 'rider_assigned';

    public const RIDER_STARTED = 'rider_started';

    public const RIDER_ARRIVED = 'rider_arrived';

    public const COLLECTING = 'collecting';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const RESCHEDULED = 'rescheduled';

    public const CANCELLED = 'cancelled';

    /**
     * Pickup is still operationally open.
     *
     * IMPORTANT:
     *
     * A rider being assigned does NOT close the pickup.
     * A rider starting does NOT close the pickup.
     *
     * The pickup remains open until the rider reaches
     * the store / collection starts.
     */
    public static function open(): array
    {
        return [
            self::REQUESTED,
            self::RIDER_ASSIGNED,
            self::RIDER_STARTED,
        ];
    }

    /**
     * Pickup is active but shipment addition is no longer allowed.
     */
    public static function locked(): array
    {
        return [
            self::RIDER_ARRIVED,
            self::COLLECTING,
        ];
    }

    public static function active(): array
    {
        return [
            self::REQUESTED,
            self::RIDER_ASSIGNED,
            self::RIDER_STARTED,
            self::RIDER_ARRIVED,
            self::COLLECTING,
        ];
    }

    public static function closed(): array
    {
        return [
            self::COMPLETED,
            self::FAILED,
            self::RESCHEDULED,
            self::CANCELLED,
        ];
    }
}