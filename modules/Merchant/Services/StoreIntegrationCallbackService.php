<?php

namespace Modules\Merchant\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Jobs\SendStoreIntegrationApprovalCallback;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantApiKey;
use Modules\Rate\Models\MerchantRateCard;

class StoreIntegrationPostApprovalService
{
    public function completeApproval(
        Merchant $merchant,
        array $approvedServices,
        int $rateCardId
    ): Merchant {
        if ($merchant->application_source !== Merchant::SOURCE_STORE_MANAGER) {
            return $merchant;
        }

        $merchant = DB::transaction(function () use (
            $merchant,
            $approvedServices,
            $rateCardId
        ) {
            $merchant = Merchant::query()
                ->lockForUpdate()
                ->findOrFail($merchant->id);

            if ($merchant->status !== 'active') {
                throw ValidationException::withMessages([
                    'merchant' => [
                        'The merchant must be active before completing Store Manager integration approval.',
                    ],
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

            $apiKey = MerchantApiKey::query()
                ->where('merchant_id', $merchant->id)
                ->lockForUpdate()
                ->first();

            if (!$apiKey) {
                throw ValidationException::withMessages([
                    'api_key' => [
                        'The merchant API key was not created by the existing approval service.',
                    ],
                ]);
            }

            MerchantRateCard::query()
                ->where('merchant_id', $merchant->id)
                ->update(['is_default' => false]);

            MerchantRateCard::query()->updateOrCreate(
                [
                    'merchant_id' => $merchant->id,
                    'rate_card_id' => $rateCardId,
                ],
                [
                    'is_default' => true,
                ]
            );

            $apiKey->forceFill([
                'permissions' => $this->mapPermissions($approvedServices),
                'status' => 'active',
                'is_active' => true,
            ])->save();

            $merchant->forceFill([
                'approved_services' => array_values(
                    array_unique($approvedServices)
                ),
                'integration_status' => 'approved',
                'integration_approved_at' => now(),
                'integration_callback_status' => 'pending',
                'integration_callback_error' => null,
            ])->save();

            return $merchant->fresh([
                'documents',
                'pickupLocations',
                'defaultBranch',
                'defaultSubBranch',
                'apiKeys',
            ]);
        }, 3);

        SendStoreIntegrationApprovalCallback::dispatch($merchant->id)
            ->afterCommit();

        return $merchant;
    }

    private function mapPermissions(array $services): array
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
            ->map(fn(string $service) => $map[$service] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
