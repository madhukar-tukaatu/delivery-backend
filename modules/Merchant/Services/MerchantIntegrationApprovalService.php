<?php

namespace Modules\Merchant\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Throwable;

class MerchantIntegrationApprovalService
{
    public function __construct(
        private readonly MerchantApiCredentialService $credentialService,
        private readonly StoreIntegrationCallbackService $callbackService,
    ) {
    }

    public function approve(Merchant $merchant, array $data, int $approvedBy): array
    {
        $callbackStatus = 'not_required';
        $credentialResult = null;

        $approvedMerchant = DB::transaction(function () use (
            $merchant,
            $data,
            $approvedBy,
            &$credentialResult
        ) {
            $merchant = Merchant::query()
                ->lockForUpdate()
                ->findOrFail($merchant->id);

            $currentStatus = (string) ($merchant->verification_status ?: $merchant->status);

            if (!in_array($currentStatus, [
                'pending',
                'pending_verification',
                'under_review',
                'more_info_required',
            ], true)) {
                throw ValidationException::withMessages([
                    'merchant' => ['This merchant application is not available for approval.'],
                ]);
            }

            if (!$merchant->pickupLocations()->exists()) {
                throw ValidationException::withMessages([
                    'pickup_location' => ['At least one pickup location is required.'],
                ]);
            }

            if (!$merchant->documents()->exists()) {
                throw ValidationException::withMessages([
                    'documents' => ['Merchant verification documents are required.'],
                ]);
            }

            $approvedServices = array_values($data['approved_services'] ?? []);

            $merchant->forceFill([
                'default_branch_id' => $data['default_branch_id'],
                'default_sub_branch_id' => $data['default_sub_branch_id'] ?? null,
                'approved_services' => $approvedServices,
                'status' => 'active',
                'verification_status' => 'approved',
                'approved_by' => $approvedBy,
                'approved_at' => now(),
                'rejection_reason' => null,
            ])->save();

            if ($merchant->application_source === 'store_manager') {
                $abilities = $this->abilitiesFromServices($approvedServices);
                $credentialResult = $this->credentialService
                    ->issuePrimaryCredential($merchant, $abilities);
            }

            return $merchant->fresh([
                'defaultBranch',
                'defaultSubBranch',
                'pickupLocations',
                'documents',
            ]);
        }, 3);

        if (
            $approvedMerchant->application_source === 'store_manager'
            && $credentialResult
            && $credentialResult['created']
        ) {
            try {
                $this->callbackService->sendApproved($approvedMerchant, $credentialResult);
                $callbackStatus = 'delivered';
            } catch (Throwable $exception) {
                report($exception);
                $callbackStatus = 'failed';
            }
        }

        return [
            'merchant' => $approvedMerchant,
            'credentials_created' => (bool) ($credentialResult['created'] ?? false),
            'callback_status' => $callbackStatus,
        ];
    }

    private function abilitiesFromServices(array $services): array
    {
        $map = [
            'delivery_pricing' => 'pricing:read',
            'quote_creation' => 'quotes:create',
            'shipment_creation' => 'shipments:create',
            'tracking' => 'tracking:read',
            'webhooks' => 'webhooks:manage',
            'cod' => 'cod:use',
            'returns' => 'returns:create',
        ];

        return collect($services)
            ->map(fn (string $service) => $map[$service] ?? null)
            ->filter()
            ->values()
            ->all();
    }
}
