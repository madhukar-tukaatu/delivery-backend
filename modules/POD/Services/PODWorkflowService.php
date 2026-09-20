<?php

namespace Modules\POD\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\POD\Models\PodRecord;
use Modules\Shipment\Models\Shipment;

class PODWorkflowService
{
    public function markCollectedForShipment(Shipment $shipment, User $rider, float $amount): ?PodRecord
    {
        $due = (float) ($shipment->total_collectable_amount
            ?: $shipment->total_collectable
            ?: $shipment->pod_amount);

        if ($due <= 0) {
            return null;
        }

        return DB::transaction(function () use ($shipment, $rider, $amount, $due) {
            $record = PodRecord::firstOrCreate(
                ['shipment_id' => $shipment->id],
                [
                    'merchant_id' => $shipment->merchant_id,
                    'pod_amount' => $shipment->pod_amount,
                    'delivery_charge' => $shipment->delivery_charge,
                    'pod_charge' => $shipment->pod_charge,
                    'status' => 'pending',
                ]
            );

            $record->update([
                'status' => 'collected',
                'collected_by' => $rider->id,
                'collected_amount' => $amount,
                'collected_at' => now(),
                'payment_method' => 'cash',
                'payment_destination' => 'rider',
                'payment_reference' => null,
                'payment_session_id' => null,
                'payment_paid_at' => now(),
            ]);

            $shipment->update([
                'pod_status' => 'collected',
                // Cash must be deposited at branch before merchant settlement.
                'settlement_status' => 'pending_deposit',
            ]);

            if (Schema::hasTable('pod_collections')) {
                $collection = DB::table('pod_collections')
                    ->where('shipment_id', $shipment->id)
                    ->first();

                if ($collection) {
                    $updates = [
                        'total_collected' => $amount,
                        'status' => 'collected_pending_deposit',
                        'collected_at' => now(),
                        'updated_at' => now(),
                    ];

                    foreach ([
                        'payment_method' => 'cash',
                        'payment_destination' => 'rider',
                        'payment_paid_at' => now(),
                    ] as $column => $value) {
                        if (Schema::hasColumn('pod_collections', $column)) {
                            $updates[$column] = $value;
                        }
                    }

                    DB::table('pod_collections')
                        ->where('id', $collection->id)
                        ->update($updates);
                }
            }

            return $record->fresh();
        });
    }

    public function markCollected(Shipment $shipment, User $user, float $amount): ?PodRecord
    {
        return DB::transaction(function () use ($shipment, $user, $amount) {
            $record = PodRecord::firstOrCreate([
                'shipment_id' => $shipment->id,
            ], [
                'merchant_id' => $shipment->merchant_id,
                'pod_amount' => $shipment->pod_amount,
                'delivery_charge' => $shipment->delivery_charge,
                'pod_charge' => $shipment->pod_charge,
                'status' => 'pending',
            ]);

            $record->update([
                'status' => 'collected',
                'collected_by' => $user->id,
                'collected_amount' => $amount,
                'collected_at' => now(),
            ]);

            $shipment->update([
                'pod_status' => 'collected',
                'settlement_status' => 'ready',
            ]);

            return $record->fresh();
        });
    }

    /**
     * Record a POD payment made directly to the merchant at delivery.
     *
     * These payments intentionally do not enter rider cash deposits or
     * merchant settlement calculations owned by the platform.
     */
    public function markPaidDirectToMerchant(
        Shipment $shipment,
        ?User $user,
        float $amount,
        string $paymentMethod,
        ?string $paymentReference = null,
        ?string $paymentSessionId = null,
    ): ?PodRecord {
        return DB::transaction(function () use (
            $shipment,
            $user,
            $amount,
            $paymentMethod,
            $paymentReference,
            $paymentSessionId,
        ) {
            $record = PodRecord::firstOrCreate([
                'shipment_id' => $shipment->id,
            ], [
                'merchant_id' => $shipment->merchant_id,
                'pod_amount' => $shipment->pod_amount,
                'delivery_charge' => $shipment->delivery_charge,
                'pod_charge' => $shipment->pod_charge,
                'status' => 'pending',
            ]);

            $paidAt = now();
            $record->update([
                'status' => 'paid_direct',
                'collected_by' => $user?->id,
                'collected_amount' => $amount,
                'collected_at' => $paidAt,
                'payment_method' => $paymentMethod,
                'payment_destination' => 'merchant',
                'payment_reference' => $paymentReference,
                'payment_session_id' => $paymentSessionId,
                'payment_paid_at' => $paidAt,
            ]);

            if (Schema::hasTable('pod_collections')) {
                $collection = DB::table('pod_collections')
                    ->where('shipment_id', $shipment->id)
                    ->first();

                if ($collection) {
                    $updates = [
                        'total_collected' => $amount,
                        'status' => 'paid_direct',
                        'collected_at' => $paidAt,
                        'updated_at' => $paidAt,
                    ];

                    foreach ([
                        'payment_method' => $paymentMethod,
                        'payment_destination' => 'merchant',
                        'payment_reference' => $paymentReference,
                        'payment_session_id' => $paymentSessionId,
                        'payment_paid_at' => $paidAt,
                    ] as $column => $value) {
                        if (Schema::hasColumn('pod_collections', $column)) {
                            $updates[$column] = $value;
                        }
                    }

                    DB::table('pod_collections')
                        ->where('id', $collection->id)
                        ->update($updates);
                }
            }

            // Online / direct merchant payments are recorded but not platform-payable.
            $shipment->update([
                'pod_status' => 'paid_direct',
                'settlement_status' => 'not_required',
            ]);

            return $record->fresh();
        });
    }

    public function markDeposited(PodRecord $record, User $user, ?string $remarks = null): PodRecord
    {
        return DB::transaction(function () use ($record, $user, $remarks) {
            if ($record->payment_destination === 'merchant' || $record->status === 'paid_direct') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'pod' => ['Direct merchant payments cannot be deposited to the platform.'],
                ]);
            }

            if ($record->status !== 'collected') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'pod' => ['Only collected cash POD can be deposited.'],
                ]);
            }

            $record->update([
                'status' => 'deposited',
                'deposited_by' => $user->id,
                'deposited_at' => now(),
                'remarks' => $remarks,
            ]);

            if ($record->shipment_id) {
                $shipment = Shipment::query()->lockForUpdate()->find($record->shipment_id);
                if ($shipment) {
                    $updates = ['pod_status' => 'deposited'];
                    // If merchant was already settled/processing via on_collection, keep that status.
                    // Otherwise mark ready for the preferred after_deposit settlement path.
                    if (! in_array($shipment->settlement_status, ['processing', 'settled'], true)) {
                        $updates['settlement_status'] = 'ready';
                    }
                    $shipment->update($updates);
                }
            }

            return $record->fresh();
        });
    }

    public function confirm(PodRecord $record, User $user, ?string $remarks = null): PodRecord
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
