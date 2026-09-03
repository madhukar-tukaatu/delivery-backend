<?php

declare(strict_types=1);

namespace Modules\Pickup\Support;

final class PickupStatus
{
    /*
    |--------------------------------------------------------------------------
    | Pickup lifecycle
    |--------------------------------------------------------------------------
    |
    | REQUESTED
    |   Pickup exists and is waiting for rider/staff assignment.
    |
    | ASSIGNED
    |   Rider/staff has been assigned.
    |
    | STARTED
    |   Rider has started travelling to the merchant.
    |
    | ARRIVED
    |   Rider has arrived at merchant pickup location.
    |
    | COMPLETED
    |   Pickup is completely collected.
    |
    | FAILED / CANCELLED
    |   Terminal states.
    |
    */

    public const REQUESTED = 'requested';

    public const ASSIGNED = 'assigned';

    public const STARTED = 'started';

    public const ARRIVED = 'arrived';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    /**
     * Pickup states which can still receive newly-created shipments.
     *
     * This is the most important method for the batching flow.
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
     * Active/non-terminal pickup states.
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
     * Terminal states.
     */
    public static function closed(): array
    {
        return [
            self::COMPLETED,
            self::FAILED,
            self::CANCELLED,
        ];
    }

    /**
     * Determine whether a pickup can receive shipments.
     */
    public static function canAddShipments(string $status): bool
    {
        return in_array(
            $status,
            self::acceptingShipments(),
            true
        );
    }

    /**
     * Determine whether pickup can be assigned.
     */
    public static function canAssign(string $status): bool
    {
        return $status === self::REQUESTED;
    }

    /**
     * Determine whether pickup can start.
     */
    public static function canStart(string $status): bool
    {
        return $status === self::ASSIGNED;
    }

    /**
     * Determine whether rider can arrive.
     */
    public static function canArrive(string $status): bool
    {
        return $status === self::STARTED;
    }

    /**
     * Determine whether collection can start.
     */
    public static function canCollect(string $status): bool
    {
        return in_array(
            $status,
            [
                self::ARRIVED,
            ],
            true
        );
    }

    /**
     * Determine whether pickup can complete.
     */
    public static function canComplete(string $status): bool
    {
        return $status === self::ARRIVED;
    }

    /**
     * Determine whether status is terminal.
     */
    public static function isClosed(string $status): bool
    {
        return in_array(
            $status,
            self::closed(),
            true
        );
    }

    /**
     * Determine whether status is active.
     */
    public static function isActive(string $status): bool
    {
        return in_array(
            $status,
            self::active(),
            true
        );
    }
}