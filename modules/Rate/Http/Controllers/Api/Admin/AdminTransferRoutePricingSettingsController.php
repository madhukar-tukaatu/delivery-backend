<?php

namespace Modules\Rate\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Rate\Http\Requests\StorePricingSettingsRequest;
use Modules\Rate\Services\PricingCacheService;
use Throwable;

final class AdminTransferRoutePricingSettingsController extends Controller
{
    public function __construct(
        private readonly PricingCacheService $cache
    ) {
    }

    public function index(
        Request $request,
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

        $customActive = DB::table('pricing_settings')
            ->where('scope_type', 'transfer_route')
            ->where('branch_transfer_route_id', $branchTransferRoute)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        $globalActive = DB::table('pricing_settings')
            ->where('scope_type', 'global')
            ->whereNull('branch_transfer_route_id')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        $perPage = min(
            100,
            max(1, (int) $request->integer('per_page', 20))
        );

        $history = DB::table('pricing_settings')
            ->where('scope_type', 'transfer_route')
            ->where('branch_transfer_route_id', $branchTransferRoute)
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'route' => $route,
                'pricing_mode' => $customActive ? 'custom' : 'global',
                'effective' => $customActive ?? $globalActive,
                'custom_active' => $customActive,
                'global_active' => $globalActive,
                'history' => $history,
            ],
        ]);
    }

    public function store(
        StorePricingSettingsRequest $request,
        int $branchTransferRoute
    ): JsonResponse {
        $routeExists = DB::table('branch_transfer_routes')
            ->where('id', $branchTransferRoute)
            ->exists();

        if (!$routeExists) {
            return response()->json([
                'success' => false,
                'message' => 'Transfer route not found.',
            ], 404);
        }

        $validated = $request->validated();
        $activate = (bool) ($validated['activate'] ?? false);
        unset($validated['activate']);

        $validated['scope_type'] = 'transfer_route';
        $validated['branch_transfer_route_id'] = $branchTransferRoute;
        $validated['vat_inclusive'] = true;

        $userId = $request->user()?->id;

        try {
            $id = DB::transaction(
                function () use (
                    $validated,
                    $activate,
                    $branchTransferRoute,
                    $userId
                ): int {
                    if ($activate) {
                        $this->deactivateRouteSettings(
                            routeId: $branchTransferRoute,
                            userId: $userId
                        );
                    }

                    return DB::table('pricing_settings')
                        ->insertGetId([
                            ...$validated,
                            'is_active' => $activate,
                            'created_by' => $userId,
                            'updated_by' => $userId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                },
                3
            );

            $this->cache->forgetSettings();

            return response()->json([
                'success' => true,
                'message' => $activate
                    ? 'Route pricing version created and activated.'
                    : 'Route pricing version created.',
                'data' => DB::table('pricing_settings')
                    ->where('id', $id)
                    ->first(),
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => app()->isLocal()
                    ? $exception->getMessage()
                    : 'Unable to save route pricing settings.',
            ], 422);
        }
    }

    public function activate(
        Request $request,
        int $branchTransferRoute,
        int $pricingSetting
    ): JsonResponse {
        $setting = DB::table('pricing_settings')
            ->where('id', $pricingSetting)
            ->where('scope_type', 'transfer_route')
            ->where('branch_transfer_route_id', $branchTransferRoute)
            ->first();

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Route pricing version not found.',
            ], 404);
        }

        $userId = $request->user()?->id;

        DB::transaction(
            function () use (
                $branchTransferRoute,
                $pricingSetting,
                $userId
            ): void {
                $this->deactivateRouteSettings(
                    routeId: $branchTransferRoute,
                    userId: $userId
                );

                DB::table('pricing_settings')
                    ->where('id', $pricingSetting)
                    ->update([
                        'is_active' => true,
                        'updated_by' => $userId,
                        'updated_at' => now(),
                    ]);
            },
            3
        );

        $this->cache->forgetSettings();

        return response()->json([
            'success' => true,
            'message' => 'Route pricing version activated.',
        ]);
    }

    public function useGlobal(
        Request $request,
        int $branchTransferRoute
    ): JsonResponse {
        $routeExists = DB::table('branch_transfer_routes')
            ->where('id', $branchTransferRoute)
            ->exists();

        if (!$routeExists) {
            return response()->json([
                'success' => false,
                'message' => 'Transfer route not found.',
            ], 404);
        }

        $this->deactivateRouteSettings(
            routeId: $branchTransferRoute,
            userId: $request->user()?->id
        );

        $this->cache->forgetSettings();

        return response()->json([
            'success' => true,
            'message' => 'This route now uses global pricing settings.',
        ]);
    }

    public function destroy(
        int $branchTransferRoute,
        int $pricingSetting
    ): JsonResponse {
        $setting = DB::table('pricing_settings')
            ->where('id', $pricingSetting)
            ->where('scope_type', 'transfer_route')
            ->where('branch_transfer_route_id', $branchTransferRoute)
            ->first();

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Route pricing version not found.',
            ], 404);
        }

        if ((bool) $setting->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'The active route pricing version cannot be deleted.',
            ], 422);
        }

        DB::table('pricing_settings')
            ->where('id', $pricingSetting)
            ->delete();

        $this->cache->forgetSettings();

        return response()->json([
            'success' => true,
            'message' => 'Inactive route pricing version deleted.',
        ]);
    }

    private function deactivateRouteSettings(
        int $routeId,
        ?int $userId
    ): void {
        DB::table('pricing_settings')
            ->where('scope_type', 'transfer_route')
            ->where('branch_transfer_route_id', $routeId)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'updated_by' => $userId,
                'updated_at' => now(),
            ]);
    }
}
