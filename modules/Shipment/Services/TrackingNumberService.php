<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\DB;

class TrackingNumberService
{
    public function generate(): string
    {
        $prefix = 'CDMS-' . now()->format('Ymd');
        $count = DB::table('shipments')->whereDate('created_at', today())->count() + 1;

        return $prefix . '-' . str_pad((string) $count, 6, '0', STR_PAD_LEFT);
    }

    public function transferBatchNumber(): string
    {
        return 'TRF-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    public function settlementNumber(): string
    {
        return 'SET-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }
}
