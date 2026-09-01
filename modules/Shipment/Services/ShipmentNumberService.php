<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use Illuminate\Support\Str;

final class ShipmentNumberService
{
    public function generate(): string
    {
        return 'TEX-'
            . now()->format('Ymd')
            . '-'
            . strtoupper(
                Str::padLeft(
                    (string) random_int(
                        1,
                        999999
                    ),
                    6,
                    '0'
                )
            );
    }
}