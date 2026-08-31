<?php

declare(strict_types=1);

namespace Modules\Pickup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Pickup\Http\Requests\AddShipmentToPickupRequest;
use Modules\Pickup\Http\Requests\AssignPickupRequest;
use Modules\Pickup\Http\Requests\CollectShipmentRequest;
use Modules\Pickup\Http\Requests\FailPickupRequest;
use Modules\Pickup\Http\Requests\TransferPickupRequest;
use Modules\Pickup\Models\PickupRequest as PickupRequestModel;
use Modules\Pickup\Services\PickupRequestService;
use Modules\Shipment\Models\Shipment;

final class PickupController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Index
    |--------------------------------------------------------------------------
    */

    public function index(Request $request)
    {
        $user = $request->user();

        $query = PickupRequestModel::query()
            ->with([
                'merchant',
                'branch',
                'subBranch',
                'pickupBranch',
                'pickupSubBranch',
                'pickupLocation',
                'assignedStaff',
                'assignedBy',
                'pickedUpBy',
                'shipments.shipment',
            ]);

        /*
        |--------------------------------------------------------------------------
        | Admin
        |--------------------------------------------------------------------------
        */

        if (
            $user->isSuperAdmin()
            ||
            $user->hasRole('main_admin')
        ) {
            // All pickups.
        }

        /*
        |--------------------------------------------------------------------------
        | Merchant
        |--------------------------------------------------------------------------
        */

        elseif (
            $user->hasRole('merchant')
        ) {

            if (! $user->merchant_id) {
                return ApiResponse::success([
                    'data' => [],
                    'current_page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                ]);
            }

            $query->where(
                'merchant_id',
                $user->merchant_id
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Branch manager
        |--------------------------------------------------------------------------
        */

        elseif (
            $user->hasRole('branch_manager')
            ||
            $user->hasRole('sub_branch_manager')
        ) {

            $branchId =
                (int) $user->branch_id;

            if ($branchId <= 0) {
                return ApiResponse::success([
                    'data' => [],
                    'current_page' => 1,
                    'per_page' => 20,
                    'total' => 0,
                ]);
            }

            $query->where(function ($q) use (
                $branchId
            ) {

                $q->where(
                    'pickup_branch_id',
                    $branchId
                )
                    ->orWhere(
                        'pickup_sub_branch_id',
                        $branchId
                    )
                    ->orWhere(
                        'branch_id',
                        $branchId
                    )
                    ->orWhere(
                        'sub_branch_id',
                        $branchId
                    );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Rider
        |--------------------------------------------------------------------------
        */

        else {

            $query->where(
                'assigned_to',
                $user->id
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Status
        |--------------------------------------------------------------------------
        */

        if (
            $request->filled('status')
        ) {

            $query->where(
                'status',
                $request
                    ->string('status')
                    ->toString()
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if (
            $request->filled('search')
        ) {

            $search =
                trim(
                    $request
                        ->string('search')
                        ->toString()
                );

            if ($search !== '') {

                $query->where(function ($q) use (
                    $search
                ) {

                    $q->where(
                        'request_number',
                        'like',
                        "%{$search}%"
                    )
                        ->orWhere(
                            'pickup_name',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'pickup_phone',
                            'like',
                            "%{$search}%"
                        );
                });
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Admin branch filter
        |--------------------------------------------------------------------------
        */

        if (
            $request->filled('branch_id')
            &&
            (
                $user->isSuperAdmin()
                ||
                $user->hasRole('main_admin')
            )
        ) {

            $branchId =
                (int) $request->branch_id;

            $query->where(function ($q) use (
                $branchId
            ) {

                $q->where(
                    'pickup_branch_id',
                    $branchId
                )
                    ->orWhere(
                        'pickup_sub_branch_id',
                        $branchId
                    )
                    ->orWhere(
                        'branch_id',
                        $branchId
                    )
                    ->orWhere(
                        'sub_branch_id',
                        $branchId
                    );
            });
        }

        $perPage =
            min(
                max(
                    (int) $request->get(
                        'per_page',
                        20
                    ),
                    1
                ),
                100
            );

        return ApiResponse::success(
            $query
                ->latest('id')
                ->paginate($perPage)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Show
    |--------------------------------------------------------------------------
    */

    public function show(
        Request $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {

        $this->authorizePickupView(
            $request,
            $pickup
        );

        return ApiResponse::success(
            $service->get($pickup)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Add shipment
    |--------------------------------------------------------------------------
    */

    public function addShipment(
        AddShipmentToPickupRequest $request,
        PickupRequestService $service
    ) {

        $shipment =
            Shipment::query()
                ->whereKey(
                    $request->validated(
                        'shipment_id'
                    )
                )
                ->firstOrFail();

        $this->authorizeMerchantShipment(
            $request,
            $shipment
        );

        $item =
            $service->attachShipment(
                shipment:
                    $shipment,

                userId:
                    $request->user()->id,

                remarks:
                    $request->validated(
                        'remarks'
                    )
            );

        return ApiResponse::success(
            $item->load([
                'pickupRequest',
                'shipment',
            ]),
            'Shipment added to pickup request successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Assign
    |--------------------------------------------------------------------------
    */

    public function assign(
        AssignPickupRequest $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {

        $this->authorizeManagement(
            $request,
            $pickup
        );

        $staff =
            User::query()
                ->findOrFail(
                    $request->validated(
                        'staff_id'
                    )
                );

        $pickup =
            $service->assign(
                pickup:
                    $pickup,

                staff:
                    $staff,

                assignedBy:
                    $request->user()
            );

        return ApiResponse::success(
            $pickup,
            'Pickup rider assigned successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Transfer
    |--------------------------------------------------------------------------
    */

    public function transfer(
        TransferPickupRequest $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {

        $this->authorizeManagement(
            $request,
            $pickup
        );

        $staff =
            User::query()
                ->findOrFail(
                    $request->validated(
                        'staff_id'
                    )
                );

        $pickup =
            $service->transfer(
                pickup:
                    $pickup,

                newStaff:
                    $staff,

                transferredBy:
                    $request->user(),

                reason:
                    $request->validated(
                        'reason'
                    )
            );

        return ApiResponse::success(
            $pickup,
            'Pickup transferred successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Start
    |--------------------------------------------------------------------------
    */

    public function start(
        Request $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {

        $pickup =
            $service->start(
                pickup:
                    $pickup,

                user:
                    $request->user()
            );

        return ApiResponse::success(
            $pickup,
            'Rider has started the pickup.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Arrive
    |--------------------------------------------------------------------------
    */

    public function arrive(
        Request $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {

        $pickup =
            $service->arrive(
                pickup:
                    $pickup,

                user:
                    $request->user()
            );

        return ApiResponse::success(
            $pickup,
            'Rider arrival recorded.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Collect
    |--------------------------------------------------------------------------
    */

    public function collectShipment(
        CollectShipmentRequest $request,
        PickupRequestModel $pickup,
        Shipment $shipment,
        PickupRequestService $service
    ) {

        $item =
            $service->collectShipment(
                pickup:
                    $pickup,

                shipment:
                    $shipment,

                user:
                    $request->user(),

                remarks:
                    $request->validated(
                        'remarks'
                    )
            );

        return ApiResponse::success(
            $item,
            'Shipment collected successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Complete
    |--------------------------------------------------------------------------
    */

    public function complete(
        Request $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {

        $pickup =
            $service->complete(
                pickup:
                    $pickup,

                user:
                    $request->user()
            );

        return ApiResponse::success(
            $pickup,
            'Pickup completed successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Receive
    |--------------------------------------------------------------------------
    */

    public function receiveShipment(
        Request $request,
        PickupRequestModel $pickup,
        Shipment $shipment,
        PickupRequestService $service
    ) {

        $this->authorizePickupView(
            $request,
            $pickup
        );

        $item =
            $service->receiveShipment(
                pickup:
                    $pickup,

                shipment:
                    $shipment,

                staff:
                    $request->user()
            );

        return ApiResponse::success(
            $item,
            'Shipment received at origin branch successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fail
    |--------------------------------------------------------------------------
    */

    public function fail(
        FailPickupRequest $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {

        $pickup =
            $service->fail(
                pickup:
                    $pickup,

                user:
                    $request->user(),

                reason:
                    $request->validated(
                        'reason'
                    )
            );

        return ApiResponse::success(
            $pickup,
            'Pickup marked as failed.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | View authorization
    |--------------------------------------------------------------------------
    */

    private function authorizePickupView(
        Request $request,
        PickupRequestModel $pickup
    ): void {

        $user =
            $request->user();

        if (
            $user->isSuperAdmin()
            ||
            $user->hasRole('main_admin')
        ) {
            return;
        }

        if (
            $user->hasRole('merchant')
        ) {

            abort_unless(
                (int) $pickup->merchant_id
                ===
                (int) $user->merchant_id,
                403
            );

            return;
        }

        if (
            $user->hasRole('branch_manager')
            ||
            $user->hasRole('sub_branch_manager')
        ) {

            $branchId =
                (int) $user->branch_id;

            abort_unless(
                $branchId ===
                (int) $pickup->pickup_branch_id
                ||
                $branchId ===
                (int) $pickup->pickup_sub_branch_id
                ||
                $branchId ===
                (int) $pickup->branch_id
                ||
                $branchId ===
                (int) $pickup->sub_branch_id,
                403
            );

            return;
        }

        abort_unless(
            (int) $pickup->assigned_to
            ===
            (int) $user->id,
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Management authorization
    |--------------------------------------------------------------------------
    */

    private function authorizeManagement(
        Request $request,
        PickupRequestModel $pickup
    ): void {

        $user =
            $request->user();

        if (
            $user->isSuperAdmin()
            ||
            $user->hasRole('main_admin')
        ) {
            return;
        }

        if (
            $user->hasRole('branch_manager')
            ||
            $user->hasRole('sub_branch_manager')
        ) {

            $branchId =
                (int) $user->branch_id;

            abort_unless(
                $branchId ===
                (int) $pickup->pickup_branch_id
                ||
                $branchId ===
                (int) $pickup->pickup_sub_branch_id
                ||
                $branchId ===
                (int) $pickup->branch_id
                ||
                $branchId ===
                (int) $pickup->sub_branch_id,
                403
            );

            return;
        }

        abort(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Merchant shipment authorization
    |--------------------------------------------------------------------------
    */

    private function authorizeMerchantShipment(
        Request $request,
        Shipment $shipment
    ): void {

        $user =
            $request->user();

        if (
            $user->isSuperAdmin()
            ||
            $user->hasRole('main_admin')
        ) {
            return;
        }

        abort_unless(
            $user->hasRole('merchant'),
            403
        );

        abort_unless(
            (int) $shipment->merchant_id
            ===
            (int) $user->merchant_id,
            403
        );
    }
}