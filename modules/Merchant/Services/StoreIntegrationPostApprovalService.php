<?php

namespace Modules\Merchant\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Jobs\SendStoreIntegrationApprovalCallback;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantApiKey;

class StoreIntegrationPostApprovalService
{
    /**
     * Complete Store Manager integration approval.
     *
     * This method:
     * - saves approved services
     * - updates API-key permissions
     * - activates the Store integration
     * - queues the Store approval callback
     *
     * Merchant-specific rate cards are not used.
     */
    public function completeApproval(
        Merchant $merchant,
        array $approvedServices
    ): Merchant {
        if (
            $merchant->application_source !==
            Merchant::SOURCE_STORE_MANAGER
        ) {
            return $merchant;
        }

        $merchant = DB::transaction(
            function () use (
                $merchant,
                $approvedServices
            ): Merchant {
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

                if (
                    empty(
                        $merchant->integration_callback_url
                    )
                ) {
                    throw ValidationException::withMessages([
                        'callback_url' => [
                            'The Store Manager callback URL is missing.',
                        ],
                    ]);
                }

                if (
                    empty(
                        $merchant->integration_callback_secret
                    )
                ) {
                    throw ValidationException::withMessages([
                        'callback_secret' => [
                            'The Store Manager callback secret is missing.',
                        ],
                    ]);
                }

                $apiKey = MerchantApiKey::query()
                    ->where(
                        'merchant_id',
                        $merchant->id
                    )
                    ->lockForUpdate()
                    ->first();

                if (!$apiKey) {
                    throw ValidationException::withMessages([
                        'api_key' => [
                            'The merchant API key was not created during merchant approval.',
                        ],
                    ]);
                }

                $approvedServices = array_values(
                    array_unique(
                        array_filter(
                            $approvedServices,
                            fn ($service) =>
                                is_string($service) &&
                                trim($service) !== ''
                        )
                    )
                );

                if ($approvedServices === []) {
                    throw ValidationException::withMessages([
                        'approved_services' => [
                            'At least one approved service is required.',
                        ],
                    ]);
                }

                $apiKey->forceFill([
                    'permissions' =>
                        $this->mapPermissions(
                            $approvedServices
                        ),

                    'status' => 'active',
                    'is_active' => true,
                ])->save();

                $merchant->forceFill([
                    'approved_services' =>
                        $approvedServices,

                    'integration_status' =>
                        'approved',

                    'integration_approved_at' =>
                        now(),

                    'integration_callback_status' =>
                        'pending',

                    'integration_callback_error' =>
                        null,
                ])->save();

                return $merchant->fresh([
                    'documents',
                    'pickupLocations',
                    'defaultBranch',
                    'defaultSubBranch',
                    'apiKeys',
                ]);
            },
            3
        );

        /*
         * The callback runs asynchronously so a remote Store
         * callback failure does not break merchant approval.
         */
        SendStoreIntegrationApprovalCallback::dispatch(
            $merchant->id
        )
            ->afterCommit()
            ->onQueue('webhooks');

        return $merchant;
    }

    /**
     * Convert approved Store services into API-key permissions.
     */
    private function mapPermissions(
        array $services
    ): array {
        $permissionMap = [
            'delivery_pricing' =>
                'pricing.quote',

            'quote_creation' =>
                'pricing.quote',

            'shipment_creation' =>
                'shipments.create',

            'tracking' =>
                'shipments.track',

            'webhooks' =>
                'webhooks.manage',

            'cod' =>
                'cod.use',

            'returns' =>
                'returns.create',
        ];

        return collect($services)
            ->map(
                fn (string $service) =>
                    $permissionMap[$service] ??
                    null
            )
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}