<?php

declare(strict_types=1);

namespace Modules\Pickup\Support;

final class PickupStatus
{
    public const REQUESTED = 'requested';

    public const ASSIGNED = 'assigned';

    public const STARTED = 'started';

    public const ARRIVED = 'arrived';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /**
     * Pickup statuses which may still receive
     * newly-created shipments.
     *
     * BUSINESS RULE:
     *
     * A shipment can join PR-001 while:
     *
     * requested
     * assigned
     * started
     * arrived
     *
     * Once completed/failed/cancelled,
     * the pickup is closed.
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

    /**
     * Active pickup statuses used when checking whether
     * a shipment is already attached to an active pickup.
     */
    public static function active(): array
    {
        return self::acceptingShipments();
    }

    /**
     * Closed statuses.
     */
    public static function closed(): array
    {
        return [
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
        ];
    }
}
