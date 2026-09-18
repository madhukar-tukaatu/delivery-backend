<?php

namespace Modules\Merchant\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Support\ApiResponse;
use Modules\Merchant\Models\MerchantChangeRequest;
use Modules\Merchant\Services\MerchantChangeRequestService;

class AdminMerchantChangeRequestController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | List Pending Change Requests
    |--------------------------------------------------------------------------
    */

    /**
     * Get all pending change requests for admin review.
     * 
     * GET /api/v1/admin/change-requests
     */
    public function index(Request $request, MerchantChangeRequestService $service)
    {
        $query = $service->getPendingRequests();

        // Filter by change type if provided
        if ($request->has('change_type')) {
            $query->where('change_type', $request->input('change_type'));
        }

        // Filter by merchant if provided
        if ($request->has('merchant_id')) {
            $query->where('merchant_id', $request->input('merchant_id'));
        }

        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $changeRequests = $query->paginate(20);

        return ApiResponse::success(
            $changeRequests,
            'Pending change requests retrieved successfully.'
        );
    }

    /**
     * Get a specific change request for review.
     * 
     * GET /api/v1/admin/change-requests/{changeRequest}
     */
    public function show(MerchantChangeRequest $changeRequest)
    {
        return ApiResponse::success(
            $changeRequest->load([
                'merchant',
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
    | Approve Change Request
    |--------------------------------------------------------------------------
    */

    /**
     * Approve a change request and apply changes to merchant.
     * 
     * POST /api/v1/admin/change-requests/{changeRequest}/approve
     */
    public function approve(
        Request $request,
        MerchantChangeRequest $changeRequest,
        MerchantChangeRequestService $service
    ) {
        $validated = $request->validate([
            'branch_id' => 'nullable|integer|exists:branches,id',
            'sub_branch_id' => 'nullable|integer|exists:branches,id',
            'remarks' => 'nullable|string|max:1000',
        ]);

        try {
            $approved = $service->approveChangeRequest(
                $changeRequest,
                $request->user(),
                $validated
            );

            return ApiResponse::success(
                $approved,
                'Change request approved. Merchant details updated and services resumed.'
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reject Change Request
    |--------------------------------------------------------------------------
    */

    /**
     * Reject a change request.
     * 
     * POST /api/v1/admin/change-requests/{changeRequest}/reject
     */
    public function reject(
        Request $request,
        MerchantChangeRequest $changeRequest,
        MerchantChangeRequestService $service
    ) {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        try {
            $rejected = $service->rejectChangeRequest(
                $changeRequest,
                $request->user(),
                $validated['reason']
            );

            return ApiResponse::success(
                $rejected,
                'Change request rejected. Merchant services have been resumed.'
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | View Merchants with Suspended Services
    |--------------------------------------------------------------------------
    */

    /**
     * Get all merchants with suspended services.
     * 
     * GET /api/v1/admin/merchants-with-suspended-services
     */
    public function getMerchantsWithSuspendedServices(
        Request $request,
        MerchantChangeRequestService $service
    ) {
        $merchants = $service->getMerchantsWithSuspendedServices()
            ->paginate(20);

        return ApiResponse::success(
            $merchants,
            'Merchants with suspended services retrieved successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Mark as Under Review
    |--------------------------------------------------------------------------
    */

    /**
     * Mark a change request as under review by admin.
     * 
     * POST /api/v1/admin/change-requests/{changeRequest}/start-review
     */
    public function startReview(
        Request $request,
        MerchantChangeRequest $changeRequest
    ) {
        if (!$changeRequest->isPending()) {
            return ApiResponse::error(
                'Only pending requests can be marked as under review.',
                400
            );
        }

        $changeRequest->update([
            'status' => MerchantChangeRequest::STATUS_UNDER_REVIEW,
        ]);

        return ApiResponse::success(
            $changeRequest,
            'Change request marked as under review.'
        );
    }
}
