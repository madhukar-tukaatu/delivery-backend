<?php

/*
|--------------------------------------------------------------------------
| AdminPricingSettingsController update
|--------------------------------------------------------------------------
|
| Merge these methods and validation rules into the existing controller.
| The route file already exposes index, store, show, update, activate, destroy.
*/

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Rate\Models\PricingSetting;

private function pricingRules(Request $request): array
{
    return $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'included_weight_kg' => ['required', 'numeric', 'min:0'],
        'same_branch_excess_weight_rate' => ['required', 'numeric', 'min:0'],
        'transfer_branch_excess_weight_rate' => ['required', 'numeric', 'min:0'],
        'included_delivery_distance_km' => ['required', 'numeric', 'min:0'],
        'extra_distance_rate_per_km' => ['required', 'numeric', 'min:0'],
        'fragile_multiplier' => ['required', 'numeric', 'min:1'],
        'same_day_same_branch_multiplier' => ['required', 'numeric', 'min:1'],
        'same_day_transfer_branch_multiplier' => ['required', 'numeric', 'min:1'],
        'same_day_cutoff_time' => [
            'required',
            'date_format:H:i',
        ],
        'minimum_pickup_packet_count' => ['required', 'integer', 'min:1'],
        'low_packet_pickup_charge' => ['required', 'numeric', 'min:0'],
        'vat_percentage' => ['required', 'numeric', 'min:0'],
        'vat_inclusive' => ['required', 'boolean'],
        'quote_validity_minutes' => ['required', 'integer', 'min:1'],
        'effective_from' => ['nullable', 'date'],
        'effective_until' => ['nullable', 'date', 'after:effective_from'],
        'change_reason' => ['nullable', 'string', 'max:2000'],
        'is_active' => ['sometimes', 'boolean'],
    ]);
}

public function index(Request $request): JsonResponse
{
    $rows = PricingSetting::query()
        ->orderByDesc('is_active')
        ->orderByDesc('effective_from')
        ->orderByDesc('id')
        ->paginate(
            min(
                max($request->integer('per_page', 25), 1),
                100
            )
        );

    return response()->json([
        'success' => true,
        'data' => $rows,
    ]);
}

public function store(Request $request): JsonResponse
{
    $validated = $this->pricingRules($request);

    $setting = PricingSetting::query()->create([
        ...$validated,
        'is_active' => (bool) ($validated['is_active'] ?? false),
        'effective_from' => $validated['effective_from'] ?? now(),
        'created_by' => $request->user()?->id,
        'updated_by' => $request->user()?->id,
    ]);

    if ($setting->is_active) {
        $this->activateSetting(
            setting: $setting,
            userId: $request->user()?->id
        );
    }

    return response()->json([
        'success' => true,
        'message' => 'Pricing-settings version created.',
        'data' => $setting->fresh(),
    ], 201);
}

public function update(
    Request $request,
    PricingSetting $pricingSetting
): JsonResponse {
    $validated = $this->pricingRules($request);

    $pricingSetting->update([
        ...$validated,
        'updated_by' => $request->user()?->id,
    ]);

    if ((bool) ($validated['is_active'] ?? false)) {
        $this->activateSetting(
            setting: $pricingSetting,
            userId: $request->user()?->id
        );
    }

    return response()->json([
        'success' => true,
        'message' => 'Pricing-settings version updated.',
        'data' => $pricingSetting->fresh(),
    ]);
}

public function activate(
    Request $request,
    PricingSetting $pricingSetting
): JsonResponse {
    $this->activateSetting(
        setting: $pricingSetting,
        userId: $request->user()?->id
    );

    return response()->json([
        'success' => true,
        'message' => 'Pricing-settings version activated.',
        'data' => $pricingSetting->fresh(),
    ]);
}

private function activateSetting(
    PricingSetting $setting,
    ?int $userId
): void {
    DB::transaction(function () use ($setting, $userId): void {
        PricingSetting::query()
            ->whereKeyNot($setting->id)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'effective_until' => now(),
                'updated_by' => $userId,
                'updated_at' => now(),
            ]);

        $setting->update([
            'is_active' => true,
            'effective_from' => $setting->effective_from ?? now(),
            'effective_until' => null,
            'updated_by' => $userId,
        ]);
    });
}
