<?php

namespace Modules\Rate\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Rate\Models\PricingReturnRule;

final class AdminPricingReturnRuleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => PricingReturnRule::query()
                ->orderBy('id')
                ->get(),
        ]);
    }

    public function update(
        Request $request,
        PricingReturnRule $pricingReturnRule
    ): JsonResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'base_rate_percentage' => ['required', 'numeric', 'min:0'],
            'distance_rate_per_km' => ['required', 'numeric', 'min:0'],
            'fixed_charge' => ['required', 'numeric', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);

        $pricingReturnRule->update([
            ...$validated,
            'updated_by' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Return-pricing rule updated.',
            'data' => $pricingReturnRule->fresh(),
        ]);
    }
}
