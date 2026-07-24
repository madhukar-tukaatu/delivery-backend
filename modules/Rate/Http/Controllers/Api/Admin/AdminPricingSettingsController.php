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
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(
            100,
            max(1, (int) $request->integer('per_page', 20))
        );

        $settings = DB::table('pricing_settings')
            ->orderByDesc('id')
            ->paginate($perPage);

        $active = DB::table('pricing_settings')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'active' => $active,
                'history' => $settings,
            ],
        ]);
    }

    public function show(int $pricingSetting): JsonResponse
    {
        $setting = DB::table('pricing_settings')
            ->where('id', $pricingSetting)
            ->first();

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Pricing settings version not found.',
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
        $validated = $request->validated();
        $activate = (bool) $validated['activate'];
        unset($validated['activate']);

        $userId = $request->user()?->id;

        try {
            $id = DB::transaction(function () use (
                $validated,
                $activate,
                $userId
            ): int {
                if ($activate) {
                    DB::table('pricing_settings')
                        ->where('is_active', true)
                        ->update([
                            'is_active' => false,
                            'updated_at' => now(),
                            'updated_by' => $userId,
                        ]);
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
            }, 3);

            $this->cache->forgetSettings();

            return response()->json([
                'success' => true,
                'message' => 'Pricing settings version created successfully.',
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
                    : 'Unable to create pricing settings.',
            ], 422);
        }
    }

    /**
     * Editing creates a new version instead of mutating history.
     */
    public function update(
        UpdatePricingSettingsRequest $request,
        int $pricingSetting
    ): JsonResponse {
        $existing = DB::table('pricing_settings')
            ->where('id', $pricingSetting)
            ->first();

        if (!$existing) {
            return response()->json([
                'success' => false,
                'message' => 'Pricing settings version not found.',
            ], 404);
        }

        $validated = $request->validated();
        $activate = (bool) $validated['activate'];
        unset($validated['activate']);

        $userId = $request->user()?->id;

        try {
            $newId = DB::transaction(function () use (
                $validated,
                $activate,
                $userId
            ): int {
                if ($activate) {
                    DB::table('pricing_settings')
                        ->where('is_active', true)
                        ->update([
                            'is_active' => false,
                            'updated_at' => now(),
                            'updated_by' => $userId,
                        ]);
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
            }, 3);

            $this->cache->forgetSettings();

            return response()->json([
                'success' => true,
                'message' =>
                    'A new pricing settings version was created successfully.',
                'data' => DB::table('pricing_settings')
                    ->where('id', $newId)
                    ->first(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => app()->isLocal()
                    ? $exception->getMessage()
                    : 'Unable to update pricing settings.',
            ], 422);
        }
    }

    public function activate(
        Request $request,
        int $pricingSetting
    ): JsonResponse {
        $exists = DB::table('pricing_settings')
            ->where('id', $pricingSetting)
            ->exists();

        if (!$exists) {
            return response()->json([
                'success' => false,
                'message' => 'Pricing settings version not found.',
            ], 404);
        }

        $userId = $request->user()?->id;

        DB::transaction(function () use (
            $pricingSetting,
            $userId
        ): void {
            DB::table('pricing_settings')
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                    'updated_by' => $userId,
                ]);

            DB::table('pricing_settings')
                ->where('id', $pricingSetting)
                ->update([
                    'is_active' => true,
                    'updated_at' => now(),
                    'updated_by' => $userId,
                ]);
        }, 3);

        $this->cache->forgetSettings();

        return response()->json([
            'success' => true,
            'message' => 'Pricing settings version activated successfully.',
        ]);
    }

    public function destroy(int $pricingSetting): JsonResponse
    {
        $setting = DB::table('pricing_settings')
            ->where('id', $pricingSetting)
            ->first();

        if (!$setting) {
            return response()->json([
                'success' => false,
                'message' => 'Pricing settings version not found.',
            ], 404);
        }

        if ((bool) $setting->is_active) {
            return response()->json([
                'success' => false,
                'message' =>
                    'The active pricing settings version cannot be deleted.',
            ], 422);
        }

        DB::table('pricing_settings')
            ->where('id', $pricingSetting)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Inactive pricing settings version deleted.',
        ]);
    }
}
