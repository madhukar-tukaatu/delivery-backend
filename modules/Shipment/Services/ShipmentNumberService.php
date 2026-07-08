<?php

namespace Modules\Shipment\Services;

use Modules\Shipment\Models\Shipment;

class ShipmentNumberService
{
    public function generate(): string
    {
        $prefix = 'HS-'.now()->format('Ymd');
        $count = Shipment::whereDate('created_at', now()->toDateString())->count() + 1;
        do {
            $number = $prefix.'-'.str_pad((string) $count, 6, '0', STR_PAD_LEFT);
            $count++;
        } while (Shipment::where('tracking_number', $number)->exists());

        return $number;
    }
}
