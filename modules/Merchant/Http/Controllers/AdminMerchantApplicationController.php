<?php

namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Services\MerchantOnboardingService;
use Modules\Merchant\Services\StoreIntegrationPostApprovalService;

class AdminMerchantApplicationController extends Controller
{
    public function index(Request $request)
    {
        $query = Merchant::query()
            ->with([
                'defaultBranch',
                'defaultSubBranch',
                'suggestedBranch',
                'suggestedSubBranch',
            ])
            ->whereIn('status', [
                'onboarding',
                'pending',
                'pending_verification',
                'more_info_required',
                'rejected',
                'active',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('source')) {
            $query->where('application_source', $request->input('source'));
        }

        if ($request->filled('q')) {
            $search = trim((string) $request->input('q'));

            $query->where(function ($query) use ($search) {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('owner_name', 'like', "%{$search}%")
                    ->orWhere('application_number', 'like', "%{$search}%")
                    ->orWhere('external_store_id', 'like', "%{$search}%");
            });
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return ApiResponse::success(
            $query->latest('id')->paginate($perPage)
        );
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
            'apiKeys',
        ]));
    }

    public function approve(
        Request $request,
        Merchant $merchant,
        MerchantOnboardingService $onboardingService,
        StoreIntegrationPostApprovalService $integrationService
    ) {
        $isStoreManagerApplication =
            $merchant->application_source === Merchant::SOURCE_STORE_MANAGER;

        $rules = [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'sub_branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];

        if ($isStoreManagerApplication) {
            $rules = array_merge($rules, [
                'rate_card_id' => [
                    'required',
                    'integer',
                    'exists:rate_cards,id',
                ],

                'approved_services' => [
                    'required',
                    'array',
                    'min:1',
                ],

                'approved_services.*' => [
                    'string',
                    Rule::in([
                        'delivery_pricing',
                        'quote_creation',
                        'shipment_creation',
                        'tracking',
                        'webhooks',
                        'cod',
                        'returns',
                    ]),
                ],
            ]);
        }

        $data = $request->validate($rules);

        // Existing approval path remains the source of truth for both public
        // website merchants and Store Manager applications.
        $approvedMerchant = $onboardingService->approve(
            $merchant,
            $request->user(),
            [
                'branch_id' => $data['branch_id'] ?? null,
                'sub_branch_id' => $data['sub_branch_id'] ?? null,
            ]
        );

        // Store Manager-specific work runs only after the existing approval
        // completes successfully. Public website merchants are untouched.
        if ($isStoreManagerApplication) {
            $approvedMerchant = $integrationService->completeApproval(
                $approvedMerchant,
                $data['approved_services'],
                (int) $data['rate_card_id']
            );
        }

        return ApiResponse::success(
            $approvedMerchant->fresh([
                'documents',
                'pickupLocations',
                'defaultBranch',
                'defaultSubBranch',
                'apiKeys',
            ]),
            $isStoreManagerApplication
                ? 'Store integration approved. API credentials and callback are being processed.'
                : 'Merchant approved and activated.'
        );
    }

    public function reject(
        Request $request,
        Merchant $merchant,
        MerchantOnboardingService $service
    ) {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return ApiResponse::success(
            $service->reject($merchant, $request->user(), $data['reason']),
            'Merchant rejected.'
        );
    }

    public function requestMoreInfo(
        Request $request,
        Merchant $merchant,
        MerchantOnboardingService $service
    ) {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
        ]);

        return ApiResponse::success(
            $service->requestMoreInfo($merchant, $data['message']),
            'More information requested.'
        );
    }
}
