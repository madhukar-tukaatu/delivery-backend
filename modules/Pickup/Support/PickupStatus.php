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

    /**
     * Pickup requests which are still operationally active.
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

    /**
     * Pickup requests which may still receive additional shipments.
     *
     * IMPORTANT:
     *
     * A rider can already be assigned and travelling while the
     * merchant adds another shipment.
     *
     * Therefore ASSIGNED and STARTED are intentionally included.
     *
     * ARRIVED is also included because the rider may be at the
     * merchant while the merchant is still preparing/adding parcels.
     *
     * If your business rule says "no new parcels once rider arrives",
     * remove ARRIVED.
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

    public static function terminal(): array
    {
        return [
            self::COMPLETED,
            self::FAILED,
        ];
    }
}