<?php

namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantApiKey;
use Modules\Merchant\Models\MerchantPickupLocation;

class AdminMerchantVerificationController extends Controller
{
    public function index(Request $request)
    {
        $query = Merchant::query()
            ->with(['documents', 'defaultBranch', 'defaultSubBranch'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('verification_status', $request->status);
        }

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($x) use ($q) {
                $x->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function show(Merchant $merchant)
    {
        return ApiResponse::success($merchant->load([
            'documents',
            'defaultBranch',
            'defaultSubBranch',
            'pickupLocations.branch',
            'pickupLocations.subBranch',
        ]));
    }

    public function assignBranch(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'sub_branch_id' => ['nullable', 'exists:branches,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $merchant->update([
            'default_branch_id' => $data['branch_id'],
            'default_sub_branch_id' => $data['sub_branch_id'] ?? null,
            'admin_remarks' => $data['remarks'] ?? $merchant->admin_remarks,
        ]);

        return ApiResponse::success($merchant->fresh(), 'Merchant branch assigned.');
    }

    public function approve(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'sub_branch_id' => ['nullable', 'exists:branches,id'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'create_api_key' => ['nullable', 'boolean'],
        ]);

        $branchId = $data['branch_id'] ?? $merchant->default_branch_id ?? $merchant->suggested_branch_id;
        $subBranchId = $data['sub_branch_id'] ?? $merchant->default_sub_branch_id ?? $merchant->suggested_sub_branch_id;

        if (!$branchId) {
            return ApiResponse::error('Please assign a branch before approving merchant.', 422);
        }

        $merchant->update([
            'default_branch_id' => $branchId,
            'default_sub_branch_id' => $subBranchId,
            'status' => 'active',
            'verification_status' => 'approved',
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
            'rejected_reason' => null,
            'admin_remarks' => $data['remarks'] ?? null,
        ]);

        User::where('merchant_id', $merchant->id)->update(['is_active' => true]);

        MerchantPickupLocation::updateOrCreate([
            'merchant_id' => $merchant->id,
            'name' => 'Default Pickup Location',
        ], [
            'branch_id' => $branchId,
            'sub_branch_id' => $subBranchId,
            'contact_person' => $merchant->contact_person,
            'phone' => $merchant->phone,
            'city' => $merchant->pickup_city,
            'area' => $merchant->pickup_area,
            'address' => $merchant->pickup_address ?: $merchant->address,
            'latitude' => $merchant->pickup_lat,
            'longitude' => $merchant->pickup_lng,
            'is_default' => true,
            'status' => 'active',
        ]);

        $plainSecret = null;
        if ($data['create_api_key'] ?? true) {
            $plainSecret = Str::random(40);
            MerchantApiKey::firstOrCreate([
                'merchant_id' => $merchant->id,
                'name' => 'Live API Key',
            ], [
                'api_key' => 'live_'.Str::random(32),
                'api_secret_hash' => Hash::make($plainSecret),
                'environment' => 'live',
                'permissions' => ['shipments:create', 'shipments:read', 'rates:calculate'],
                'status' => 'active',
            ]);
        }

        return ApiResponse::success([
            'merchant' => $merchant->fresh(['pickupLocations', 'apiKeys']),
            'api_secret_once' => $plainSecret,
        ], 'Merchant approved and activated.');
    }

    public function reject(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $merchant->update([
            'status' => 'rejected',
            'verification_status' => 'rejected',
            'rejected_reason' => $data['reason'],
            'verified_by' => $request->user()->id,
            'verified_at' => now(),
        ]);

        User::where('merchant_id', $merchant->id)->update(['is_active' => false]);

        return ApiResponse::success($merchant->fresh(), 'Merchant rejected.');
    }

    public function requestMoreInfo(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            'remarks' => ['required', 'string', 'max:1000'],
        ]);

        $merchant->update([
            'verification_status' => 'more_info_required',
            'admin_remarks' => $data['remarks'],
        ]);

        return ApiResponse::success($merchant->fresh(), 'More information requested.');
    }
}
