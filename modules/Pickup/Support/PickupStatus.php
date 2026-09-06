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
    | ACCEPTED
    |   Rider has accepted the pickup assignment.
    |
    | STARTED
    |   Rider has started travelling to the merchant.
    |
    | ARRIVED
    |   Rider has arrived at merchant pickup location.
    |
    | COLLECTED
    |   Rider has collected all shipments from merchant.
    |
    | ON_WAY_TO_BRANCH
    |   Rider has started journey to origin branch with shipments.
    |
    | COMPLETED
    |   Branch staff has received and verified all shipments.
    |   This is the final pickup state. pickup.completed callback fires.
    |
    | FAILED
    |   Pickup failed (shipment missing, service not possible, etc.).
    |   Rider or staff cancelled the pickup.
    |
    | CANCELLED
    |   Pickup was explicitly cancelled by merchant or system.
    |
    */

    public const REQUESTED = 'requested';

    public const ASSIGNED = 'assigned';

    public const ACCEPTED = 'accepted';

    public const STARTED = 'started';

    public const ARRIVED = 'arrived';

    public const COLLECTED = 'collected';

    public const ON_WAY_TO_BRANCH = 'on_way_to_branch';

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
            self::ACCEPTED,
            self::STARTED,
            self::ARRIVED,
            self::COLLECTED,
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
            self::ACCEPTED,
            self::STARTED,
            self::ARRIVED,
            self::COLLECTED,
            self::ON_WAY_TO_BRANCH,
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
     *
     * STRICT: Can ONLY start from ACCEPTED state.
     * Rider must explicitly accept before starting.
     */
    public static function canStart(string $status): bool
    {
        return $status === self::ACCEPTED;
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