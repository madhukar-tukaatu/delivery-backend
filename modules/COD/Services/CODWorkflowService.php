<?php

namespace Modules\COD\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\COD\Models\CodRecord;
use Modules\Shipment\Models\Shipment;

class CODWorkflowService
{
    public function markCollectedForShipment(Shipment $shipment, User $rider, float $amount): ?CodRecord
    {
        if ((float) $shipment->cod_amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($shipment, $rider, $amount) {
            $record = CodRecord::firstOrCreate(
                ['shipment_id' => $shipment->id],
                [
                    'merchant_id' => $shipment->merchant_id,
                    'cod_amount' => $shipment->cod_amount,
                    'delivery_charge' => $shipment->delivery_charge,
                    'cod_charge' => $shipment->cod_charge,
                    'status' => 'pending',
                ]
            );

            $record->update([
                'status' => 'collected',
                'collected_by' => $rider->id,
                'collected_amount' => $amount,
                'collected_at' => now(),
            ]);

            return $record->fresh();
        });
    }

    public function markCollected(Shipment $shipment, User $user, float $amount): ?CodRecord
    {
        return DB::transaction(function () use ($shipment, $user, $amount) {
            $record = CodRecord::firstOrCreate([
                'shipment_id' => $shipment->id,
            ], [
                'merchant_id' => $shipment->merchant_id,
                'cod_amount' => $shipment->cod_amount,
                'delivery_charge' => $shipment->delivery_charge,
                'cod_charge' => $shipment->cod_charge,
                'status' => 'pending',
            ]);

            $record->update([
                'status' => 'collected',
                'collected_by' => $user->id,
                'collected_amount' => $amount,
                'collected_at' => now(),
            ]);

            $shipment->update([
                'cod_status' => 'collected',
                'settlement_status' => 'ready',
            ]);

            return $record->fresh();
        });
    }
    public function markDeposited(CodRecord $record, User $user, ?string $remarks = null): CodRecord
    {
        $record->update([
            'status' => 'deposited',
            'deposited_by' => $user->id,
            'deposited_at' => now(),
            'remarks' => $remarks,
        ]);

        return $record->fresh();
    }

    public function confirm(CodRecord $record, User $user, ?string $remarks = null): CodRecord
    {
        $record->update([
            'status' => 'confirmed',
            'confirmed_by' => $user->id,
            'confirmed_at' => now(),
            'remarks' => $remarks,
        ]);

        $record->shipment?->update(['settlement_status' => 'ready']);

        return $record->fresh();
    }
}
