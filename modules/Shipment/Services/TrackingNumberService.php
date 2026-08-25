<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Str;

class TrackingNumberService
{
    public function generate(): string
    {
        return 'Tex-'
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

    public function transferBatchNumber(): string
    {
        return 'TRF-'
            . now()->format('YmdHis')
            . '-'
            . random_int(100, 999);
    }

    public function settlementNumber(): string
    {
        return 'SET-'
            . now()->format('YmdHis')
            . '-'
            . random_int(100, 999);
    }
}