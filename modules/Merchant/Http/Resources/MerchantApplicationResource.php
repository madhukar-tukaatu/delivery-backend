<?php

namespace Modules\Merchant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $resolvedStatus = $this->status === 'active'
            ? 'active'
            : ($this->verification_status ?: $this->status);

        return [
            'id' => $this->id,
            'application_number' => $this->application_number,
            'source' => $this->application_source ?: 'public_website',
            'source_label' => match ($this->application_source) {
                'store_manager' => 'Store Manager',
                'admin' => 'Admin Created',
                default => 'Tukaatu Website',
            },
            'name' => $this->name ?: $this->store_name ?: $this->business_name,
            'store_name' => $this->store_name,
            'business_name' => $this->business_name,
            'owner_name' => $this->owner_name,
            'business_type' => $this->business_type,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $resolvedStatus,
            'raw_status' => $this->status,
            'verification_status' => $this->verification_status,
            'requested_services' => $this->requested_services ?: [],
            'approved_services' => $this->approved_services ?: [],
            'default_branch' => $this->whenLoaded('defaultBranch'),
            'default_sub_branch' => $this->whenLoaded('defaultSubBranch'),
            'suggested_branch' => $this->whenLoaded('suggestedBranch'),
            'pickup_locations' => $this->whenLoaded('pickupLocations'),
            'documents' => $this->whenLoaded('documents'),
            'submitted_at' => $this->submitted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
