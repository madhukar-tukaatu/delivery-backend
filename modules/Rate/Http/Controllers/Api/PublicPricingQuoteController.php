<?php

namespace Modules\Rate\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Merchant\Services\MerchantApiKeyGuard;
use Modules\Rate\Models\PricingQuote;
use Modules\Rate\Services\PricingEngineService;

class PublicPricingQuoteController extends Controller
{
    public function store(
        Request $request,
        MerchantApiKeyGuard $guard,
        PricingEngineService $pricingEngine
    ) {
        $apiKey = $guard->resolve($request);

        $validated = $request->validate([
            'pickup_address' => ['nullable', 'string', 'max:500'],
            'pickup_latitude' => ['required', 'numeric'],
            'pickup_longitude' => ['required', 'numeric'],

            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_latitude' => ['required', 'numeric'],
            'delivery_longitude' => ['required', 'numeric'],

            'parcel_weight' => ['nullable', 'numeric', 'min:0'],
            'parcel_value' => ['nullable', 'numeric', 'min:0'],

            'payment_type' => ['nullable', 'string', 'in:prepaid,cod'],
            'cod_amount' => ['nullable', 'numeric', 'min:0'],

            'service_type' => ['nullable', 'string', 'max:50'],
        ]);

        $result = $pricingEngine->calculate($validated, $apiKey);

        $quoteNumber = 'QT-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(5));

        $quote = PricingQuote::create([
            'quote_number' => $quoteNumber,
            'merchant_id' => $apiKey->merchant_id,

            'pickup_branch_id' => $result['pickup_branch']['id'],
            'delivery_branch_id' => $result['delivery_branch']['id'],

            'pickup_address' => $validated['pickup_address'] ?? null,
            'pickup_latitude' => $validated['pickup_latitude'],
            'pickup_longitude' => $validated['pickup_longitude'],

            'delivery_address' => $validated['delivery_address'] ?? null,
            'delivery_latitude' => $validated['delivery_latitude'],
            'delivery_longitude' => $validated['delivery_longitude'],

            'parcel_weight' => $validated['parcel_weight'] ?? 0,
            'parcel_value' => $validated['parcel_value'] ?? 0,

            'payment_type' => $validated['payment_type'] ?? 'prepaid',
            'cod_amount' => $validated['cod_amount'] ?? 0,
            'service_type' => $validated['service_type'] ?? 'standard',

            'final_price' => $result['final_price'],
            'expires_at' => now()->addMinutes(30),
            'snapshot_json' => $result,
        ]);

        return response()->json([
            'success' => true,
            'quote_id' => $quote->quote_number,
            'currency' => 'NPR',
            'final_delivery_fee' => $result['final_price'],
            'valid_until' => $quote->expires_at?->toIso8601String(),
            'pickup_branch' => $result['pickup_branch'],
            'delivery_branch' => $result['delivery_branch'],
            'estimated_hours' => $result['estimated_hours'],
            'breakdown' => $result['breakdown'],
        ]);
    }
}