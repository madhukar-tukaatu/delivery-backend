<?php

namespace Modules\Rate\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Rate\Http\Requests\UpdateTransferRoutePricingProfileRequest;
use Modules\Rate\Services\PricingCacheService;
use Throwable;

final class AdminTransferRoutePricingProfileController extends
    Controller
{
    public function __construct(
        private readonly PricingCacheService $cache
    ) {
    }

    public function show(
        int $branchTransferRoute
    ): JsonResponse {
        $route = DB::table('branch_transfer_routes')
            ->where('id', $branchTransferRoute)
            ->first();

        if (!$route) {
            return response()->json([
                'success' => false,
                'message' => 'Transfer route not found.',
            ], 404);
        }

        $globalActive = $this->globalActive();

        $customActive = DB::table('pricing_settings')
            ->where('scope_type', 'transfer_route')
            ->where(
                'branch_transfer_route_id',
                $branchTransferRoute
            )
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'success' => true,

            'data' => [
                'route_id' => (int) $route->id,

                'mode' => $customActive
                    ? 'custom'
                    : 'global',

                'global_active' => $globalActive,

                'custom_active' => $customActive,

                'effective' => $customActive
                    ?? $globalActive,
            ],
        ]);
    }

    public function update(
        UpdateTransferRoutePricingProfileRequest $request,
        int $branchTransferRoute
    ): JsonResponse {
        $route = DB::table('branch_transfer_routes')
            ->where('id', $branchTransferRoute)
            ->first();

        if (!$route) {
            return response()->json([
                'success' => false,
                'message' => 'Transfer route not found.',
            ], 404);
        }

        $validated = $request->validated();

        $mode = $validated['mode'];

        $userId = $request->user()?->id;

        try {
            $result = DB::transaction(
                function () use (
                    $route,
                    $mode,
                    $validated,
                    $userId
                ): array {
                    if ($mode === 'global') {
                        $this->deactivateRoutePricing(
                            routeId: (int) $route->id,
                            userId: $userId
                        );

                        return [
                            'mode' => 'global',
                            'pricing_setting_id' => null,
                        ];
                    }

                    $global = $this->globalActive();

                    if (!$global) {
                        throw ValidationException::withMessages([
                            'pricing_settings' => [
                                'An active global pricing version must exist before creating custom route pricing.',
                            ],
                        ]);
                    }

                    $custom = $validated[
                        'custom_pricing'
                    ];

                    $this->deactivateRoutePricing(
                        routeId: (int) $route->id,
                        userId: $userId
                    );

                    $id = DB::table('pricing_settings')
                        ->insertGetId([
                            'scope_type' =>
                                'transfer_route',

                            'branch_transfer_route_id' =>
                                (int) $route->id,

                            'name' =>
                                $custom['name']
                                ?? "{$route->route_code} Custom Pricing",

                            'base_weight_kg' =>
                                $custom['base_weight_kg'],

                            'base_distance_km' =>
                                $custom['base_distance_km'],

                            /*
                             * Local fields are not used for this
                             * transfer route but remain required
                             * by the shared pricing structure.
                             */
                            'local_extra_weight_rate' =>
                                $global
                                    ->local_extra_weight_rate,

                            'transfer_extra_weight_rate' =>
                                $custom[
                                    'transfer_extra_weight_rate'
                                ],

                            'extra_distance_rate' =>
                                $custom[
                                    'extra_distance_rate'
                                ],

                            'fragile_multiplier' =>
                                $custom[
                                    'fragile_multiplier'
                                ],

                            'local_same_day_multiplier' =>
                                $global
                                    ->local_same_day_multiplier,

                            'transfer_same_day_multiplier' =>
                                $custom[
                                    'transfer_same_day_multiplier'
                                ],

                            'same_day_cutoff_time' =>
                                $custom[
                                    'same_day_cutoff_time'
                                ],

                            'minimum_free_pickup_packets' =>
                                $custom[
                                    'minimum_free_pickup_packets'
                                ],

                            'small_pickup_charge' =>
                                $custom[
                                    'small_pickup_charge'
                                ],

                            'vat_percentage' =>
                                $custom[
                                    'vat_percentage'
                                ],

                            /*
                             * VAT is always inclusive.
                             */
                            'vat_inclusive' => true,

                            'weight_rounding' =>
                                $custom[
                                    'weight_rounding'
                                ],

                            'distance_rounding' =>
                                $custom[
                                    'distance_rounding'
                                ],

                            'money_rounding' =>
                                $custom[
                                    'money_rounding'
                                ],

                            'fragile_enabled' =>
                                $custom[
                                    'fragile_enabled'
                                ],

                            'same_day_enabled' =>
                                $custom[
                                    'same_day_enabled'
                                ],

                            'pickup_charge_enabled' =>
                                $custom[
                                    'pickup_charge_enabled'
                                ],

                            'vat_enabled' =>
                                $custom[
                                    'vat_enabled'
                                ],

                            /*
                             * Saving from the route edit form
                             * activates the custom values.
                             */
                            'is_active' => true,

                            'created_by' => $userId,
                            'updated_by' => $userId,

                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                    return [
                        'mode' => 'custom',
                        'pricing_setting_id' => $id,
                    ];
                },
                3
            );

            $this->cache->forgetSettings();

            return response()->json([
                'success' => true,

                'message' => $result['mode'] === 'custom'
                    ? 'Custom pricing saved for this route.'
                    : 'This route now uses global pricing.',

                'data' => $result,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,

                'message' => app()->isLocal()
                    ? $exception->getMessage()
                    : 'Unable to update route pricing.',
            ], 422);
        }
    }

    private function globalActive(): ?object
    {
        return DB::table('pricing_settings')
            ->where('scope_type', 'global')
            ->whereNull('branch_transfer_route_id')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }

    private function deactivateRoutePricing(
        int $routeId,
        ?int $userId
    ): void {
        DB::table('pricing_settings')
            ->where('scope_type', 'transfer_route')
            ->where(
                'branch_transfer_route_id',
                $routeId
            )
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'updated_by' => $userId,
                'updated_at' => now(),
            ]);
    }
}