<?php

declare (strict_types = 1);

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Shipment\Services\PickupWorkflowService;

final class StaffPickupLifecycleController extends Controller
{
    /**
     * Get pickups assigned to the authenticated staff member.
     */
    public function index(
        Request $request,
        PickupWorkflowService $service
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data'    => $service->assignedPickupsForStaff(
                $request->user()->id
            ),
        ]);
    }

    /**
     * Accept assigned pickup.
     */
    public function accept(
        int $pickup,
        Request $request,
        PickupWorkflowService $service
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data'    => $service->accept(
                $pickup,
                $request->user()->id
            ),
        ]);
    }

    /**
     * Mark pickup as completed.
     */
    public function pickedUp(
        int $pickup,
        Request $request,
        PickupWorkflowService $service
    ): JsonResponse {

        $data = $request->validate([
            'remarks'      => [
                'nullable',
                'string',
                'max:1000',
            ],

            'parcel_count' => [
                'nullable',
                'integer',
                'min:1',
            ],
        ]);

        return response()->json([
            'success' => true,
            'data'    => $service->markPickedUp(
                $pickup,
                $request->user()->id,
                $data
            ),
        ]);
    }
}
