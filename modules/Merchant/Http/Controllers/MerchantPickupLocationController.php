<?php

namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Routing\Services\BranchLocatorService;

class MerchantPickupLocationController extends Controller
{
    public function index(Request $request)
    {
        $merchant = $request->user()->merchant;

        return ApiResponse::success(
            MerchantPickupLocation::query()
                ->with(['branch', 'subBranch'])
                ->where('merchant_id', $merchant->id)
                ->latest()
                ->paginate((int) $request->get('per_page', 20))
        );
    }

    public function store(Request $request, BranchLocatorService $branchLocator)
    {
        $merchant = $request->user()->merchant;
        abort_unless($merchant && $merchant->status === 'active', 403, 'Merchant account is not active.');

        $data = $this->validatePayload($request);
        $nearest = $branchLocator->nearestBranchSet((float) $data['latitude'], (float) $data['longitude']);

        if (!empty($data['is_default'])) {
            MerchantPickupLocation::where('merchant_id', $merchant->id)->update(['is_default' => false]);
        }

        $location = MerchantPickupLocation::create([
            'merchant_id' => $merchant->id,
            'branch_id' => $nearest['branch']->id ?? null,
            'sub_branch_id' => $nearest['sub_branch']->id ?? null,
            'name' => $data['name'],
            'contact_person' => $data['contact_person'] ?? $merchant->contact_person,
            'phone' => $data['phone'] ?? $merchant->phone,
            'city' => $data['city'] ?? null,
            'area' => $data['area'] ?? null,
            'address' => $data['address'],
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'is_default' => (bool) ($data['is_default'] ?? false),
            'status' => 'active',
        ]);

        if ($location->is_default) {
            $merchant->update([
                'default_branch_id' => $location->branch_id,
                'default_sub_branch_id' => $location->sub_branch_id,
            ]);
        }

        return ApiResponse::success($location->fresh(['branch', 'subBranch']), 'Pickup location created.', 201);
    }

    public function update(Request $request, MerchantPickupLocation $pickupLocation, BranchLocatorService $branchLocator)
    {
        $merchant = $request->user()->merchant;
        abort_unless($merchant && $pickupLocation->merchant_id === $merchant->id, 403);

        $data = $this->validatePayload($request);
        $nearest = $branchLocator->nearestBranchSet((float) $data['latitude'], (float) $data['longitude']);

        if (!empty($data['is_default'])) {
            MerchantPickupLocation::where('merchant_id', $merchant->id)->where('id', '!=', $pickupLocation->id)->update(['is_default' => false]);
        }

        $pickupLocation->update([
            'branch_id' => $nearest['branch']->id ?? null,
            'sub_branch_id' => $nearest['sub_branch']->id ?? null,
            'name' => $data['name'],
            'contact_person' => $data['contact_person'] ?? $merchant->contact_person,
            'phone' => $data['phone'] ?? $merchant->phone,
            'city' => $data['city'] ?? null,
            'area' => $data['area'] ?? null,
            'address' => $data['address'],
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'is_default' => (bool) ($data['is_default'] ?? false),
            'status' => $data['status'] ?? 'active',
        ]);

        return ApiResponse::success($pickupLocation->fresh(['branch', 'subBranch']), 'Pickup location updated.');
    }

    public function destroy(Request $request, MerchantPickupLocation $pickupLocation)
    {
        $merchant = $request->user()->merchant;
        abort_unless($merchant && $pickupLocation->merchant_id === $merchant->id, 403);

        $pickupLocation->delete();

        return ApiResponse::success(null, 'Pickup location deleted.');
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:100'],
            'area' => ['nullable', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:500'],
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
            'is_default' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
    }
}
