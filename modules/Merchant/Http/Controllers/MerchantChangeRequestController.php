<?php

namespace Modules\Merchant\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Support\ApiResponse;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantChangeRequest;
use Modules\Merchant\Services\MerchantChangeRequestService;

class MerchantChangeRequestController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Merchant List Their Change Requests
    |--------------------------------------------------------------------------
    */

    /**
     * Get all change requests for the authenticated merchant.
     * 
     * GET /api/v1/merchant/change-requests
     */
    public function index(Request $request, MerchantChangeRequestService $service)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return ApiResponse::error('User is not associated with a merchant.', 400);
        }

        $requests = $service->getMerchantRequests($merchant)
            ->paginate(15);

        return ApiResponse::success(
            $requests,
            'Change requests retrieved successfully.'
        );
    }

    /**
     * Get a specific change request.
     * 
     * GET /api/v1/merchant/change-requests/{changeRequest}
     */
    public function show(Request $request, MerchantChangeRequest $changeRequest)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant || $changeRequest->merchant_id !== $merchant->id) {
            return ApiResponse::error('Unauthorized.', 403);
        }

        return ApiResponse::success(
            $changeRequest->load([
                'requestedByUser',
                'approvedByUser',
                'suggestedBranch',
                'suggestedSubBranch',
                'approvedBranch',
                'approvedSubBranch',
            ]),
            'Change request retrieved successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Unified Submit Endpoint (Like Store Manager)
    |--------------------------------------------------------------------------
    */

    /**
     * Unified endpoint for submitting any type of merchant change request.
     * Supports: location, documents, bank_details, business_profile, contact_info
     * 
     * POST /api/v1/merchant/change-requests/{merchantId}/submit
     * 
     * Request body:
     * {
     *   "change_type": "location|documents|bank_details|business_profile|contact_info",
     *   "reason": "Optional reason for the change",
     *   // Then include the relevant fields for that change type:
     *   
     *   // For location:
     *   "address": "...",
     *   "city": "...",
     *   "area": "...",
     *   "latitude": "...",
     *   "longitude": "...",
     *   
     *   // For documents:
     *   "documents": [{ "type": "...", "path": "..." }],
     *   
     *   // For bank_details:
     *   "bank_name": "...",
     *   "bank_account_name": "...",
     *   "bank_account_number": "...",
     *   "bank_branch": "...",
     *   
     *   // For business_profile:
     *   "business_name": "...",
     *   "business_type": "...",
     *   "pan_vat_number": "...",
     *   "website_url": "...",
     *   
     *   // For contact_info:
     *   "contact_person": "...",
     *   "phone": "..."
     * }
     */
    public function submit(
        Request $request,
        Merchant $merchant,
        MerchantChangeRequestService $service
    ) {
        $authMerchant = $request->user()->merchant;

        // Validate the merchant in URL matches authenticated merchant
        if (!$authMerchant || $merchant->id !== $authMerchant->id) {
            return ApiResponse::error('Unauthorized. You can only submit changes for your own merchant account.', 403);
        }

        $validated = $request->validate([
            'change_type' => 'required|string|in:' . implode(',', MerchantChangeRequest::getChangeTypes()),
            'reason' => 'nullable|string|max:1000',
            
            // Location fields
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'area' => 'nullable|string|max:100',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            
            // Business profile fields
            'business_name' => 'nullable|string|max:255',
            'business_type' => 'nullable|string|max:100',
            'pan_vat_number' => 'nullable|string|max:50',
            'website_url' => 'nullable|url|max:255',
            
            // Bank details fields
            'bank_name' => 'nullable|string|max:100',
            'bank_account_name' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:50',
            'bank_branch' => 'nullable|string|max:100',
            
            // Document fields
            'documents' => 'nullable|array',
            
            // Contact info fields
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        // Validate required fields based on change type
        $validated = $this->validateChangeTypeRequiredFields($validated);

        try {
            $changeRequest = $service->submitChangeRequest(
                $merchant,
                $request->user(),
                $validated['change_type'],
                $validated
            );

            return ApiResponse::success(
                $changeRequest->load([
                    'merchant',
                    'requestedByUser',
                    'suggestedBranch',
                    'suggestedSubBranch',
                ]),
                'Change request submitted successfully. Services affected by this change have been suspended pending admin approval.'
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }

    /**
     * Validate required fields based on change type.
     */
    private function validateChangeTypeRequiredFields(array $data): array
    {
        $changeType = $data['change_type'];

        switch ($changeType) {
            case MerchantChangeRequest::TYPE_LOCATION:
                if (empty($data['address']) || !isset($data['latitude']) || !isset($data['longitude'])) {
                    throw new \Illuminate\Validation\ValidationException(
                        \Illuminate\Validation\Validator::make([], [])
                            ->errors()
                    );
                }
                break;

            case MerchantChangeRequest::TYPE_DOCUMENTS:
                if (empty($data['documents'])) {
                    throw new \Exception('Documents are required for document change requests.');
                }
                break;

            case MerchantChangeRequest::TYPE_BANK_DETAILS:
                if (empty($data['bank_name']) && empty($data['bank_account_name']) && 
                    empty($data['bank_account_number']) && empty($data['bank_branch'])) {
                    throw new \Exception('At least one bank detail field is required.');
                }
                break;

            case MerchantChangeRequest::TYPE_BUSINESS_PROFILE:
                if (empty($data['business_name']) && empty($data['business_type']) && 
                    empty($data['pan_vat_number']) && empty($data['website_url'])) {
                    throw new \Exception('At least one business profile field is required.');
                }
                break;

            case MerchantChangeRequest::TYPE_CONTACT_INFO:
                if (empty($data['contact_person']) && empty($data['phone'])) {
                    throw new \Exception('At least contact person or phone is required.');
                }
                break;
        }

        return $data;
    }

    /*
    |--------------------------------------------------------------------------
    | Cancel Change Request
    |--------------------------------------------------------------------------
    */

    /**
     * Cancel a pending change request.
     * 
     * POST /api/v1/merchant/change-requests/{changeRequest}/cancel
     */
    public function cancel(
        Request $request,
        MerchantChangeRequest $changeRequest,
        MerchantChangeRequestService $service
    ) {
        $merchant = $request->user()->merchant;

        if (!$merchant || $changeRequest->merchant_id !== $merchant->id) {
            return ApiResponse::error('Unauthorized.', 403);
        }

        try {
            $cancelled = $service->cancelChangeRequest(
                $changeRequest,
                $request->user()
            );

            return ApiResponse::success(
                $cancelled,
                'Change request cancelled. Suspended services have been resumed.'
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Get Current Merchant Status
    |--------------------------------------------------------------------------
    */

    /**
     * Get merchant's current suspension status.
     * 
     * GET /api/v1/merchant/service-status
     */
    public function getServiceStatus(Request $request)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return ApiResponse::error('User is not associated with a merchant.', 400);
        }

        return ApiResponse::success([
            'merchant_id' => $merchant->id,
            'merchant_name' => $merchant->name,
            'status' => $merchant->status,
            'has_suspended_services' => $merchant->hasSuspendedServices(),
            'suspended_services' => $merchant->getSuspendedServices(),
            'pending_change_request' => $merchant->hasPendingChangeRequest() ? [
                'id' => $merchant->pendingChangeRequest->id,
                'change_type' => $merchant->pendingChangeRequest->change_type,
                'affected_services' => $merchant->pendingChangeRequest->affected_services,
                'reason' => $merchant->pendingChangeRequest->reason,
                'status' => $merchant->pendingChangeRequest->status,
                'requested_at' => $merchant->pendingChangeRequest->requested_at,
                'change_summary' => $merchant->pendingChangeRequest->getChangeSummary(),
            ] : null,
            'services_suspended_at' => $merchant->services_suspended_at,
        ]);
    }
}
