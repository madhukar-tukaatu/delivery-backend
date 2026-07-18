<?php
namespace Modules\Rate\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Rate\Http\Requests\StoreMultiStorePricingQuoteRequest;
use Modules\Rate\Http\Requests\StorePublicPricingQuoteRequest;
use Modules\Rate\Services\MultiStorePricingService;
use Modules\Rate\Services\PricingEngineService;
use Throwable;

final class PublicPricingQuoteController extends Controller
{
    public function storeSingle(
        StorePublicPricingQuoteRequest $request,
        PricingEngineService $pricingEngine
    ): JsonResponse {
        dd($request->all());
        $validated = $request->validated();

        $merchantId = $request->attributes->get(
            'merchant_id'
        );

        try {
            $quote = $pricingEngine->calculate(
                $validated,
                $merchantId !== null
                    ? (int) $merchantId
                    : null
            );

            $quoteNumber = sprintf(
                'QT-%s-%s',
                now()->format('YmdHis'),
                Str::upper(Str::random(6))
            );

            $pricingQuoteId = DB::table(
                'pricing_quotes'
            )->insertGetId([
                'checkout_quote_id' => null,
                'quote_number' => $quoteNumber,
                'merchant_id' => $merchantId,
                'store_id' =>
                    $validated['store_id']
                    ?? null,

                'pickup_branch_id' =>
                    $quote['pickup_branch']['id'],

                'delivery_branch_id' =>
                    $quote['delivery_branch']['id'],

                'pickup_address' =>
                    $validated['pickup_address'],

                'pickup_latitude' =>
                    $validated['pickup_latitude'],

                'pickup_longitude' =>
                    $validated['pickup_longitude'],

                'delivery_address' =>
                    $validated['delivery_address'],

                'delivery_latitude' =>
                    $validated['delivery_latitude'],

                'delivery_longitude' =>
                    $validated['delivery_longitude'],

                'parcel_weight' =>
                    $validated['parcel_weight'],

                'parcel_value' =>
                    $validated['parcel_value']
                    ?? 0,

                'parcel_type' =>
                    $validated['parcel_type'],

                'payment_type' =>
                    $validated['payment_type'],

                'pod_amount' =>
                    $validated['pod_amount']
                    ?? 0,

                'service_type' =>
                    $quote['service_type']['code'],

                'service_type_id' =>
                    $quote['service_type']['id'],

                'final_price' =>
                    $quote['final_price'],

                'currency' =>
                    $quote['currency'],

                'estimated_hours' =>
                    $quote['estimated_hours'],

                'sla_due_at' =>
                    $quote['sla_due_at'],

                'expires_at' =>
                    $quote['valid_until'],

                'snapshot_json' =>
                    json_encode(
                        $quote,
                        JSON_THROW_ON_ERROR
                    ),

                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' =>
                    'Pricing quote created successfully.',

                'data' => [
                    'pricing_quote_id' =>
                        $pricingQuoteId,

                    'quote_number' =>
                        $quoteNumber,

                    ...$quote,

                    'sla_due_at' =>
                        $quote['sla_due_at']
                            ->toIso8601String(),

                    'valid_until' =>
                        $quote['valid_until']
                            ->toIso8601String(),
                ],
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' =>
                    app()->isLocal()
                        ? $exception->getMessage()
                        : 'Unable to calculate the price.',
            ], 422);
        }
    }

    public function storeMultiStore(
        StoreMultiStorePricingQuoteRequest $request,
        MultiStorePricingService $pricingService
    ): JsonResponse {
        $merchantId = $request->attributes->get(
            'merchant_id'
        );

        try {
            $result =
                $pricingService->calculateAndStore(
                    $request->validated(),
                    $merchantId !== null
                        ? (int) $merchantId
                        : null
                );

            return response()->json([
                'success' => true,
                'message' =>
                    'Multi-store checkout quote created successfully.',
                'data' => $result,
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' =>
                    app()->isLocal()
                        ? $exception->getMessage()
                        : 'Unable to calculate checkout pricing.',
            ], 422);
        }
    }

    public function showCheckoutQuote(
        Request $request,
        string $quoteNumber
    ): JsonResponse {
        $merchantId = $request->attributes->get(
            'merchant_id'
        );

        $checkoutQuote = DB::table(
            'checkout_quotes'
        )
            ->where(
                'quote_number',
                $quoteNumber
            )
            ->when(
                $merchantId !== null,
                fn ($query) =>
                    $query->where(
                        'merchant_id',
                        $merchantId
                    )
            )
            ->first();

        if (!$checkoutQuote) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Checkout quote not found.',
            ], 404);
        }

        if (
            Carbon::parse(
                $checkoutQuote->expires_at
            )->isPast()
        ) {
            DB::table('checkout_quotes')
                ->where('id', $checkoutQuote->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'expired',
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => false,
                'message' =>
                    'Checkout quote has expired.',
            ], 410);
        }

        $storeQuotes = DB::table(
            'pricing_quotes'
        )
            ->where(
                'checkout_quote_id',
                $checkoutQuote->id
            )
            ->orderBy('id')
            ->get()
            ->map(function (object $quote): array {
                return [
                    'pricing_quote_id' =>
                        (int) $quote->id,

                    'quote_number' =>
                        $quote->quote_number,

                    'store_id' =>
                        $quote->store_id !== null
                            ? (int) $quote->store_id
                            : null,

                    'parcel_weight' =>
                        (float) $quote
                            ->parcel_weight,

                    'parcel_value' =>
                        (float) $quote
                            ->parcel_value,

                    'parcel_type' =>
                        $quote->parcel_type,

                    'delivery_fee' =>
                        (float) $quote
                            ->final_price,

                    'status' =>
                        $quote->status,

                    'breakdown' =>
                        json_decode(
                            $quote->snapshot_json,
                            true
                        )['breakdown']
                        ?? [],
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'checkout_quote_id' =>
                    (int) $checkoutQuote->id,

                'checkout_quote_number' =>
                    $checkoutQuote->quote_number,

                'currency' =>
                    $checkoutQuote->currency,

                'store_count' =>
                    (int) $checkoutQuote
                        ->store_count,

                'products_total' =>
                    (float) $checkoutQuote
                        ->products_total,

                'delivery_total' =>
                    (float) $checkoutQuote
                        ->delivery_total,

                'pod_total' =>
                    (float) $checkoutQuote
                        ->pod_total,

                'grand_total' =>
                    (float) $checkoutQuote
                        ->grand_total,

                'status' =>
                    $checkoutQuote->status,

                'valid_until' =>
                    $checkoutQuote->expires_at,

                'store_quotes' =>
                    $storeQuotes,
            ],
        ]);
    }
}