<?php

namespace Modules\Rate\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Rate\Http\Requests\StorePricingSettingsRequest;
use Modules\Rate\Http\Requests\UpdatePricingSettingsRequest;
use Modules\Rate\Services\PricingCacheService;
use Throwable;

final class AdminPricingSettingsController extends Controller
{
    public function __construct(
        private readonly PricingCacheService $cache
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(
            100,
            max(1, (int) $request->integer('per_page', 20))
        );

        $history = DB::table('pricing_settings')
            ->where('scope_type', 'global')
            ->whereNull('branch_transfer_route_id')
            ->orderByDesc('id')
            ->paginate($perPage);

        $active = DB::table('pricing_settings')
            ->where('scope_type', 'global')
            ->whereNull('branch_transfer_route_id')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'active' => $active,
                'history' => $history,
            ],
        ]);
    }

    public function defaults(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => config('pricing_defaults.rules', []),
        ]);
    }

    public function show(int $pricingSetting): JsonResponse
    {
        $setting = DB::table('pricing_settings')
            ->where('id', $pricingSetting)
            ->where('scope_type', 'global')
            ->whereNull('branch_transfer_route_id')
            ->first();

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Global pricing settings version not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $setting,
        ]);
    }

    public function store(
        StorePricingSettingsRequest $request
    ): JsonResponse {
        return $this->createGlobalVersion(
            request: $request,
            createdMessage: 'Global pricing version created.',
            activatedMessage: 'Global pricing version created and activated.'
        );
    }

    public function update(
        UpdatePricingSettingsRequest $request,
        int $pricingSetting
    ): JsonResponse {
        $existing = DB::table('pricing_settings')
            ->where('id', $pricingSetting)
            ->where('scope_type', 'global')
            ->whereNull('branch_transfer_route_id')
            ->first();

        if (!$existing) {
            return response()->json([
                'success' => false,
                'message' => 'Global pricing settings version not found.',
            ], 404);
        }

        return $this->createGlobalVersion(
            request: $request,
            createdMessage: 'New global pricing version created.',
            activatedMessage: 'New global pricing version created and activated.'
        );
    }

    public function activate(
        Request $request,
        int $pricingSetting
    ): JsonResponse {
        $setting = DB::table('pricing_settings')
            ->where('id', $pricingSetting)
            ->where('scope_type', 'global')
            ->whereNull('branch_transfer_route_id')
            ->first();

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Global pricing settings version not found.',
            ], 404);
        }

        $userId = $request->user()?->id;

        DB::transaction(
            function () use ($pricingSetting, $userId): void {
                $this->deactivateGlobalSettings($userId);

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
            'message' => 'Global pricing settings version activated.',
        ]);
    }

    public function destroy(int $pricingSetting): JsonResponse
    {
        $setting = DB::table('pricing_settings')
            ->where('id', $pricingSetting)
            ->where('scope_type', 'global')
            ->whereNull('branch_transfer_route_id')
            ->first();

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Global pricing settings version not found.',
            ], 404);
        }

        if ((bool) $setting->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'The active global pricing version cannot be deleted.',
            ], 422);
        }

        DB::table('pricing_settings')
            ->where('id', $pricingSetting)
            ->delete();

        $this->cache->forgetSettings();

        return response()->json([
            'success' => true,
            'message' => 'Inactive global pricing version deleted.',
        ]);
    }

    private function createGlobalVersion(
        StorePricingSettingsRequest $request,
        string $createdMessage,
        string $activatedMessage
    ): JsonResponse {
        $validated = $request->validated();
        $activate = (bool) ($validated['activate'] ?? false);
        unset($validated['activate']);

        $validated['scope_type'] = 'global';
        $validated['branch_transfer_route_id'] = null;
        $validated['vat_inclusive'] = true;

        $userId = $request->user()?->id;

        try {
            $id = DB::transaction(
                function () use (
                    $validated,
                    $activate,
                    $userId
                ): int {
                    if ($activate) {
                        $this->deactivateGlobalSettings($userId);
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
                    ? $activatedMessage
                    : $createdMessage,
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
                    : 'Unable to save global pricing settings.',
            ], 422);
        }
    }

    private function deactivateGlobalSettings(
        ?int $userId
    ): void {
        DB::table('pricing_settings')
            ->where('scope_type', 'global')
            ->whereNull('branch_transfer_route_id')
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'updated_by' => $userId,
                'updated_at' => now(),
            ]);
    }
}
