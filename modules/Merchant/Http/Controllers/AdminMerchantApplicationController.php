<?php

namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Services\MerchantOnboardingService;

class AdminMerchantApplicationController extends Controller
{
    public function index(Request $request)
    {
        $query = Merchant::query()
            ->with(['defaultBranch', 'defaultSubBranch'])
            ->whereIn('status', ['onboarding', 'pending_verification', 'more_info_required', 'rejected', 'active']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($x) use ($q) {
                $x->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('owner_name', 'like', "%{$q}%");
            });
        }

        return ApiResponse::success($query->latest()->paginate((int) $request->get('per_page', 20)));
    }

    public function show(Merchant $merchant)
    {
        return ApiResponse::success($merchant->load([
            'documents',
            'pickupLocations',
            'defaultBranch',
            'defaultSubBranch',
            'suggestedBranch',
            'suggestedSubBranch',
        ]));
    }

    public function approve(Request $request, Merchant $merchant, MerchantOnboardingService $service)
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'sub_branch_id' => ['nullable', 'exists:branches,id'],
        ]);

        return ApiResponse::success(
            $service->approve($merchant, $request->user(), $data),
            'Merchant approved and activated.'
        );
    }

    public function reject(Request $request, Merchant $merchant, MerchantOnboardingService $service)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return ApiResponse::success(
            $service->reject($merchant, $request->user(), $data['reason']),
            'Merchant rejected.'
        );
    }

    public function requestMoreInfo(Request $request, Merchant $merchant, MerchantOnboardingService $service)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        return ApiResponse::success(
            $service->requestMoreInfo($merchant, $data['message']),
            'More information requested.'
        );
    }
}
