<?php

declare(strict_types=1);

namespace Modules\Pickup\Support;

final class PickupShipmentStatus
{
    public const PENDING = 'pending';

    public const COLLECTED = 'collected';

    public const RECEIVED = 'received';

    public const FAILED = 'failed';

    public const REMOVED = 'removed';

    public static function active(): array
    {
        return [
            self::PENDING,
            self::COLLECTED,
            self::RECEIVED,
        ];
    }
}