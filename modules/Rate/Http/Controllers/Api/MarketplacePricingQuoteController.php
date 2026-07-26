<?php

namespace Modules\Rate\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use Modules\Rate\Http\Requests\StoreMultiStorePricingQuoteRequest;
use Modules\Rate\Services\MultiStorePricingService;
use Throwable;

final class MarketplacePricingQuoteController extends Controller
{
    /**
     * Supports one marketplace store or multiple stores.
     */
    public function check(
        StoreMultiStorePricingQuoteRequest $request,
        MultiStorePricingService $pricingService
    ): JsonResponse {
        $marketplaceId = $this->resolveMarketplaceId($request);

        try {
            $result = $pricingService->calculateOnly(
                validated: $request->validated(),
                marketplaceId: $marketplaceId
            );

            return response()->json([
                'success' => true,
                'message' =>
                    'Marketplace pricing and transfer routes calculated successfully.',
                'data' => $this->serialiseDates($result),
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return $this->errorResponse(
                'Unable to calculate marketplace pricing.',
                $exception,
                422
            );
        }
    }

    public function store(
        StoreMultiStorePricingQuoteRequest $request,
        MultiStorePricingService $pricingService
    ): JsonResponse {
        $marketplaceId = $this->resolveMarketplaceId($request);

        try {
            $result = $pricingService->calculateAndStore(
                validated: $request->validated(),
                marketplaceId: $marketplaceId
            );

            return response()->json([
                'success' => true,
                'message' =>
                    'Marketplace checkout quote created successfully.',
                'data' => $this->serialiseDates($result),
            ], 201);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return $this->errorResponse(
                'Unable to create marketplace checkout quote.',
                $exception,
                422
            );
        }
    }

    public function show(
        Request $request,
        string $quoteNumber
    ): JsonResponse {
        $marketplaceId = $this->resolveMarketplaceId($request);

        $checkoutQuote = DB::table('checkout_quotes')
            ->where('quote_number', $quoteNumber)
            ->where('marketplace_id', $marketplaceId)
            ->first();

        if (!$checkoutQuote) {
            return response()->json([
                'success' => false,
                'message' => 'Marketplace checkout quote not found.',
            ], 404);
        }

        if (
            $checkoutQuote->expires_at &&
            Carbon::parse($checkoutQuote->expires_at)->isPast()
        ) {
            $this->markExpired((int) $checkoutQuote->id);

            return response()->json([
                'success' => false,
                'message' => 'Marketplace checkout quote has expired.',
            ], 410);
        }

        $storeQuotes = DB::table('pricing_quotes')
            ->where('checkout_quote_id', $checkoutQuote->id)
            ->where('marketplace_id', $marketplaceId)
            ->orderBy('id')
            ->get()
            ->map(
                fn (object $quote): array =>
                    $this->formatStoreQuote($quote)
            )
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'checkout_quote_id' => (int) $checkoutQuote->id,
                'checkout_quote_number' =>
                    $checkoutQuote->quote_number,
                'external_checkout_id' =>
                    $checkoutQuote->external_checkout_id ?? null,
                'marketplace_id' => $marketplaceId,
                'currency' => $checkoutQuote->currency ?? 'NPR',
                'store_count' => (int) $checkoutQuote->store_count,
                'products_total' =>
                    (float) $checkoutQuote->products_total,
                'delivery_total' =>
                    (float) $checkoutQuote->delivery_total,
                'pod_total' => (float) $checkoutQuote->pod_total,
                'grand_total' =>
                    (float) $checkoutQuote->grand_total,
                'status' => $checkoutQuote->status,
                'valid_until' => $checkoutQuote->expires_at
                    ? Carbon::parse(
                        $checkoutQuote->expires_at
                    )->toIso8601String()
                    : null,
                'store_quotes' => $storeQuotes,
            ],
        ]);
    }

    private function formatStoreQuote(object $quote): array
    {
        $snapshot = $this->decodeSnapshot(
            $quote->snapshot_json ?? null
        );

        return [
            'pricing_quote_id' => (int) $quote->id,
            'quote_number' => $quote->quote_number,
            'store_id' => $quote->store_id !== null
                ? (int) $quote->store_id
                : null,
            'external_store_id' =>
                $quote->external_store_id ?? null,
            'packet_count' => isset($quote->packet_count)
                ? (int) $quote->packet_count
                : (int) ($snapshot['packet_count'] ?? 0),
            'parcel_weight' => (float) $quote->parcel_weight,
            'parcel_value' => (float) ($quote->parcel_value ?? 0),
            'parcel_type' => $quote->parcel_type,
            'payment_type' => $quote->payment_type,
            'pod_amount' => (float) ($quote->pod_amount ?? 0),
            'pickup_branch' => $snapshot['pickup_branch'] ?? null,
            'delivery_branch' => $snapshot['delivery_branch'] ?? null,
            'customer_pricing_route' => $snapshot['route'] ?? null,
            'transfer_route' => $snapshot['transfer_route'] ?? null,
            'service_type' =>
                $snapshot['service_type'] ?? $quote->service_type,
            'packets' => $snapshot['packets'] ?? [],
            'weight_summary' => $snapshot['weight_summary'] ?? [],
            'breakdown' => $snapshot['breakdown'] ?? [],
            'delivery_charge' => (float) $quote->final_price,
            'currency' => $quote->currency ?? 'NPR',
            'status' => $quote->status,
            'pricing_estimated_hours' =>
                (int) ($snapshot['pricing_estimated_hours'] ?? 0),
            'operational_estimated_hours' =>
                (int) ($snapshot['operational_estimated_hours'] ?? 0),
            'estimated_hours' => $quote->estimated_hours !== null
                ? (int) $quote->estimated_hours
                : null,
            'sla_due_at' => $quote->sla_due_at
                ? Carbon::parse($quote->sla_due_at)->toIso8601String()
                : null,
            'valid_until' => $quote->expires_at
                ? Carbon::parse($quote->expires_at)->toIso8601String()
                : null,
        ];
    }

    private function resolveMarketplaceId(Request $request): int
    {
        $marketplaceId = $request->attributes->get('marketplace_id');

        if (!$marketplaceId) {
            throw ValidationException::withMessages([
                'marketplace' => [
                    'Marketplace authentication context is missing.',
                ],
            ]);
        }

        return (int) $marketplaceId;
    }

    private function markExpired(int $checkoutQuoteId): void
    {
        DB::transaction(function () use ($checkoutQuoteId): void {
            DB::table('checkout_quotes')
                ->where('id', $checkoutQuoteId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'expired',
                    'updated_at' => now(),
                ]);

            DB::table('pricing_quotes')
                ->where('checkout_quote_id', $checkoutQuoteId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'expired',
                    'updated_at' => now(),
                ]);
        });
    }

    private function decodeSnapshot(?string $snapshot): array
    {
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

            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }

    private function serialiseDates(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->serialiseDates($item);
            }
        }

        return $value;
    }

    private function errorResponse(
        string $message,
        Throwable $exception,
        int $status
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => app()->isLocal()
                ? $exception->getMessage()
                : $message,
            'error_code' => app()->isLocal()
                ? class_basename($exception)
                : null,
        ], $status);
    }
}
