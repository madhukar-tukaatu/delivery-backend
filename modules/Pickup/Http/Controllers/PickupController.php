<?php

declare(strict_types=1);

namespace Modules\Pickup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Pickup\Http\Requests\AddShipmentToPickupRequest;
use Modules\Pickup\Http\Requests\AssignPickupRequest;
use Modules\Pickup\Http\Requests\CollectShipmentRequest;
use Modules\Pickup\Http\Requests\FailPickupRequest;
use Modules\Pickup\Models\PickupRequest as PickupRequestModel;
use Modules\Pickup\Services\PickupRequestService;
use Modules\Shipment\Models\Shipment;

final class PickupController extends Controller
{
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
            ]);

        if (
            $user->isSuperAdmin()
            ||
            $user->hasRole('main_admin')
        ) {
            // unrestricted
        } elseif ($user->hasRole('merchant')) {

            if (! $user->merchant_id) {
                return ApiResponse::success([]);
            }

            $query->where(
                'merchant_id',
                $user->merchant_id
            );

        } elseif (
            $user->hasRole('branch_manager')
            ||
            $user->hasRole('sub_branch_manager')
        ) {

            $query->forBranch(
                (int) $user->branch_id
            );

        } else {

            $query->where(
                'assigned_to',
                $user->id
            );
        }

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->string('status')->toString()
            );
        }

        if ($request->filled('search')) {

            $search = $request
                ->string('search')
                ->toString();

            $query->where(function ($q) use ($search) {

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

        $perPage = min(
            max(
                (int) $request->get('per_page', 20),
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

    public function addShipment(
        AddShipmentToPickupRequest $request,
        PickupRequestService $service
    ) {
        $shipment = Shipment::query()
            ->whereKey(
                $request->validated('shipment_id')
            )
            ->firstOrFail();

        $this->authorizeMerchantShipment(
            $request,
            $shipment
        );

        $item = $service->attachShipment(
            shipment: $shipment,
            userId: $request->user()->id,
            remarks: $request->validated('remarks')
        );

        return ApiResponse::success(
            $item->load([
                'pickupRequest',
                'shipment',
            ]),
            'Shipment added to pickup request successfully.'
        );
    }

    public function assign(
        AssignPickupRequest $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {
        $this->authorizeManagement(
            $request,
            $pickup
        );

        $staff = \App\Models\User::query()
            ->findOrFail(
                $request->validated('staff_id')
            );

        $pickup = $service->assign(
            pickup: $pickup,
            staff: $staff,
            assignedBy: $request->user()
        );

        return ApiResponse::success(
            $pickup,
            'Pickup rider assigned successfully.'
        );
    }

    public function start(
        Request $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {
        $pickup = $service->start(
            pickup: $pickup,
            user: $request->user()
        );

        return ApiResponse::success(
            $pickup,
            'Rider has started the pickup.'
        );
    }

    public function arrive(
        Request $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {
        $pickup = $service->arrive(
            pickup: $pickup,
            user: $request->user()
        );

        return ApiResponse::success(
            $pickup,
            'Rider arrival recorded.'
        );
    }

    public function collectShipment(
        CollectShipmentRequest $request,
        PickupRequestModel $pickup,
        Shipment $shipment,
        PickupRequestService $service
    ) {
        $item = $service->collectShipment(
            pickup: $pickup,
            shipment: $shipment,
            user: $request->user(),
            remarks: $request->validated('remarks')
        );

        return ApiResponse::success(
            $item,
            'Shipment collected successfully.'
        );
    }

    public function receiveShipment(
        Request $request,
        PickupRequestModel $pickup,
        Shipment $shipment,
        PickupRequestService $service
    ) {
        $item = $service->receiveShipment(
            pickup: $pickup,
            shipment: $shipment,
            staff: $request->user()
        );

        return ApiResponse::success(
            $item,
            'Shipment received at origin branch successfully.'
        );
    }

    public function fail(
        FailPickupRequest $request,
        PickupRequestModel $pickup,
        PickupRequestService $service
    ) {
        $pickup = $service->fail(
            pickup: $pickup,
            user: $request->user(),
            reason: $request->validated('reason')
        );

        return ApiResponse::success(
            $pickup,
            'Pickup marked as failed.'
        );
    }

    private function authorizePickupView(
        Request $request,
        PickupRequestModel $pickup
    ): void {
        $user = $request->user();

        if (
            $user->isSuperAdmin()
            ||
            $user->hasRole('main_admin')
        ) {
            return;
        }

        if ($user->hasRole('merchant')) {

            abort_unless(
                (int) $pickup->merchant_id ===
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

            $branchId = (int) $user->branch_id;

            abort_unless(
                $branchId === (int) $pickup->branch_id
                ||
                $branchId === (int) $pickup->sub_branch_id
                ||
                $branchId === (int) $pickup->pickup_branch_id
                ||
                $branchId === (int) $pickup->pickup_sub_branch_id,
                403
            );

            return;
        }

        abort_unless(
            (int) $pickup->assigned_to ===
            (int) $user->id,
            403
        );
    }

    private function authorizeManagement(
        Request $request,
        PickupRequestModel $pickup
    ): void {
        $user = $request->user();

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

            $branchId = (int) $user->branch_id;

            abort_unless(
                $branchId === (int) $pickup->branch_id
                ||
                $branchId === (int) $pickup->sub_branch_id
                ||
                $branchId === (int) $pickup->pickup_branch_id
                ||
                $branchId === (int) $pickup->pickup_sub_branch_id,
                403
            );

            return;
        }

        abort(403);
    }

    private function authorizeMerchantShipment(
        Request $request,
        Shipment $shipment
    ): void {
        $user = $request->user();

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
            (int) $shipment->merchant_id ===
            (int) $user->merchant_id,
            403
        );
    }
}