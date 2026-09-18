<?php

namespace Modules\Merchant\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantChangeRequest;
use Modules\Merchant\Models\MerchantDocument;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Routing\Services\BranchLocatorService;
use Modules\Merchant\Events\MerchantChangeEvent;

class MerchantChangeRequestService
{
    public function __construct(
        private BranchLocatorService $branchLocator
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Request Submission
    |--------------------------------------------------------------------------
    */

    /**
     * Submit a merchant change request with granular service suspension.
     * 
     * @param Merchant $merchant
     * @param User $requestedByUser
     * @param string $changeType Change type (location, documents, bank_details, etc.)
     * @param array $data New values for the fields being changed
     * @return MerchantChangeRequest
     */
    public function submitChangeRequest(
        Merchant $merchant,
        User $requestedByUser,
        string $changeType,
        array $data
    ): MerchantChangeRequest {
        return DB::transaction(function () use ($merchant, $requestedByUser, $changeType, $data) {
            // Validate merchant is active
            if ($merchant->status !== 'active') {
                throw new \Exception(
                    'Change requests can only be submitted by active merchants.'
                );
            }

            // Check if merchant already has an active change request
            if ($merchant->hasPendingChangeRequest()) {
                throw new \Exception(
                    'Merchant already has an active change request pending approval.'
                );
            }

            // Get affected services for this change type
            $affectedServices = MerchantChangeRequest::getAffectedServicesForChangeType($changeType);

            // Prepare change request data
            $changeRequestData = [
                'merchant_id' => $merchant->id,
                'requested_by_user_id' => $requestedByUser->id,
                'change_type' => $changeType,
                'affected_services' => $affectedServices,
                'reason' => $data['reason'] ?? null,
                'status' => MerchantChangeRequest::STATUS_PENDING,
                'requested_at' => now(),
            ];

            // Handle location changes
            if ($changeType === MerchantChangeRequest::TYPE_LOCATION) {
                $changeRequestData = $this->handleLocationChange($merchant, $data, $changeRequestData);
            }

            // Handle business profile changes
            if ($changeType === MerchantChangeRequest::TYPE_BUSINESS_PROFILE) {
                $changeRequestData = $this->handleBusinessProfileChange($merchant, $data, $changeRequestData);
            }

            // Handle bank details changes
            if ($changeType === MerchantChangeRequest::TYPE_BANK_DETAILS) {
                $changeRequestData = $this->handleBankDetailsChange($merchant, $data, $changeRequestData);
            }

            // Handle document changes
            if ($changeType === MerchantChangeRequest::TYPE_DOCUMENTS) {
                $changeRequestData = $this->handleDocumentChanges($merchant, $data, $changeRequestData);
            }

            // Handle contact info changes
            if ($changeType === MerchantChangeRequest::TYPE_CONTACT_INFO) {
                $changeRequestData = $this->handleContactInfoChange($merchant, $data, $changeRequestData);
            }

            // Create the change request
            $request = MerchantChangeRequest::create($changeRequestData);

            // Suspend affected services
            $this->suspendServices($merchant, $affectedServices, $request);

            // Dispatch event
            event(new MerchantChangeEvent(
                $request,
                $merchant,
                $requestedByUser,
                'requested'
            ));

            return $request->fresh();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Change Type Handlers
    |--------------------------------------------------------------------------
    */

    private function handleLocationChange(Merchant $merchant, array $data, array $requestData): array
    {
        // VALIDATE: Check if location actually changed
        $previousLat = (float) ($merchant->pickup_lat ?? 0);
        $previousLng = (float) ($merchant->pickup_lng ?? 0);
        $newLat = (float) $data['latitude'];
        $newLng = (float) $data['longitude'];

        if ($previousLat === $newLat && $previousLng === $newLng) {
            throw new \Exception(
                'New location coordinates are identical to current location. No change detected.'
            );
        }

        // VALIDATE: Ensure minimum distance change (e.g., not just a few meters away)
        $distance = $this->calculateDistance($previousLat, $previousLng, $newLat, $newLng);
        if ($distance < 0.1) { // Less than 100 meters
            throw new \Exception(
                'New location is too close to current location (less than 100 meters). Minimum significant distance required.'
            );
        }

        // Auto-detect branch from new coordinates
        $location = $this->branchLocator->locate($newLat, $newLng);

        $requestData['new_address'] = $data['address'];
        $requestData['new_city'] = $data['city'] ?? null;
        $requestData['new_area'] = $data['area'] ?? null;
        $requestData['new_latitude'] = $newLat;
        $requestData['new_longitude'] = $newLng;
        $requestData['previous_address'] = $merchant->pickup_address;
        $requestData['previous_city'] = $merchant->pickup_city;
        $requestData['previous_area'] = $merchant->pickup_area;
        $requestData['previous_latitude'] = $previousLat;
        $requestData['previous_longitude'] = $previousLng;
        $requestData['suggested_branch_id'] = $location['branch']?->id;
        $requestData['suggested_sub_branch_id'] = $location['sub_branch']?->id;

        return $requestData;
    }

    /**
     * Calculate distance between two coordinates in kilometers using Haversine formula.
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // Radius of the earth in km

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(sin($latDelta / 2) ** 2 + cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2));
        return $angle * $earthRadius;
    }

    private function handleBusinessProfileChange(Merchant $merchant, array $data, array $requestData): array
    {
        // VALIDATE: Check if at least one field actually changed
        $hasChanges = false;

        if (isset($data['business_name']) && $data['business_name'] !== $merchant->name) {
            $hasChanges = true;
        }
        if (isset($data['business_type']) && $data['business_type'] !== $merchant->business_type) {
            $hasChanges = true;
        }
        if (isset($data['pan_vat_number']) && $data['pan_vat_number'] !== $merchant->pan_vat_number) {
            $hasChanges = true;
        }
        if (isset($data['website_url']) && $data['website_url'] !== $merchant->website_url) {
            $hasChanges = true;
        }

        if (!$hasChanges) {
            throw new \Exception(
                'No business profile changes detected. All new values match current values.'
            );
        }

        $requestData['new_business_name'] = $data['business_name'] ?? null;
        $requestData['previous_business_name'] = $merchant->name;
        $requestData['new_business_type'] = $data['business_type'] ?? null;
        $requestData['previous_business_type'] = $merchant->business_type;
        $requestData['new_pan_vat_number'] = $data['pan_vat_number'] ?? null;
        $requestData['previous_pan_vat_number'] = $merchant->pan_vat_number;
        $requestData['new_website_url'] = $data['website_url'] ?? null;
        $requestData['previous_website_url'] = $merchant->website_url;

        return $requestData;
    }

    private function handleBankDetailsChange(Merchant $merchant, array $data, array $requestData): array
    {
        // VALIDATE: Check if at least one field actually changed
        $hasChanges = false;

        if (isset($data['bank_name']) && $data['bank_name'] !== $merchant->bank_name) {
            $hasChanges = true;
        }
        if (isset($data['bank_account_name']) && $data['bank_account_name'] !== $merchant->bank_account_name) {
            $hasChanges = true;
        }
        if (isset($data['bank_account_number']) && $data['bank_account_number'] !== $merchant->bank_account_number) {
            $hasChanges = true;
        }
        if (isset($data['bank_branch']) && $data['bank_branch'] !== $merchant->bank_branch) {
            $hasChanges = true;
        }

        if (!$hasChanges) {
            throw new \Exception(
                'No bank details changes detected. All new values match current values.'
            );
        }

        $requestData['new_bank_name'] = $data['bank_name'] ?? null;
        $requestData['previous_bank_name'] = $merchant->bank_name;
        $requestData['new_bank_account_name'] = $data['bank_account_name'] ?? null;
        $requestData['previous_bank_account_name'] = $merchant->bank_account_name;
        $requestData['new_bank_account_number'] = $data['bank_account_number'] ?? null;
        $requestData['previous_bank_account_number'] = $merchant->bank_account_number;
        $requestData['new_bank_branch'] = $data['bank_branch'] ?? null;
        $requestData['previous_bank_branch'] = $merchant->bank_branch;

        return $requestData;
    }

    private function handleContactInfoChange(Merchant $merchant, array $data, array $requestData): array
    {
        $requestData['new_contact_person'] = $data['contact_person'] ?? null;
        $requestData['previous_contact_person'] = $merchant->contact_person;
        $requestData['new_phone'] = $data['phone'] ?? null;
        $requestData['previous_phone'] = $merchant->phone;

        return $requestData;
    }

    private function handleDocumentChanges(Merchant $merchant, array $data, array $requestData): array
    {
        // VALIDATE: Check if documents are actually provided
        if (empty($data['documents'])) {
            throw new \Exception(
                'No new documents provided. Please upload at least one document for resubmission.'
            );
        }

        // VALIDATE: Check that we have document files/paths
        $newDocuments = $data['documents'];
        if (!is_array($newDocuments) || count($newDocuments) === 0) {
            throw new \Exception(
                'Invalid documents format. Expected array of document paths.'
            );
        }

        // Store new documents
        $requestData['new_documents'] = $newDocuments;
        
        // Get old documents that will be superseded
        $oldDocuments = MerchantDocument::where('merchant_id', $merchant->id)
            ->where('status', 'approved')
            ->pluck('id')
            ->toArray();

        if (empty($oldDocuments)) {
            throw new \Exception(
                'No existing approved documents found. Cannot resubmit without existing documents.'
            );
        }

        $requestData['superseded_document_ids'] = $oldDocuments;

        return $requestData;
    }

    /*
    |--------------------------------------------------------------------------
    | Admin Approval
    |--------------------------------------------------------------------------
    */

    /**
     * Approve a change request and apply all changes to merchant.
     * 
     * @param MerchantChangeRequest $request
     * @param User $admin
     * @param array $data Optional: branch_id, sub_branch_id for location changes
     * @return MerchantChangeRequest
     */
    public function approveChangeRequest(
        MerchantChangeRequest $request,
        User $admin,
        array $data = []
    ): MerchantChangeRequest {
        return DB::transaction(function () use ($request, $admin, $data) {
            if (!$request->isActive()) {
                throw new \Exception(
                    'Only active change requests can be approved.'
                );
            }

            $merchant = $request->merchant;

            // Apply changes based on change type
            $this->applyChanges($merchant, $request, $data);

            // Update request
            $request->update([
                'status' => MerchantChangeRequest::STATUS_APPROVED,
                'approved_by_user_id' => $admin->id,
                'decision_at' => now(),
                'admin_remarks' => $data['remarks'] ?? null,
            ]);

            // Resume affected services
            $this->resumeServices($merchant, $request->affected_services);

            // Clear pending request
            $merchant->update([
                'pending_change_request_id' => null,
            ]);

            // Dispatch event
            event(new MerchantChangeEvent(
                $request,
                $merchant,
                $admin,
                'approved'
            ));

            return $request->fresh();
        });
    }

    private function applyChanges(Merchant $merchant, MerchantChangeRequest $request, array $data): void
    {
        $updateData = [];

        // Apply location changes
        if ($request->isLocationChange()) {
            $branchId = $data['branch_id'] ?? $request->suggested_branch_id;
            $subBranchId = $data['sub_branch_id'] ?? $request->suggested_sub_branch_id;

            $updateData = array_merge($updateData, [
                'pickup_address' => $request->new_address,
                'pickup_city' => $request->new_city,
                'pickup_area' => $request->new_area,
                'pickup_lat' => $request->new_latitude,
                'pickup_lng' => $request->new_longitude,
                'default_branch_id' => $branchId,
                'default_sub_branch_id' => $subBranchId,
            ]);

            // Update pickup location
            $merchant->pickupLocations()
                ->where('is_default', true)
                ->update([
                    'address' => $request->new_address,
                    'city' => $request->new_city,
                    'area' => $request->new_area,
                    'latitude' => $request->new_latitude,
                    'longitude' => $request->new_longitude,
                    'branch_id' => $branchId,
                    'sub_branch_id' => $subBranchId,
                    'status' => 'active',
                ]);

            // Update request with approved branches
            $request->update([
                'approved_branch_id' => $branchId,
                'approved_sub_branch_id' => $subBranchId,
            ]);
        }

        // Apply business profile changes
        if ($request->isBusinessProfileChange()) {
            $updateData = array_merge($updateData, [
                'name' => $request->new_business_name ?? $merchant->name,
                'business_type' => $request->new_business_type ?? $merchant->business_type,
                'pan_vat_number' => $request->new_pan_vat_number ?? $merchant->pan_vat_number,
                'website_url' => $request->new_website_url ?? $merchant->website_url,
            ]);
        }

        // Apply bank details changes
        if ($request->isBankDetailsChange()) {
            $updateData = array_merge($updateData, [
                'bank_name' => $request->new_bank_name ?? $merchant->bank_name,
                'bank_account_name' => $request->new_bank_account_name ?? $merchant->bank_account_name,
                'bank_account_number' => $request->new_bank_account_number ?? $merchant->bank_account_number,
                'bank_branch' => $request->new_bank_branch ?? $merchant->bank_branch,
            ]);
        }

        // Apply contact info changes
        if ($request->isContactInfoChange()) {
            $updateData = array_merge($updateData, [
                'contact_person' => $request->new_contact_person ?? $merchant->contact_person,
                'phone' => $request->new_phone ?? $merchant->phone,
            ]);
        }

        // Apply document changes
        if ($request->isDocumentChange()) {
            // Mark old documents as superseded
            if ($request->superseded_document_ids) {
                MerchantDocument::whereIn('id', $request->superseded_document_ids)
                    ->update(['status' => 'superseded']);
            }

            // New documents will be approved by the controller/API
        }

        if (count($updateData) > 0) {
            $merchant->update($updateData);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Admin Rejection
    |--------------------------------------------------------------------------
    */

    /**
     * Reject a change request.
     * 
     * @param MerchantChangeRequest $request
     * @param User $admin
     * @param string $reason
     * @return MerchantChangeRequest
     */
    public function rejectChangeRequest(
        MerchantChangeRequest $request,
        User $admin,
        string $reason
    ): MerchantChangeRequest {
        return DB::transaction(function () use ($request, $admin, $reason) {
            if (!$request->isActive()) {
                throw new \Exception(
                    'Only active change requests can be rejected.'
                );
            }

            $merchant = $request->merchant;

            $request->update([
                'status' => MerchantChangeRequest::STATUS_REJECTED,
                'approved_by_user_id' => $admin->id,
                'rejection_reason' => $reason,
                'decision_at' => now(),
            ]);

            // Resume services
            $this->resumeServices($merchant, $request->affected_services);

            // Clear pending request
            $merchant->update([
                'pending_change_request_id' => null,
            ]);

            // Dispatch event
            event(new MerchantChangeEvent(
                $request,
                $merchant,
                $admin,
                'rejected'
            ));

            return $request->fresh();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Merchant Cancellation
    |--------------------------------------------------------------------------
    */

    /**
     * Allow merchant to cancel their own change request.
     * 
     * @param MerchantChangeRequest $request
     * @param User $user
     * @return MerchantChangeRequest
     */
    public function cancelChangeRequest(
        MerchantChangeRequest $request,
        User $user
    ): MerchantChangeRequest {
        return DB::transaction(function () use ($request, $user) {
            if (!$request->isActive()) {
                throw new \Exception(
                    'Only active change requests can be cancelled.'
                );
            }

            if ($user->merchant_id !== $request->merchant_id) {
                throw new \Exception(
                    'Unauthorized: You can only cancel your own change requests.'
                );
            }

            $merchant = $request->merchant;

            $request->update([
                'status' => MerchantChangeRequest::STATUS_CANCELLED,
                'decision_at' => now(),
            ]);

            // Resume services
            $this->resumeServices($merchant, $request->affected_services);

            // Clear pending request
            $merchant->update([
                'pending_change_request_id' => null,
            ]);

            return $request->fresh();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Service Suspension/Resumption
    |--------------------------------------------------------------------------
    */

    /**
     * Suspend specific services for a merchant.
     * 
     * @param Merchant $merchant
     * @param array $services Services to suspend
     * @param MerchantChangeRequest $request
     * @return void
     */
    private function suspendServices(
        Merchant $merchant,
        array $services,
        MerchantChangeRequest $request
    ): void {
        $currentSuspended = $merchant->suspended_services ?? [];
        $newSuspended = array_unique(array_merge($currentSuspended, $services));

        $merchant->update([
            'suspended_services' => $newSuspended,
            'services_suspended_at' => now(),
            'pending_change_request_id' => $request->id,
        ]);
    }

    /**
     * Resume specific services for a merchant.
     * 
     * @param Merchant $merchant
     * @param array $services Services to resume
     * @return void
     */
    private function resumeServices(
        Merchant $merchant,
        array $services
    ): void {
        $currentSuspended = $merchant->suspended_services ?? [];
        $newSuspended = array_diff($currentSuspended, $services);

        $merchant->update([
            'suspended_services' => array_values($newSuspended),
            'services_suspended_at' => count($newSuspended) > 0 ? $merchant->services_suspended_at : null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Query Helpers
    |--------------------------------------------------------------------------
    */

    public function getPendingRequests()
    {
        return MerchantChangeRequest::query()
            ->active()
            ->with(['merchant', 'requestedByUser', 'suggestedBranch', 'suggestedSubBranch'])
            ->orderBy('requested_at', 'desc');
    }

    public function getMerchantRequests(Merchant $merchant)
    {
        return MerchantChangeRequest::query()
            ->where('merchant_id', $merchant->id)
            ->with(['approvedByUser', 'approvedBranch', 'approvedSubBranch'])
            ->orderBy('requested_at', 'desc');
    }

    public function getMerchantsWithSuspendedServices()
    {
        return Merchant::query()
            ->where('suspended_services', '!=', '[]')
            ->with(['pendingChangeRequest'])
            ->orderBy('services_suspended_at', 'desc');
    }
}
