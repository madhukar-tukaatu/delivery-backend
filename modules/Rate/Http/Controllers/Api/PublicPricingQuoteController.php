<?php

namespace Modules\Rate\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Modules\Rate\Http\Requests\StoreMultiStorePricingQuoteRequest;
use Modules\Rate\Http\Requests\StorePublicPricingQuoteRequest;
use Modules\Rate\Services\MultiStorePricingService;
use Modules\Rate\Services\PricingEngineService;
use Throwable;

final class PublicPricingQuoteController extends Controller
{

    /**
     * Calculate a delivery charge without saving a quote
     * and without creating a shipment.
     */
    public function checkPrice(
        StorePublicPricingQuoteRequest $request,
        PricingEngineService $pricingEngine
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $merchantId = $request->attributes->get(
                'merchant_id'
            );

            $quote = $pricingEngine->calculate(
                $validated,
                $merchantId !== null
                    ? (int) $merchantId
                    : null
            );

            $productCount = 0;

            if (!empty($validated['products'])) {
                $productCount = collect(
                    $validated['products']
                )->sum(
                    static fn (array $product): int =>
                        (int) ($product['quantity'] ?? 0)
                );
            } elseif (!empty($validated['packets'])) {
                $productCount = count(
                    $validated['packets']
                );
            } elseif (isset($validated['parcel_weight'])) {
                $productCount = 1;
            }

            return response()->json([
                'success' => true,

                'message' =>
                'Delivery charge calculated successfully.',

                'data' => [
                    'store_id' =>
                        isset($validated['store_id'])
                            ? (int) $validated['store_id']
                            : null,

                    'input_mode' => match (true) {
                        !empty($validated['packets']) =>
                            'packets',

                        !empty($validated['products']) =>
                            'products',

                        default =>
                            'legacy_single_parcel',
                    },

                    'products' =>
                        $validated['products'] ?? [],

                    /*
                     * The final packet breakdown comes from the
                     * pricing engine after product quantities are
                     * expanded into individual physical packets.
                     */
                    'packets' =>
                        $quote['packets'] ?? [],

                    'product_count' =>
                        $productCount,

                    'packet_count' =>
                        (int) (
                            $quote['packet_count']
                            ?? $validated['packet_count']
                        ),

                    /*
                     * Aggregate compatibility fields. Packet-level
                     * weights and types remain available in packets.
                     */
                    'parcel_weight' =>
                        (float) $validated['parcel_weight'],

                    'parcel_value' =>
                        (float) (
                            $validated['parcel_value']
                            ?? 0
                        ),

                    'parcel_type' =>
                        $validated['parcel_type'],

                    'payment_type' =>
                        $validated['payment_type'],

                    'pod_amount' =>
                        (float) (
                            $validated['pod_amount']
                            ?? 0
                        ),

                    'pickup_branch' =>
                        $quote['pickup_branch'],

                    'delivery_branch' =>
                        $quote['delivery_branch'],

                    'route' =>
                        $quote['route'],

                    'service_type' =>
                        $quote['service_type'],

                    'weight_summary' =>
                        $quote['weight_summary'] ?? [],

                    'breakdown' =>
                        $quote['breakdown'],

                    'delivery_charge' =>
                        (float) $quote['final_price'],

                    'currency' =>
                        $quote['currency'],

                    'vat' =>
                        $quote['vat'],

                    'estimated_hours' =>
                        $quote['estimated_hours'],

                    'sla_due_at' =>
                        $this->toIso8601(
                            $quote['sla_due_at'] ?? null
                        ),

                    'valid_until' =>
                        $this->toIso8601(
                            $quote['valid_until'] ?? null
                        ),

                    'quote_stored' => false,
                    'shipment_created' => false,
                ],
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,

                'message' => app()->isLocal()
                    ? $exception->getMessage()
                    : 'Unable to calculate the delivery charge.',

                'error_code' => app()->isLocal()
                    ? class_basename($exception)
                    : null,
            ], 422);
        }
    }

    /**
     * Create a pricing quote for one store/shipment.
     */
    public function storeSingle(
        StorePublicPricingQuoteRequest $request,
        PricingEngineService $pricingEngine
    ): JsonResponse {
        $validated = $request->validated();
        $merchantId = $this->resolveMerchantId($request);

        try {
            $result = DB::transaction(function () use (
                $validated,
                $merchantId,
                $pricingEngine
            ): array {
                /*
                 * Calculate the complete pricing result first.
                 *
                 * The service should return:
                 * - pickup_branch
                 * - delivery_branch
                 * - service_type
                 * - breakdown
                 * - final_price
                 * - currency
                 * - estimated_hours
                 * - sla_due_at
                 * - valid_until
                 */
                $quote = $pricingEngine->calculate(
                    $validated,
                    $merchantId
                );

                $this->validateCalculatedQuote($quote);

                $quoteNumber = $this->generateQuoteNumber();

                $pricingQuoteId = DB::table('pricing_quotes')
                    ->insertGetId([
                        'checkout_quote_id' => null,
                        'quote_number' => $quoteNumber,

                        'merchant_id' => $merchantId,
                        'store_id' => $validated['store_id'] ?? null,

                        'pickup_branch_id' =>
                        (int) $quote['pickup_branch']['id'],

                        'delivery_branch_id' =>
                        (int) $quote['delivery_branch']['id'],

                        'pickup_address' =>
                        $validated['pickup_address'],

                        'pickup_latitude' =>
                        $this->decimalOrNull(
                            $validated['pickup_latitude'] ?? null
                        ),

                        'pickup_longitude' =>
                        $this->decimalOrNull(
                            $validated['pickup_longitude'] ?? null
                        ),

                        'delivery_address' =>
                        $validated['delivery_address'],

                        'delivery_latitude' =>
                        $this->decimalOrNull(
                            $validated['delivery_latitude'] ?? null
                        ),

                        'delivery_longitude' =>
                        $this->decimalOrNull(
                            $validated['delivery_longitude'] ?? null
                        ),

                        'parcel_weight' =>
                        (float) $validated['parcel_weight'],

                        'parcel_value' =>
                        (float) ($validated['parcel_value'] ?? 0),

                        'parcel_type' =>
                        $validated['parcel_type'],

                        'payment_type' =>
                        $validated['payment_type'],

                        'pod_amount' =>
                        (float) ($validated['pod_amount'] ?? 0),

                        'service_type' =>
                        $quote['service_type']['code'],

                        'service_type_id' =>
                        (int) $quote['service_type']['id'],

                        'final_price' =>
                        (float) $quote['final_price'],

                        'currency' =>
                        $quote['currency'] ?? 'NPR',

                        'estimated_hours' =>
                        isset($quote['estimated_hours'])
                            ? (int) $quote['estimated_hours']
                            : null,

                        'sla_due_at' =>
                        $this->toDatabaseDateTime(
                            $quote['sla_due_at'] ?? null
                        ),

                        'expires_at' =>
                        $this->toDatabaseDateTime(
                            $quote['valid_until'] ?? null
                        ),

                        /*
                         * Store the complete immutable calculation.
                         * Confirmed shipments should use this snapshot rather
                         * than recalculating against future pricing rules.
                         */
                        'snapshot_json' =>
                        $this->encodeSnapshot($quote),

                        'status' => 'pending',

                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                return [
                    'pricing_quote_id' => $pricingQuoteId,
                    'quote_number' => $quoteNumber,
                    'quote' => $quote,
                ];
            }, 3);

            return response()->json([
                'success' => true,
                'message' =>
                'Pricing quote created successfully.',

                'data' => [
                    'pricing_quote_id' =>
                    (int) $result['pricing_quote_id'],

                    'quote_number' =>
                    $result['quote_number'],

                    ...$this->serializeQuote(
                        $result['quote']
                    ),
                ],
            ], 201);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            report($exception);

            return $this->errorResponse(
                message: 'Unable to save the pricing quote.',
                exception: $exception,
                status: 422
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->errorResponse(
                message: 'Unable to calculate the price.',
                exception: $exception,
                status: 422
            );
        }
    }

    /**
     * Create one checkout quote containing multiple store quotes.
     */
    public function storeMultiStore(
        StoreMultiStorePricingQuoteRequest $request,
        MultiStorePricingService $pricingService
    ): JsonResponse {
        $merchantId = $this->resolveMerchantId($request);

        try {
            /*
             * calculateAndStore() should handle its own DB transaction because
             * it creates the checkout quote and several child pricing quotes.
             */
            $result = $pricingService->calculateAndStore(
                $request->validated(),
                $merchantId
            );

            return response()->json([
                'success' => true,
                'message' =>
                'Multi-store checkout quote created successfully.',
                'data' => $this->serializeDateValues($result),
            ], 201);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return $this->errorResponse(
                message: 'Unable to calculate checkout pricing.',
                exception: $exception,
                status: 422
            );
        }
    }

    /**
     * Retrieve a multi-store checkout quote with its store-level quotes.
     */
    public function showCheckoutQuote(
        Request $request,
        string $quoteNumber
    ): JsonResponse {
        $merchantId = $this->resolveMerchantId($request);

        $checkoutQuote = DB::table('checkout_quotes')
            ->where('quote_number', $quoteNumber)
            ->when(
                $merchantId !== null,
                fn($query) => $query->where(
                    'merchant_id',
                    $merchantId
                )
            )
            ->first();

        if (!$checkoutQuote) {
            return response()->json([
                'success' => false,
                'message' => 'Checkout quote not found.',
            ], 404);
        }

        if ($this->isExpired($checkoutQuote->expires_at)) {
            $this->markCheckoutQuoteExpired(
                (int) $checkoutQuote->id
            );

            return response()->json([
                'success' => false,
                'message' => 'Checkout quote has expired.',
            ], 410);
        }

        if (
            isset($checkoutQuote->status) &&
            in_array(
                $checkoutQuote->status,
                ['cancelled', 'rejected'],
                true
            )
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                'This checkout quote is no longer available.',
            ], 410);
        }

        $storeQuotes = DB::table('pricing_quotes')
            ->where(
                'checkout_quote_id',
                $checkoutQuote->id
            )
            ->orderBy('id')
            ->get()
            ->map(
                fn(object $quote): array =>
                $this->formatStoreQuote($quote)
            )
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'checkout_quote_id' =>
                (int) $checkoutQuote->id,

                'checkout_quote_number' =>
                $checkoutQuote->quote_number,

                'merchant_id' =>
                $checkoutQuote->merchant_id !== null
                    ? (int) $checkoutQuote->merchant_id
                    : null,

                'currency' =>
                $checkoutQuote->currency ?? 'NPR',

                'store_count' =>
                (int) $checkoutQuote->store_count,

                'products_total' =>
                (float) $checkoutQuote->products_total,

                'delivery_total' =>
                (float) $checkoutQuote->delivery_total,

                'pod_total' =>
                (float) $checkoutQuote->pod_total,

                'grand_total' =>
                (float) $checkoutQuote->grand_total,

                'status' =>
                $checkoutQuote->status,

                'valid_until' =>
                $this->toIso8601(
                    $checkoutQuote->expires_at
                ),

                'is_expired' => false,

                'store_quotes' => $storeQuotes,
            ],
        ]);
    }

    /**
     * Retrieve one pricing quote.
     */
    public function showSingleQuote(
        Request $request,
        string $quoteNumber
    ): JsonResponse {
        $merchantId = $this->resolveMerchantId($request);

        $pricingQuote = DB::table('pricing_quotes')
            ->where('quote_number', $quoteNumber)
            ->when(
                $merchantId !== null,
                fn($query) => $query->where(
                    'merchant_id',
                    $merchantId
                )
            )
            ->first();

        if (!$pricingQuote) {
            return response()->json([
                'success' => false,
                'message' => 'Pricing quote not found.',
            ], 404);
        }

        if ($this->isExpired($pricingQuote->expires_at)) {
            $this->markPricingQuoteExpired(
                (int) $pricingQuote->id
            );

            return response()->json([
                'success' => false,
                'message' => 'Pricing quote has expired.',
            ], 410);
        }

        return response()->json([
            'success' => true,
            'data' => [
                ...$this->formatStoreQuote($pricingQuote),

                'merchant_id' =>
                $pricingQuote->merchant_id !== null
                    ? (int) $pricingQuote->merchant_id
                    : null,

                'checkout_quote_id' =>
                $pricingQuote->checkout_quote_id !== null
                    ? (int) $pricingQuote->checkout_quote_id
                    : null,

                'pickup_branch_id' =>
                $pricingQuote->pickup_branch_id !== null
                    ? (int) $pricingQuote->pickup_branch_id
                    : null,

                'delivery_branch_id' =>
                $pricingQuote->delivery_branch_id !== null
                    ? (int) $pricingQuote->delivery_branch_id
                    : null,

                'pickup_address' =>
                $pricingQuote->pickup_address,

                'delivery_address' =>
                $pricingQuote->delivery_address,

                'service_type' =>
                $pricingQuote->service_type,

                'currency' =>
                $pricingQuote->currency ?? 'NPR',

                'estimated_hours' =>
                $pricingQuote->estimated_hours !== null
                    ? (int) $pricingQuote->estimated_hours
                    : null,

                'sla_due_at' =>
                $this->toIso8601(
                    $pricingQuote->sla_due_at
                ),

                'valid_until' =>
                $this->toIso8601(
                    $pricingQuote->expires_at
                ),

                'is_expired' => false,

                'snapshot' =>
                $this->decodeSnapshot(
                    $pricingQuote->snapshot_json
                ),
            ],
        ]);
    }

    /**
     * Resolve merchant ID provided by public API middleware.
     */
    private function resolveMerchantId(
        Request $request
    ): ?int {
        $merchantId = $request->attributes->get(
            'merchant_id'
        );

        if (
            $merchantId === null ||
            $merchantId === ''
        ) {
            return null;
        }

        return (int) $merchantId;
    }

    /**
     * Ensure the pricing service returned the fields required for persistence.
     */
    private function validateCalculatedQuote(
        array $quote
    ): void {
        $requiredPaths = [
            'pickup_branch.id',
            'delivery_branch.id',
            'service_type.id',
            'service_type.code',
            'packet_count',
            'packets',
            'weight_summary.total_chargeable_weight_kg',
            'breakdown',
            'final_price',
            'currency',
            'valid_until',
        ];

        foreach ($requiredPaths as $path) {
            $value = data_get($quote, $path);

            if ($value === null) {
                throw ValidationException::withMessages([
                    'pricing' => [
                        "Pricing engine response is missing {$path}.",
                    ],
                ]);
            }
        }

        if (
            !is_array($quote['packets']) ||
            count($quote['packets']) === 0
        ) {
            throw ValidationException::withMessages([
                'pricing' => [
                    'Pricing engine response does not contain any packet calculations.',
                ],
            ]);
        }

        if (
            (int) $quote['packet_count']
            !== count($quote['packets'])
        ) {
            throw ValidationException::withMessages([
                'pricing' => [
                    'Pricing engine packet count does not match its packet breakdown.',
                ],
            ]);
        }

        if ((float) $quote['final_price'] < 0) {
            throw ValidationException::withMessages([
                'pricing' => [
                    'Calculated final price cannot be negative.',
                ],
            ]);
        }
    }

    /**
     * Generate a collision-resistant public quote number.
     */
    private function generateQuoteNumber(): string
    {
        do {
            $quoteNumber = sprintf(
                'QT-%s-%s',
                now()->format('YmdHisv'),
                Str::upper(Str::random(8))
            );

            $exists = DB::table('pricing_quotes')
                ->where('quote_number', $quoteNumber)
                ->exists();
        } while ($exists);

        return $quoteNumber;
    }

    /**
     * Convert calculated quote dates into JSON-safe ISO strings.
     */
    private function serializeQuote(
        array $quote
    ): array {
        return [
            ...$quote,

            'sla_due_at' => $this->toIso8601(
                $quote['sla_due_at'] ?? null
            ),

            'valid_until' => $this->toIso8601(
                $quote['valid_until'] ?? null
            ),
        ];
    }

    /**
     * Recursively convert Carbon values returned by the multi-store service.
     */
    private function serializeDateValues(
        mixed $value
    ): mixed {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)
                ->toIso8601String();
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] =
                    $this->serializeDateValues($item);
            }
        }

        return $value;
    }

    /**
     * Format one child pricing quote for API output.
     */
    private function formatStoreQuote(
        object $quote
    ): array {
        $snapshot = $this->decodeSnapshot(
            $quote->snapshot_json ?? null
        );

        $packets = is_array(
            $snapshot['packets'] ?? null
        )
            ? $snapshot['packets']
            : [];

        return [
            'pricing_quote_id' =>
                (int) $quote->id,

            'quote_number' =>
                $quote->quote_number,

            'store_id' =>
                $quote->store_id !== null
                    ? (int) $quote->store_id
                    : null,

            /*
             * Aggregate compatibility fields stored in the
             * pricing_quotes table.
             */
            'parcel_weight' =>
                (float) $quote->parcel_weight,

            'parcel_value' =>
                (float) ($quote->parcel_value ?? 0),

            'parcel_type' =>
                $quote->parcel_type,

            'payment_type' =>
                $quote->payment_type ?? null,

            'pod_amount' =>
                (float) ($quote->pod_amount ?? 0),

            /*
             * Packet-level immutable calculation restored from
             * snapshot_json.
             */
            'packet_count' =>
                isset($snapshot['packet_count'])
                    ? (int) $snapshot['packet_count']
                    : count($packets),

            'packets' =>
                $packets,

            'weight_summary' =>
                $snapshot['weight_summary'] ?? [],

            'pickup_branch' =>
                $snapshot['pickup_branch'] ?? null,

            'delivery_branch' =>
                $snapshot['delivery_branch'] ?? null,

            'route' =>
                $snapshot['route'] ?? null,

            'service_type_details' =>
                $snapshot['service_type'] ?? null,

            'delivery_fee' =>
                (float) $quote->final_price,

            'currency' =>
                $quote->currency ?? 'NPR',

            'status' =>
                $quote->status,

            'valid_until' =>
                $this->toIso8601(
                    $quote->expires_at ?? null
                ),

            'breakdown' =>
                $snapshot['breakdown'] ?? [],
        ];
    }

    /**
     * Safely encode an immutable pricing snapshot.
     */
    private function encodeSnapshot(
        array $quote
    ): string {
        try {
            return json_encode(
                $this->serializeDateValues($quote),
                JSON_THROW_ON_ERROR |
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw ValidationException::withMessages([
                'pricing' => [
                    'The calculated pricing snapshot could not be encoded.',
                ],
            ]);
        }
    }

    /**
     * Safely decode a stored pricing snapshot.
     */
    private function decodeSnapshot(
        ?string $snapshot
    ): array {
        if (!$snapshot) {
            return [];
        }

        try {
            $decoded = json_decode(
                $snapshot,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            return is_array($decoded)
                ? $decoded
                : [];
        } catch (JsonException) {
            return [];
        }
    }

    private function decimalOrNull(
        mixed $value
    ): ?float {
        if (
            $value === null ||
            $value === ''
        ) {
            return null;
        }

        return (float) $value;
    }

    private function toDatabaseDateTime(
        mixed $value
    ): ?string {
        if (
            $value === null ||
            $value === ''
        ) {
            return null;
        }

        return Carbon::parse($value)
            ->format('Y-m-d H:i:s');
    }

    private function toIso8601(
        mixed $value
    ): ?string {
        if (
            $value === null ||
            $value === ''
        ) {
            return null;
        }

        return Carbon::parse($value)
            ->toIso8601String();
    }

    private function isExpired(
        mixed $expiresAt
    ): bool {
        if (
            $expiresAt === null ||
            $expiresAt === ''
        ) {
            return false;
        }

        return Carbon::parse($expiresAt)->isPast();
    }

    private function markPricingQuoteExpired(
        int $pricingQuoteId
    ): void {
        DB::table('pricing_quotes')
            ->where('id', $pricingQuoteId)
            ->where('status', 'pending')
            ->update([
                'status' => 'expired',
                'updated_at' => now(),
            ]);
    }

    private function markCheckoutQuoteExpired(
        int $checkoutQuoteId
    ): void {
        DB::transaction(function () use (
            $checkoutQuoteId
        ): void {
            DB::table('checkout_quotes')
                ->where('id', $checkoutQuoteId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'expired',
                    'updated_at' => now(),
                ]);

            DB::table('pricing_quotes')
                ->where(
                    'checkout_quote_id',
                    $checkoutQuoteId
                )
                ->where('status', 'pending')
                ->update([
                    'status' => 'expired',
                    'updated_at' => now(),
                ]);
        });
    }

    private function errorResponse(
        string $message,
        Throwable $exception,
        int $status
    ): JsonResponse {
        return response()->json([
            'success' => false,

            'message' =>
            app()->isLocal()
                ? $exception->getMessage()
                : $message,

            'error_code' =>
            app()->isLocal()
                ? class_basename($exception)
                : null,
        ], $status);
    }
}