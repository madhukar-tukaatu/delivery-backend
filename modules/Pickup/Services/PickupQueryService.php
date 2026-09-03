<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Pickup\Models\PickupRequest;

final class PickupQueryService
{
    /**
     * List pickup requests visible to the authenticated user.
     *
     * Visibility rules:
     *
     * Super Admin:
     *     Can see every pickup.
     *
     * Branch Admin / Branch Staff:
     *     Can only see pickups belonging to their branch.
     *
     * The query intentionally returns pickup requests,
     * not individual shipments.
     *
     * Shipments are eager-loaded and displayed inside
     * the pickup/store row.
     */
    public function paginate(
        array $filters = [],
        ?int $branchId = null,
        bool $isSuperAdmin = false,
        int $perPage = 20,
    ): LengthAwarePaginator {

        $query = PickupRequest::query()
            ->with([
                'merchant:id,name,email,phone',
                'pickupLocation',
                'pickupBranch:id,name',
                'pickupSubBranch:id,name',
                'assignedStaff:id,name,phone',
                'shipments',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Branch scope
        |--------------------------------------------------------------------------
        |
        | Super Admin:
        |     No branch restriction.
        |
        | Branch user:
        |     Only pickups belonging to their branch.
        |
        */

        if (! $isSuperAdmin && $branchId !== null) {
            $query->where(
                'pickup_branch_id',
                $branchId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['status'])) {
            $query->where(
                'status',
                $filters['status']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Merchant / Store
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['merchant_id'])) {
            $query->where(
                'merchant_id',
                (int) $filters['merchant_id']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['search'])) {

            $search = trim(
                (string) $filters['search']
            );

            $query->where(function (Builder $q) use ($search) {

                $q
                    ->where(
                        'request_number',
                        'like',
                        "%{$search}%"
                    )

                    /*
                    | Pickup information
                    */
                    ->orWhere(
                        'pickup_name',
                        'like',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'pickup_phone',
                        'like',
                        "%{$search}%"
                    )

                    ->orWhere(
                        'pickup_address',
                        'like',
                        "%{$search}%"
                    )

                    /*
                    | Merchant/store
                    */
                    ->orWhereHas(
                        'merchant',
                        function (Builder $merchant) use ($search) {
                            $merchant
                                ->where(
                                    'name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'phone',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'email',
                                    'like',
                                    "%{$search}%"
                                );
                        }
                    )

                    /*
                    | Shipment tracking number
                    */
                    ->orWhereHas(
                        'shipments',
                        function (Builder $shipment) use ($search) {
                            $shipment
                                ->where(
                                    'tracking_number',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'merchant_order_id',
                                    'like',
                                    "%{$search}%"
                                );
                        }
                    );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Date
        |--------------------------------------------------------------------------
        */

        if (! empty($filters['scheduled_date'])) {
            $query->whereDate(
                'scheduled_date',
                $filters['scheduled_date']
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Active-first ordering
        |--------------------------------------------------------------------------
        |
        | Pending / assigned / accepted / in-progress pickups
        | should appear before completed pickups.
        |
        */

        $query
            ->orderByRaw(
                "
                CASE status
                    WHEN 'pending' THEN 1
                    WHEN 'assigned' THEN 2
                    WHEN 'accepted' THEN 3
                    WHEN 'in_progress' THEN 4
                    WHEN 'arrived' THEN 5
                    WHEN 'completed' THEN 6
                    WHEN 'picked_up' THEN 7
                    WHEN 'failed' THEN 8
                    ELSE 9
                END
                "
            )
            ->latest('id');

        return $query->paginate(
            min(
                max($perPage, 1),
                100
            )
        );
    }

    /**
     * Get one pickup with all operational information.
     */
    public function findForUser(
        int $pickupId,
        ?int $branchId = null,
        bool $isSuperAdmin = false,
    ): PickupRequest {

        $query = PickupRequest::query()
            ->with([
                'merchant',
                'pickupLocation',
                'pickupBranch',
                'pickupSubBranch',
                'assignedStaff',
                'shipments',
            ])
            ->whereKey($pickupId);

        if (! $isSuperAdmin && $branchId !== null) {
            $query->where(
                'pickup_branch_id',
                $branchId
            );
        }

        return $query->firstOrFail();
    }
}