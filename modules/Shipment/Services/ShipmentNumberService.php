<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Str;

class ShipmentNumberService
{
    public function generate(): string
    {
        return 'TEX-'
            . now()->format('Ymd')
            . '-'
            . strtoupper(
                Str::padLeft(
                    (string) random_int(1, 999999),
                    6,
                    '0'
                )
            );
    }
}