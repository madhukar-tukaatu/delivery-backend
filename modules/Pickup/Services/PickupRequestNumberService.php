<?php

namespace Modules\Pickup\Services;

use Illuminate\Support\Str;

class PickupRequestNumberService
{
    public function generate(): string
    {
        return 'PR-' .
            now()->format('Ymd') .
            '-' .
            strtoupper(
                Str::padLeft(
                    (string) random_int(1, 999999),
                    6,
                    '0'
                )
            );
    }
}