<?php

declare(strict_types=1);

namespace Modules\Pickup\Support;

final class PickupShipmentStatus
{
    /**
     * Shipment is part of pickup but has not been collected yet.
     */
    public const PENDING = 'pending';

    /**
     * Rider has collected the shipment from merchant.
     */
    public const COLLECTED = 'collected';

    /**
     * Shipment has been received at origin branch.
     */
    public const RECEIVED = 'received';

    /**
     * Shipment could not be collected.
     */
    public const FAILED = 'failed';

    /**
     * Shipment was removed from this pickup.
     */
    public const REMOVED = 'removed';

    public static function active(): array
    {
        return [
            self::PENDING,
            self::COLLECTED,
            self::RECEIVED,
        ];
    }

    public static function canCollect(string $status): bool
    {
        return $status === self::PENDING;
    }

    public static function isClosed(string $status): bool
    {
        return in_array(
            $status,
            [
                self::RECEIVED,
                self::FAILED,
                self::REMOVED,
            ],
            true
        );
    }
}