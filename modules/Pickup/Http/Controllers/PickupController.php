<?php

namespace Modules\Pickup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Services\PickupWorkflowService;

class PickupController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = PickupRequest::query()
            ->with(['shipment.merchant', 'assignedUser'])
            ->whereIn('status', ['pending', 'assigned']);

        if ($user->isSuperAdmin() || $user->hasRole('main_admin')) {
            // sees all pickups
        } elseif ($user->hasRole('branch_manager') || $user->hasRole('sub_branch_manager')) {
            $query->where(function ($q) use ($user) {
                $q->where('branch_id', $user->branch_id)
                    ->orWhere('sub_branch_id', $user->branch_id);
            });
        } else {
            $query->where('assigned_to', $user->id);
        }

        return ApiResponse::success($query->latest()->paginate((int) $request->get('per_page', 20)));
    }

    public function pickedUp(Request $request, PickupRequest $pickup, PickupWorkflowService $service)
    {
        $this->authorizePickup($request, $pickup);

        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $shipment = $service->markPickedUp($pickup, $request->user(), $data['remarks'] ?? null);

        return ApiResponse::success($shipment, 'Parcel marked as picked up.');
    }

    public function failed(Request $request, PickupRequest $pickup, PickupWorkflowService $service)
    {
        $this->authorizePickup($request, $pickup);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $shipment = $service->markFailed($pickup, $request->user(), $data['reason']);

        return ApiResponse::success($shipment, 'Pickup marked as failed.');
    }

    private function authorizePickup(Request $request, PickupRequest $pickup): void
    {
        $user = $request->user();

        if ($user->isSuperAdmin() || $user->hasRole('main_admin')) {
            return;
        }

        if (($user->hasRole('branch_manager') || $user->hasRole('sub_branch_manager'))
            && ((int) $pickup->branch_id === (int) $user->branch_id || (int) $pickup->sub_branch_id === (int) $user->branch_id)) {
            return;
        }

        abort_unless((int) $pickup->assigned_to === (int) $user->id, 403);
    }
}
