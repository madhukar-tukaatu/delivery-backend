<?php

declare(strict_types=1);

namespace Modules\Rate\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Rate\Services\PricingEngineService;

final class AdminPricingTestController extends Controller
{
    public function test(
        Request $request,
        PricingEngineService $pricingEngine
    ): JsonResponse {
        $validated = $request->validate([
            'merchant_id' => [
                'nullable',
                'integer',
            ],

            'pickup_latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'pickup_longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            'delivery_latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'delivery_longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            'parcel_weight' => [
                'required',
                'numeric',
                'min:0.001',
            ],

            'parcel_value' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'parcel_type' => [
                'required',
                'in:fragile,non_fragile',
            ],

            'payment_type' => [
                'required',
                'in:pod,prepaid',
            ],

            'pod_amount' => [
                'nullable',
                'required_if:payment_type,pod',
                'numeric',
                'min:0',
            ],

            'service_type' => [
                'required',
                'in:standard,express,same_day',
            ],
        ]);

        $quote = $pricingEngine->calculate(
            $validated,
            isset($validated['merchant_id'])
                ? (int) $validated['merchant_id']
                : null
        );

        return response()->json([
            'success' => true,
            'data' => [
                ...$quote,

                'sla_due_at' =>
                    $quote['sla_due_at']
                        ->toIso8601String(),

                'valid_until' =>
                    $quote['valid_until']
                        ->toIso8601String(),
            ],
        ]);
    }
}