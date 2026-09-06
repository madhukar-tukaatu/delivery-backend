<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use App\Support\CourierStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Models\PickupRequestShipment;
use Modules\Pickup\Support\PickupShipmentStatus;
use Modules\Pickup\Support\PickupStatus;
use Modules\Shipment\Models\Shipment;

final class GatewayPickupService
{
    /*
    |--------------------------------------------------------------------------
    | CREATE / REUSE PICKUP
    |--------------------------------------------------------------------------
    */

    public function create(
        int $merchantId,
        array $data
    ): PickupRequest {
        return DB::transaction(
            function () use (
                $merchantId,
                $data
            ): PickupRequest {
                $merchant = Merchant::query()
                    ->lockForUpdate()
                    ->find($merchantId);

                if (! $merchant) {
                    throw ValidationException::withMessages([
                        'merchant' => [
                            'Merchant was not found.',
                        ],
                    ]);
                }

                if ($merchant->status !== 'active') {
                    throw ValidationException::withMessages([
                        'merchant' => [
                            'Merchant account is not active.',
                        ],
                    ]);
                }

                $pickupLocationId = (int) (
                    $data['pickup_location_id'] ?? 0
                );

                if ($pickupLocationId <= 0) {
                    throw ValidationException::withMessages([
                        'pickup_location_id' => [
                            'Pickup location is required.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Lock pickup location
                |--------------------------------------------------------------------------
                |
                | This is important for concurrency.
                |
                | Two requests cannot simultaneously create PR-001 and PR-002
                | for the same merchant/location.
                |
                */

                $pickupLocation =
                    MerchantPickupLocation::query()
                        ->where('id', $pickupLocationId)
                        ->where(
                            'merchant_id',
                            $merchantId
                        )
                        ->lockForUpdate()
                        ->first();

                if (! $pickupLocation) {
                    throw ValidationException::withMessages([
                        'pickup_location_id' => [
                            'Pickup location does not belong to this merchant.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | EXISTING ACTIVE PICKUP
                |--------------------------------------------------------------------------
                */

                $pickup = $this->findOpenPickup(
                    merchantId: $merchantId,
                    pickupLocationId: $pickupLocationId,
                    lock: true,
                );

                /*
                |--------------------------------------------------------------------------
                | STRICT MODE: If ANY active pickup exists for this merchant/location,
                | REJECT new pickup request.
                |
                | Merchant must wait for rider to COMPLETE the previous pickup before
                | requesting a new one.
                |
                | This prevents:
                | - Multiple riders assigned to same merchant
                | - Shipments getting lost or mixed up
                | - Consolidation bugs
                |--------------------------------------------------------------------------
                */

                if ($pickup) {
                    throw ValidationException::withMessages([
                        'pickup' => [
                            'An active pickup already exists for this location (Request #' . $pickup->request_number . ', Status: ' . $pickup->status . '). '
                            . 'Please wait for the rider to complete this pickup before requesting a new one.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | CREATE NEW PICKUP
                |--------------------------------------------------------------------------
                */

                $pickup = $this->createPickupRequest(
                    merchantId: $merchantId,
                    pickupLocation: $pickupLocation,
                    data: $data,
                );

                /*
                |--------------------------------------------------------------------------
                | Attach all currently waiting shipments
                |--------------------------------------------------------------------------
                */

                $this->attachWaitingShipments(
                    pickup: $pickup,
                    merchantId: $merchantId,
                    pickupLocation: $pickupLocation,
                );

                $this->recalculateParcelQuantity(
                    $pickup
                );

                return $pickup->fresh([
                    'merchant',
                    'pickupLocation',
                    'pickupBranch',
                    'pickupSubBranch',
                    'shipments',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AUTOMATIC SHIPMENT -> ACTIVE PICKUP
    |--------------------------------------------------------------------------
    */

    public function attachShipmentToOpenPickup(
        int $merchantId,
        int $pickupLocationId,
        Shipment $shipment
    ): ?PickupRequest {
        return DB::transaction(
            function () use (
                $merchantId,
                $pickupLocationId,
                $shipment
            ): ?PickupRequest {
                /*
                |--------------------------------------------------------------------------
                | Self drop never enters pickup batching.
                |--------------------------------------------------------------------------
                */

                if ((bool) $shipment->self_drop) {
                    return null;
                }

                /*
                |--------------------------------------------------------------------------
                | Lock pickup location first.
                |--------------------------------------------------------------------------
                |
                | Gateway pickup creation uses the same lock order.
                | This prevents race conditions/deadlocks.
                |
                */

                $pickupLocation =
                    MerchantPickupLocation::query()
                        ->where('id', $pickupLocationId)
                        ->where(
                            'merchant_id',
                            $merchantId
                        )
                        ->lockForUpdate()
                        ->first();

                if (! $pickupLocation) {
                    return null;
                }

                /*
                |--------------------------------------------------------------------------
                | Make sure shipment belongs to merchant.
                |--------------------------------------------------------------------------
                */

                $shipment = Shipment::query()
                    ->lockForUpdate()
                    ->find($shipment->id);

                if (! $shipment) {
                    return null;
                }

                if (
                    (int) $shipment->merchant_id
                    !== $merchantId
                ) {
                    return null;
                }

                if (
                    (int) $shipment->pickup_location_id
                    !== $pickupLocationId
                ) {
                    return null;
                }

                /*
                |--------------------------------------------------------------------------
                | Only AWAITING_PICKUP can automatically enter pickup.
                |--------------------------------------------------------------------------
                */

                if (
                    $shipment->status
                    !== CourierStatus::AWAITING_PICKUP
                ) {
                    return null;
                }

                /*
                |--------------------------------------------------------------------------
                | Find ACTIVE pickup.
                |--------------------------------------------------------------------------
                */

                $pickup = $this->findOpenPickup(
                    merchantId: $merchantId,
                    pickupLocationId: $pickupLocationId,
                    lock: true,
                );

                /*
                |--------------------------------------------------------------------------
                | No active pickup:
                |
                | IMPORTANT:
                | Do NOT create one.
                |--------------------------------------------------------------------------
                */

                if (! $pickup) {
                    return null;
                }

                /*
                |--------------------------------------------------------------------------
                | Check whether shipment is already in any active pickup.
                |--------------------------------------------------------------------------
                */

                $alreadyAttached =
                    PickupRequestShipment::query()
                        ->where(
                            'shipment_id',
                            $shipment->id
                        )
                        ->whereNull('removed_at')
                        ->whereIn(
                            'status',
                            PickupShipmentStatus::active()
                        )
                        ->whereHas(
                            'pickupRequest',
                            function (Builder $query): void {
                                $query->whereIn(
                                    'status',
                                    PickupStatus::active()
                                );
                            }
                        )
                        ->lockForUpdate()
                        ->exists();

                if ($alreadyAttached) {
                    return $pickup->fresh([
                        'shipments',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Attach.
                |--------------------------------------------------------------------------
                */

                $this->attachShipmentToPickup(
                    pickup: $pickup,
                    shipment: $shipment,
                );

                /*
                |--------------------------------------------------------------------------
                | If pickup is already assigned/started/arrived,
                | the shipment immediately becomes PICKUP_ASSIGNED.
                |--------------------------------------------------------------------------
                */

                if (
                    in_array(
                        $pickup->status,
                        [
                            PickupStatus::ASSIGNED,
                            PickupStatus::STARTED,
                            PickupStatus::ARRIVED,
                        ],
                        true
                    )
                    &&
                    $shipment->status
                    === CourierStatus::AWAITING_PICKUP
                ) {
                    $oldStatus =
                        $shipment->status;

                    $shipment->status =
                        CourierStatus::PICKUP_ASSIGNED;

                    $shipment->merchant_status =
                        CourierStatus::merchantStatus(
                            CourierStatus::PICKUP_ASSIGNED
                        );

                    $shipment->save();

                    $this->createTrackingEvent(
                        shipment: $shipment,
                        oldStatus: $oldStatus,
                        newStatus: CourierStatus::PICKUP_ASSIGNED,
                        description:
                            'Shipment automatically added to active pickup request '
                            . $pickup->request_number
                            . '.'
                    );
                }

                $this->recalculateParcelQuantity(
                    $pickup
                );

                return $pickup->fresh([
                    'shipments',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FIND ACTIVE PICKUP
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Find open pickup
    |--------------------------------------------------------------------------
    |
    | Search for an active pickup for this merchant/location that can
    | receive new shipments.
    |
    | IMPORTANT: This must match the logic in consolidation to ensure
    | new shipments are attached to existing pickups, not create duplicates.
    |
    | Active statuses: REQUESTED, ASSIGNED, ACCEPTED, STARTED, ARRIVED
    | (NOT COMPLETED, FAILED, CANCELLED - those are terminal)
    |
    */

    public function findOpenPickup(
        int $merchantId,
        int $pickupLocationId,
        bool $lock = false
    ): ?PickupRequest {
        $query = PickupRequest::query()
            ->where(
                'merchant_id',
                $merchantId
            )
            ->where(
                'pickup_location_id',
                $pickupLocationId
            )
            ->whereIn(
                'status',
                PickupStatus::acceptingShipments()
            )
            ->latest('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Check for active pickup (STRICT)
    |--------------------------------------------------------------------------
    |
    | STRICT MODE: Reject new shipments if ANY pickup is active.
    | Rider must COMPLETE previous pickup before new one can be created.
    |
    */

    public function getActivePickup(
        int $merchantId,
        int $pickupLocationId,
        bool $lock = false
    ): ?PickupRequest {
        $query = PickupRequest::query()
            ->where(
                'merchant_id',
                $merchantId
            )
            ->where(
                'pickup_location_id',
                $pickupLocationId
            )
            ->whereIn(
                'status',
                PickupStatus::active()
            )
            ->latest('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /*
    |--------------------------------------------------------------------------
    | DEBUG: Consolidation diagnostics
    |--------------------------------------------------------------------------
    |
    | This helps diagnose why new pickups are being created instead of
    | consolidating with existing ones.
    |
    */

    public function diagnoseConsolidation(
        int $merchantId,
        int $pickupLocationId
    ): array {
        $merchant = Merchant::find($merchantId);
        $location = MerchantPickupLocation::find(
            $pickupLocationId
        );

        $openPickups = PickupRequest::query()
            ->where('merchant_id', $merchantId)
            ->where('pickup_location_id', $pickupLocationId)
            ->whereIn(
                'status',
                PickupStatus::acceptingShipments()
            )
            ->get();

        $closedPickups = PickupRequest::query()
            ->where('merchant_id', $merchantId)
            ->where('pickup_location_id', $pickupLocationId)
            ->whereIn(
                'status',
                PickupStatus::closed()
            )
            ->latest('id')
            ->limit(5)
            ->get();

        $awaitingShipments =
            Shipment::query()
                ->where('merchant_id', $merchantId)
                ->where(
                    'pickup_location_id',
                    $pickupLocationId
                )
                ->where(
                    'status',
                    CourierStatus::AWAITING_PICKUP
                )
                ->where('self_drop', false)
                ->get();

        return [
            'merchant' => [
                'id' => $merchant?->id,
                'name' => $merchant?->name,
                'status' => $merchant?->status,
            ],
            'location' => [
                'id' => $location?->id,
                'name' => $location?->name,
            ],
            'openPickups' => $openPickups->map(
                fn($p) => [
                    'id' => $p->id,
                    'request_number' => $p->request_number,
                    'status' => $p->status,
                    'shipment_count' => $p->shipments()->count(),
                ]
            )->toArray(),
            'closedPickups' => $closedPickups->map(
                fn($p) => [
                    'id' => $p->id,
                    'request_number' => $p->request_number,
                    'status' => $p->status,
                    'completed_at' => $p->completed_at,
                ]
            )->toArray(),
            'awaitingShipments' => $awaitingShipments->count(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | ATTACH WAITING SHIPMENTS
    |--------------------------------------------------------------------------
    */

    private function attachWaitingShipments(
        PickupRequest $pickup,
        int $merchantId,
        MerchantPickupLocation $pickupLocation
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Lock waiting shipments.
        |--------------------------------------------------------------------------
        */

        $shipments = Shipment::query()
            ->where(
                'merchant_id',
                $merchantId
            )
            ->where(
                'pickup_location_id',
                $pickupLocation->id
            )
            ->where(
                'status',
                CourierStatus::AWAITING_PICKUP
            )
            ->where(
                'self_drop',
                false
            )
            ->lockForUpdate()
            ->get();

        foreach ($shipments as $shipment) {
            /*
            |--------------------------------------------------------------------------
            | Check if already belongs to active pickup.
            |--------------------------------------------------------------------------
            */

            $hasActivePickup =
                PickupRequestShipment::query()
                    ->where(
                        'shipment_id',
                        $shipment->id
                    )
                    ->whereNull('removed_at')
                    ->whereIn(
                        'status',
                        PickupShipmentStatus::active()
                    )
                    ->whereHas(
                        'pickupRequest',
                        function (Builder $query): void {
                            $query->whereIn(
                                'status',
                                PickupStatus::active()
                            );
                        }
                    )
                    ->exists();

            if ($hasActivePickup) {
                continue;
            }

            $this->attachShipmentToPickup(
                pickup: $pickup,
                shipment: $shipment,
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ATTACH SINGLE SHIPMENT
    |--------------------------------------------------------------------------
    */

    private function attachShipmentToPickup(
        PickupRequest $pickup,
        Shipment $shipment,
        ?int $userId = null,
        ?string $remarks = null
    ): PickupRequestShipment {
        if (! $pickup->canAcceptShipments()) {
            throw ValidationException::withMessages([
                'pickup' => [
                    'This pickup request is no longer accepting shipments.',
                ],
            ]);
        }

        if (
            (int) $pickup->merchant_id
            !== (int) $shipment->merchant_id
        ) {
            throw ValidationException::withMessages([
                'shipment' => [
                    'Shipment does not belong to this merchant.',
                ],
            ]);
        }

        if (
            (int) $pickup->pickup_location_id
            !== (int) $shipment->pickup_location_id
        ) {
            throw ValidationException::withMessages([
                'shipment' => [
                    'Shipment belongs to a different pickup location.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Existing membership
        |--------------------------------------------------------------------------
        */

        $existing = PickupRequestShipment::query()
            ->where(
                'pickup_request_id',
                $pickup->id
            )
            ->where(
                'shipment_id',
                $shipment->id
            )
            ->first();

        if ($existing) {
            if ($existing->removed_at !== null) {
                $existing->update([
                    'removed_at' => null,
                    'removed_by' => null,
                    'status' => PickupShipmentStatus::PENDING,
                    'added_at' => now(),
                    'added_by' => $userId,
                    'remarks' => $remarks,
                ]);
            }

            return $existing->fresh();
        }

        /*
        |--------------------------------------------------------------------------
        | Create membership.
        |--------------------------------------------------------------------------
        */

        return PickupRequestShipment::query()->create([
            'pickup_request_id' => $pickup->id,
            'shipment_id' => $shipment->id,

            'added_at' => now(),
            'added_by' => $userId,

            'status' => PickupShipmentStatus::PENDING,

            'remarks' => $remarks,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE PICKUP REQUEST
    |--------------------------------------------------------------------------
    */

    private function createPickupRequest(
        int $merchantId,
        MerchantPickupLocation $pickupLocation,
        array $data
    ): PickupRequest {
        $requestNumber =
            $this->generateRequestNumber(
                $merchantId
            );

        $pickup = PickupRequest::query()->create([
            'request_number' =>
                $requestNumber,

            'merchant_id' =>
                $merchantId,

            'store_reference' =>
                $data['store_reference'] ?? null,

            'pickup_location_id' =>
                $pickupLocation->id,

            /*
            |----------------------------------------------------------------------
            | Pickup location determines branch.
            |----------------------------------------------------------------------
            */

            'pickup_branch_id' =>
                $pickupLocation->branch_id
                ?? null,

            'pickup_sub_branch_id' =>
                $pickupLocation->sub_branch_id
                ?? null,

            /*
            |----------------------------------------------------------------------
            | Snapshot pickup information.
            |----------------------------------------------------------------------
            */

            'pickup_name' =>
                $pickupLocation->name
                ?? null,

            'pickup_phone' =>
                $pickupLocation->phone
                ?? null,

            'pickup_address' =>
                $pickupLocation->address
                ?? null,

            'pickup_city' =>
                $pickupLocation->city
                ?? null,

            'pickup_area' =>
                $pickupLocation->area
                ?? null,

            'pickup_lat' =>
                $pickupLocation->latitude
                ?? $pickupLocation->lat
                ?? null,

            'pickup_lng' =>
                $pickupLocation->longitude
                ?? $pickupLocation->lng
                ?? null,

            'preferred_pickup_at' =>
                $data['preferred_pickup_at']
                ?? null,

            'parcel_quantity' =>
                0,

            'status' =>
                PickupStatus::REQUESTED,

            'remarks' =>
                $data['remarks']
                ?? null,

            'requested_at' =>
                now(),
        ]);

        return $pickup;
    }

    /*
    |--------------------------------------------------------------------------
    | REQUEST NUMBER
    |--------------------------------------------------------------------------
    */

    private function generateRequestNumber(
        int $merchantId
    ): string {
        /*
        |--------------------------------------------------------------------------
        | request_number is GLOBALLY unique: PR-000001, PR-000002, ...
        |
        | It is Tukaatu's own identifier and is NOT scoped to a merchant.
        | Two pickups can never share a request_number, regardless of merchant.
        |
        | (The store's own reference is stored separately in `store_reference`,
        |  which is unique only per merchant.)
        |
        | We compute the next number from the highest EXISTING numeric suffix
        | across ALL pickup requests, then defensively skip any candidate that
        | already exists. The surrounding transaction locks the pickup location
        | row, and the global unique index is the final guarantee.
        |--------------------------------------------------------------------------
        */

        $existingNumbers = PickupRequest::query()
            ->whereNotNull('request_number')
            ->pluck('request_number');

        $highest = 0;

        foreach ($existingNumbers as $value) {
            if (
                is_string($value)
                && preg_match('/(\d+)$/', $value, $matches)
            ) {
                $highest = max(
                    $highest,
                    (int) $matches[1]
                );
            }
        }

        $number = $highest + 1;

        /*
        |--------------------------------------------------------------------------
        | Safety: skip any number that already exists globally. Protects against
        | manual data edits or legacy rows using a different padding width.
        |--------------------------------------------------------------------------
        */

        do {
            $candidate = 'PR-' . str_pad(
                (string) $number,
                6,
                '0',
                STR_PAD_LEFT
            );

            $exists = PickupRequest::query()
                ->where('request_number', $candidate)
                ->exists();

            if ($exists) {
                $number++;
            }
        } while ($exists);

        return $candidate;
    }

    /*
    |--------------------------------------------------------------------------
    | RECALCULATE QUANTITY
    |--------------------------------------------------------------------------
    */

    private function recalculateParcelQuantity(
        PickupRequest $pickup
    ): void {
        $quantity = $pickup->shipments()
            ->wherePivotNull('removed_at')
            ->wherePivotIn(
                'status',
                PickupShipmentStatus::active()
            )
            ->count();

        $pickup->forceFill([
            'parcel_quantity' => $quantity,
        ])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | FIND PICKUP FOR MERCHANT
    |--------------------------------------------------------------------------
    */

    public function findForMerchant(
        int $merchantId,
        string $requestNumber
    ): PickupRequest {
        return PickupRequest::query()
            ->where(
                'merchant_id',
                $merchantId
            )
            ->where(
                'request_number',
                $requestNumber
            )
            ->with([
                'merchant',
                'pickupLocation',
                'pickupBranch',
                'pickupSubBranch',
                'assignedRider',
                'shipments',
            ])
            ->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | TRACKING EVENT
    |--------------------------------------------------------------------------
    */

    private function createTrackingEvent(
        Shipment $shipment,
        ?string $oldStatus,
        string $newStatus,
        string $description
    ): void {
        $table = 'tracking_events';

        if (
            ! DB::getSchemaBuilder()
                ->hasTable($table)
        ) {
            return;
        }

        $columns =
            DB::getSchemaBuilder()
                ->getColumnListing($table);

        $data = [
            'shipment_id' =>
                $shipment->id,

            'tracking_number' =>
                $shipment->tracking_number,

            'old_status' =>
                $oldStatus,

            'status' =>
                $newStatus,

            'merchant_status' =>
                CourierStatus::merchantStatus(
                    $newStatus
                ),

            'branch_id' =>
                $shipment->current_branch_id
                ??
                $shipment->origin_branch_id,

            'sub_branch_id' =>
                $shipment->current_sub_branch_id
                ??
                $shipment->origin_sub_branch_id,

            'location_text' =>
                null,

            'description' =>
                $description,

            'visibility' =>
                'public',

            'created_by' =>
                null,

            'created_at' =>
                now(),

            'updated_at' =>
                now(),
        ];

        $data = array_intersect_key(
            $data,
            array_flip($columns)
        );

        DB::table($table)->insert($data);
    }
}