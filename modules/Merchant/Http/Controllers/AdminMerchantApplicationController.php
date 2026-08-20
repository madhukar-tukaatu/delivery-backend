<?php
namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Merchant\Events\MerchantApplicationChanged;
use Modules\Merchant\Jobs\SendStoreIntegrationApprovalCallback;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Services\MerchantIntegrationApprovalService;
use Modules\Merchant\Services\MerchantOnboardingService;

class AdminMerchantApplicationController extends Controller
{
    /**
     * List merchant applications from both entry points:
     *
     * 1. Public Tukaatu merchant registration
     * 2. Store Manager integration
     */
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
                'under_review',
                'more_info_required',
                'rejected',
                'active',
            ]);

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->input('status')
            );
        }

        if ($request->filled('source')) {
            $query->where(
                'application_source',
                $request->input('source')
            );
        }

        if ($request->filled('q')) {
            $search = trim(
                (string) $request->input('q')
            );

            $query->where(
                function ($query) use ($search) {
                    $query
                        ->where(
                            'name',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'email',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'phone',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'owner_name',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'application_number',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhere(
                            'external_store_id',
                            'like',
                            "%{$search}%"
                        );
                }
            );
        }

        $perPage = min(
            max(
                (int) $request->input(
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

    public function retryCallback(Merchant $merchant): JsonResponse
    {
        if ($merchant->application_source !== Merchant::SOURCE_STORE_MANAGER) {
            return response()->json([
                'message' => 'This merchant does not use store integration callbacks.',
            ], 422);
        }

        if ($merchant->integration_status !== 'approved') {
            return response()->json([
                'message' => 'The merchant must be approved before the integration callback can be retried.',
            ], 422);
        }

        if (! $merchant->integration_callback_url) {
            return response()->json([
                'message' => 'Integration callback URL is not configured.',
            ], 422);
        }

        DB::transaction(function () use ($merchant): void {
            $merchant->forceFill([
                'integration_callback_status'  => 'pending',
                'integration_callback_error'   => null,
                'integration_callback_sent_at' => null,
            ])->save();

            SendStoreIntegrationApprovalCallback::dispatch($merchant->id)
                ->onQueue('webhooks');
        });

        return response()->json([
            'message'            => 'Integration callback retry has been queued.',
            'merchant_id'        => $merchant->id,
            'application_number' => $merchant->application_number,
            'callback_status'    => 'pending',
        ]);
    }

    /**
     * Display one merchant application.
     */
    public function show(Merchant $merchant)
    {
        return ApiResponse::success(
            $this->freshMerchant($merchant)
        );
    }

    /**
     * Approve either:
     *
     * - Public website merchant
     * - Store Manager integration merchant
     */
    public function approve(
        Request $request,
        Merchant $merchant,
        MerchantOnboardingService $onboardingService,
        MerchantIntegrationApprovalService $integrationService
    ) {
        $isStoreManagerApplication =
        $merchant->application_source ===
        Merchant::SOURCE_STORE_MANAGER;

        $rules = [
            'branch_id'     => [
                'nullable',
                'integer',
                'exists:branches,id',
            ],

            'sub_branch_id' => [
                'nullable',
                'integer',
                'exists:branches,id',
            ],
        ];

        /*
         * Store Manager integration approval only requires
         * the services that Tukaatu allows the Store to use.
         *
         * Pricing is resolved through the currently active
         * global Pricing Settings version.
         */
        if ($isStoreManagerApplication) {
            $rules['approved_services'] = [
                'required',
                'array',
                'min:1',
            ];

            $rules['approved_services.*'] = [
                'required',
                'string',

                Rule::in([
                    'delivery_pricing',
                    'quote_creation',
                    'shipment_creation',
                    'tracking',
                    'webhooks',
                    'pod',
                    'returns',
                ]),
            ];
        }

        $data =
        $request->validate($rules);

        /*
         * Shared approval process for both public merchants
         * and Store Manager merchants.
         */
        $approvedMerchant =
        $onboardingService->approve(
            $merchant,
            $request->user(),
            [
                'branch_id'     =>
                $data['branch_id'] ?? null,

                'sub_branch_id' =>
                $data['sub_branch_id'] ?? null,
            ]
        );

        /*
         * Defensive fallback when the service updates the
         * merchant but does not explicitly return it.
         */
        if (! $approvedMerchant instanceof Merchant) {
            $approvedMerchant = $merchant;
        }

        /*
         * Store Manager integrations additionally receive:
         *
         * - approved services
         * - API credentials
         * - integration activation
         * - post-approval callback
         *
         * No merchant rate card is required.
         */
        if ($isStoreManagerApplication) {
            $integrationResult = $integrationService->approve(
                $approvedMerchant,
                [
                    'approved_services'     => $data['approved_services'],
                    'default_branch_id'     => $approvedMerchant->default_branch_id,
                    'default_sub_branch_id' => $approvedMerchant->default_sub_branch_id,
                ],
                $request->user()->id
            );

            $approvedMerchant = $integrationResult['merchant'];
        }

        $approvedMerchant =
        $this->freshMerchant(
            $approvedMerchant
        );

        /*
         * Notify connected admin pages.
         */
        $this->broadcastChange(
            $approvedMerchant,
            'approved'
        );

        return ApiResponse::success(
            $approvedMerchant,

            $isStoreManagerApplication
                ? 'Store integration approved. API credentials and callback are being processed.'
                : 'Merchant approved and activated.'
        );
    }

    /**
     * Reject a merchant application.
     */
    public function reject(
        Request $request,
        Merchant $merchant,
        MerchantOnboardingService $service
    ) {
        $data = $request->validate([
            'reason' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        $result = $service->reject(
            $merchant,
            $request->user(),
            $data['reason']
        );

        /*
         * Support service methods that either return the
         * Merchant model or update the supplied model
         * without returning it.
         */
        $rejectedMerchant =
        $result instanceof Merchant
            ? $result
            : $merchant;

        $rejectedMerchant =
        $this->freshMerchant(
            $rejectedMerchant
        );

        $this->broadcastChange(
            $rejectedMerchant,
            'rejected'
        );

        return ApiResponse::success(
            $rejectedMerchant,
            'Merchant rejected.'
        );
    }

    /**
     * Ask a merchant to update or provide additional
     * information.
     */
    public function requestMoreInfo(
        Request $request,
        Merchant $merchant,
        MerchantOnboardingService $service
    ) {
        $data = $request->validate([
            'message' => [
                'required',
                'string',
                'max:1000',
            ],
        ]);

        $result =
        $service->requestMoreInfo(
            $merchant,
            $data['message']
        );

        $updatedMerchant =
        $result instanceof Merchant
            ? $result
            : $merchant;

        $updatedMerchant =
        $this->freshMerchant(
            $updatedMerchant
        );

        $this->broadcastChange(
            $updatedMerchant,
            'more_info_required'
        );

        return ApiResponse::success(
            $updatedMerchant,
            'More information requested.'
        );
    }

    /**
     * Reload the latest merchant data and relationships.
     */
    private function freshMerchant(
        Merchant $merchant
    ): Merchant {
        return $merchant->fresh([
            'documents',
            'pickupLocations',

            'defaultBranch',
            'defaultSubBranch',

            'suggestedBranch',
            'suggestedSubBranch',

            'apiKeys',
        ]);
    }

    /**
     * Broadcast a safe realtime update.
     *
     * The frontend receives the merchant ID and reloads
     * the protected REST endpoint for full application
     * information.
     */
    private function broadcastChange(
        Merchant $merchant,
        string $action
    ): void {
        event(
            new MerchantApplicationChanged(
                merchantId: $merchant->id,
                action: $action,
                source: $merchant->application_source,
                status: $merchant->status
            )
        );
    }
}
