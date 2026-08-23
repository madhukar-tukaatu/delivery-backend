<?php

namespace Modules\Pickup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Pickup\Http\Requests\AddShipmentsToPickupRequest;
use Modules\Pickup\Http\Requests\CreatePickupRequestRequest;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Services\PickupWorkflowService;

class PickupController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = PickupRequest::query()
            ->with([
                'merchant',
                'branch',
                'subBranch',
                'assignedStaff',
                'shipments',
            ]);

        if (
            $user->isSuperAdmin()
            ||
            $user->hasRole('main_admin')
        ) {
            // unrestricted
        } elseif (
            $user->hasRole('branch_manager')
            ||
            $user->hasRole('sub_branch_manager')
        ) {

            $query->where(function ($q) use ($user) {

                $q->where(
                    'branch_id',
                    $user->branch_id
                )->orWhere(
                    'sub_branch_id',
                    $user->branch_id
                );
            });

        } else {

            $query->where(
                'assigned_to',
                $user->id
            );
        }

        return ApiResponse::success(
            $query
                ->latest('id')
                ->paginate(
                    min(
                        max(
                            (int) $request->get(
                                'per_page',
                                20
                            ),
                            1
                        ),
                        100
                    )
                )
        );
    }

    public function store(
        CreatePickupRequestRequest $request,
        PickupWorkflowService $service
    ) {
        $pickup = $service->create(
            $request->user(),
            $request->validated()
        );

        return ApiResponse::success(
            $pickup,
            'Pickup request created successfully.'
        );
    }

    public function addShipments(
        AddShipmentsToPickupRequest $request,
        PickupRequest $pickup,
        PickupWorkflowService $service
    ) {
        $pickup =
            $service->addShipments(
                $pickup,
                $request->user(),
                $request->validated()['shipment_ids'],
                $request->validated()['remarks'] ?? null
            );

        return ApiResponse::success(
            $pickup,
            'Shipment(s) added to pickup request.'
        );
    }

    public function show(
        PickupRequest $pickup
    ) {
        return ApiResponse::success(
            $pickup->load([
                'merchant',
                'branch',
                'subBranch',
                'assignedStaff',
                'shipments',
            ])
        );
    }

    public function assign(
        Request $request,
        PickupRequest $pickup,
        PickupWorkflowService $service
    ) {
        $data = $request->validate([
            'staff_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
        ]);

        $staff =
            \App\Models\User::findOrFail(
                $data['staff_id']
            );

        $pickup =
            $service->assign(
                $pickup,
                $staff,
                $request->user()
            );

        return ApiResponse::success(
            $pickup,
            'Pickup rider assigned successfully.'
        );
    }

    public function riderArrived(
        Request $request,
        PickupRequest $pickup,
        PickupWorkflowService $service
    ) {
        $pickup =
            $service->riderArrived(
                $pickup,
                $request->user()
            );

        return ApiResponse::success(
            $pickup,
            'Rider arrival recorded.'
        );
    }

    public function startCollection(
        Request $request,
        PickupRequest $pickup,
        PickupWorkflowService $service
    ) {
        $pickup =
            $service->startCollection(
                $pickup,
                $request->user()
            );

        return ApiResponse::success(
            $pickup,
            'Pickup collection started.'
        );
    }

    public function complete(
        Request $request,
        PickupRequest $pickup,
        PickupWorkflowService $service
    ) {
        $pickup =
            $service->complete(
                $pickup,
                $request->user()
            );

        return ApiResponse::success(
            $pickup,
            'Pickup request completed successfully.'
        );
    }
}