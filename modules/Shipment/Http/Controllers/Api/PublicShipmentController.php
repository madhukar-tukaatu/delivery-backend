<?php

namespace Modules\Shipment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Services\MerchantApiKeyGuard;
use Modules\Rate\Models\PricingQuote;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Models\ShipmentPriceBreakdown;

class PublicShipmentController extends Controller
{
    public function store(Request $request, MerchantApiKeyGuard $guard)
    {
        $apiKey = $guard->resolve($request);

        $validated = $request->validate([
            'quote_id' => ['required', 'string'],
            'merchant_order_id' => ['required', 'string', 'max:150'],

            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:50'],
            'customer_email' => ['nullable', 'email', 'max:150'],

            'pickup_address' => ['required', 'string', 'max:500'],
            'pickup_latitude' => ['required', 'numeric'],
            'pickup_longitude' => ['required', 'numeric'],

            'delivery_address' => ['required', 'string', 'max:500'],
            'delivery_latitude' => ['required', 'numeric'],
            'delivery_longitude' => ['required', 'numeric'],

            'parcel_weight' => ['nullable', 'numeric', 'min:0'],
            'parcel_value' => ['nullable', 'numeric', 'min:0'],

            'payment_type' => ['nullable', 'string', 'in:prepaid,cod'],
            'cod_amount' => ['nullable', 'numeric', 'min:0'],
            'service_type' => ['nullable', 'string', 'max:50'],
            'items' => ['nullable', 'array'],
        ]);

        $quote = PricingQuote::query()
            ->where('quote_number', $validated['quote_id'])
            ->where('merchant_id', $apiKey->merchant_id)
            ->first();

        if (!$quote) {
            throw ValidationException::withMessages([
                'quote_id' => 'Invalid quote ID.',
            ]);
        }

        if ($quote->expires_at && now()->greaterThan($quote->expires_at)) {
            throw ValidationException::withMessages([
                'quote_id' => 'Quote has expired. Please request a new delivery quote.',
            ]);
        }

        $trackingNumber = $this->generateTrackingNumber();

        return DB::transaction(function () use ($validated, $quote, $trackingNumber, $apiKey) {
            $shipment = Shipment::create([
                'tracking_number' => $trackingNumber,
                'quote_number' => $quote->quote_number,
                'merchant_id' => $apiKey->merchant_id,
                'merchant_order_id' => $validated['merchant_order_id'],

                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'customer_email' => $validated['customer_email'] ?? null,

                'pickup_address' => $validated['pickup_address'],
                'pickup_latitude' => $validated['pickup_latitude'],
                'pickup_longitude' => $validated['pickup_longitude'],

                'delivery_address' => $validated['delivery_address'],
                'delivery_latitude' => $validated['delivery_latitude'],
                'delivery_longitude' => $validated['delivery_longitude'],

                'parcel_weight' => $validated['parcel_weight'] ?? $quote->parcel_weight,
                'parcel_value' => $validated['parcel_value'] ?? $quote->parcel_value,

                'payment_type' => $validated['payment_type'] ?? $quote->payment_type,
                'cod_amount' => $validated['cod_amount'] ?? $quote->cod_amount,
                'service_type' => $validated['service_type'] ?? $quote->service_type,

                'delivery_fee' => $quote->final_price,
                'pickup_branch_id' => $quote->pickup_branch_id,
                'delivery_branch_id' => $quote->delivery_branch_id,
                'status' => 'created',
                'pricing_snapshot_json' => $quote->snapshot_json,
            ]);

            $breakdown = $quote->snapshot_json['breakdown'] ?? [];

            ShipmentPriceBreakdown::create([
                'shipment_id' => $shipment->id,
                'pricing_quote_id' => $quote->id,

                'base_pickup_fee' => $breakdown['base_pickup_fee'] ?? 0,
                'base_delivery_fee' => $breakdown['base_delivery_fee'] ?? 0,
                'base_transfer_fee' => $breakdown['base_transfer_fee'] ?? 0,

                'pickup_distance_km' => $breakdown['pickup_distance_km'] ?? 0,
                'pickup_extra_km' => $breakdown['pickup_extra_km'] ?? 0,
                'pickup_extra_charge' => $breakdown['pickup_extra_charge'] ?? 0,

                'delivery_distance_km' => $breakdown['delivery_distance_km'] ?? 0,
                'delivery_extra_km' => $breakdown['delivery_extra_km'] ?? 0,
                'delivery_extra_charge' => $breakdown['delivery_extra_charge'] ?? 0,

                'weight_charge' => $breakdown['weight_charge'] ?? 0,
                'cod_fee' => $breakdown['cod_fee'] ?? 0,
                'discount' => $breakdown['discount'] ?? 0,
                'final_price' => $quote->final_price,

                'snapshot_json' => $quote->snapshot_json,
            ]);

            return response()->json([
                'success' => true,
                'shipment_id' => $shipment->id,
                'tracking_number' => $trackingNumber,
                'status' => 'created',
                'delivery_fee' => $quote->final_price,
                'pickup_branch_id' => $quote->pickup_branch_id,
                'delivery_branch_id' => $quote->delivery_branch_id,
                'tracking_url' => 'https://tukaatuexpress.com/site/tracking?code=' . $trackingNumber,
            ], 201);
        });
    }

    private function generateTrackingNumber(): string
    {
        do {
            $trackingNumber = 'TKX-' . now()->format('ymd') . '-' . Str::upper(Str::random(6));
        } while (Shipment::query()->where('tracking_number', $trackingNumber)->exists());

        return $trackingNumber;
    }
}