<?php

namespace Modules\Merchant\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Jobs\SendStoreIntegrationApprovalCallback;
use Modules\Merchant\Models\Merchant;

class MerchantIntegrationApprovalService
{
    public function __construct(
        private readonly MerchantApiCredentialService $credentialService,
    ) {
    }

    public function approve(Merchant $merchant, array $data, int $approvedBy): array
    {
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

            // MerchantOnboardingService already set status=active/approved before this runs
            if ($merchant->status !== 'active') {
                throw ValidationException::withMessages([
                    'merchant' => ['Merchant must be active before issuing integration credentials.'],
                ]);
            }

            if (!$merchant->integration_callback_url) {
                throw ValidationException::withMessages([
                    'callback_url' => ['The Store Manager callback URL is missing.'],
                ]);
            }

            if (!$merchant->integration_callback_secret) {
                throw ValidationException::withMessages([
                    'callback_secret' => ['The Store Manager callback secret is missing.'],
                ]);
            }

            $approvedServices = array_values($data['approved_services'] ?? []);

            $merchant->forceFill([
                'default_branch_id' => $data['default_branch_id'],
                'default_sub_branch_id' => $data['default_sub_branch_id'] ?? null,
                'approved_services' => $approvedServices,
                'status' => 'active',
                'verification_status' => 'approved',
                'integration_status' => 'approved',
                'integration_approved_at' => now(),
                'integration_callback_status' => 'pending',
                'verified_by' => $approvedBy,
                'verified_at' => now(),
                'rejection_reason' => null,
            ])->save();

            $abilities = $this->abilitiesFromServices($approvedServices);
            $credentialResult = $this->credentialService
                ->issuePrimaryCredential($merchant, $abilities);

            return $merchant->fresh([
                'defaultBranch',
                'defaultSubBranch',
                'pickupLocations',
                'documents',
                'apiKeys',
            ]);
        }, 3);

        if ($credentialResult && $credentialResult['created']) {
            SendStoreIntegrationApprovalCallback::dispatch($approvedMerchant->id)
                ->afterCommit()
                ->onQueue('webhooks');
        }

        return [
            'merchant' => $approvedMerchant,
            'credentials_created' => (bool) ($credentialResult['created'] ?? false),
        ];
    }

    private function abilitiesFromServices(array $services): array
    {
        $map = [
            'delivery_pricing' => 'pricing.quote',
            'quote_creation' => 'pricing.quote',
            'shipment_creation' => 'shipments.create',
            'tracking' => 'shipments.track',
            'webhooks' => 'webhooks.manage',
            'pod' => 'pod.use',
            'returns' => 'returns.create',
        ];

        return collect($services)
            ->map(fn (string $service) => $map[$service] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
