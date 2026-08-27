<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

final class PickupRequestNumberService
{
    public function generate(int $id): string
    {
        return sprintf(
            'PICKUP-REQ-%06d',
            $id
        );
    }
}