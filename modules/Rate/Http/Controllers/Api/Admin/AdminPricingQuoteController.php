<?php

namespace Modules\Rate\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;

final class AdminPricingQuoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('pricing_quotes');

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('quote_number', 'like', "%{$search}%")
                    ->orWhere('pickup_address', 'like', "%{$search}%")
                    ->orWhere('delivery_address', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('service_type')) {
            $query->where(
                'service_type',
                $request->query('service_type')
            );
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', (int) $request->query('store_id'));
        }

        if ($request->filled('merchant_id')) {
            $query->where(
                'merchant_id',
                (int) $request->query('merchant_id')
            );
        }

        if ($request->filled('date_from')) {
            $query->whereDate(
                'created_at',
                '>=',
                $request->query('date_from')
            );
        }

        if ($request->filled('date_to')) {
            $query->whereDate(
                'created_at',
                '<=',
                $request->query('date_to')
            );
        }

        $quotes = $query
            ->orderByDesc('id')
            ->paginate(
                min(100, max(1, (int) $request->integer('per_page', 20)))
            );

        $quotes->setCollection(
            $quotes->getCollection()->map(
                fn(object $quote): array => $this->summary($quote)
            )
        );

        return response()->json([
            'success' => true,
            'data' => $quotes,
        ]);
    }

    public function show(int $pricingQuote): JsonResponse
    {
        $quote = DB::table('pricing_quotes')
            ->where('id', $pricingQuote)
            ->first();

        if (!$quote) {
            return response()->json([
                'success' => false,
                'message' => 'Pricing quote not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                ...$this->summary($quote),
                'merchant_id' => $quote->merchant_id !== null
                    ? (int) $quote->merchant_id
                    : null,
                'checkout_quote_id' =>
                    $quote->checkout_quote_id !== null
                        ? (int) $quote->checkout_quote_id
                        : null,
                'pickup_branch_id' =>
                    $quote->pickup_branch_id !== null
                        ? (int) $quote->pickup_branch_id
                        : null,
                'delivery_branch_id' =>
                    $quote->delivery_branch_id !== null
                        ? (int) $quote->delivery_branch_id
                        : null,
                'pickup_address' => $quote->pickup_address,
                'pickup_latitude' => $quote->pickup_latitude !== null
                    ? (float) $quote->pickup_latitude
                    : null,
                'pickup_longitude' => $quote->pickup_longitude !== null
                    ? (float) $quote->pickup_longitude
                    : null,
                'delivery_address' => $quote->delivery_address,
                'delivery_latitude' => $quote->delivery_latitude !== null
                    ? (float) $quote->delivery_latitude
                    : null,
                'delivery_longitude' => $quote->delivery_longitude !== null
                    ? (float) $quote->delivery_longitude
                    : null,
                'snapshot' => $this->decodeSnapshot(
                    $quote->snapshot_json ?? null
                ),
            ],
        ]);
    }

    /**
     * Quotes are immutable. Deletion is allowed only for closed records.
     */
    public function destroy(int $pricingQuote): JsonResponse
    {
        $quote = DB::table('pricing_quotes')
            ->where('id', $pricingQuote)
            ->first();

        if (!$quote) {
            return response()->json([
                'success' => false,
                'message' => 'Pricing quote not found.',
            ], 404);
        }

        if (
            !in_array(
                $quote->status,
                ['expired', 'cancelled', 'rejected'],
                true
            )
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Only expired, cancelled, or rejected quotes may be deleted.',
            ], 422);
        }

        DB::table('pricing_quotes')
            ->where('id', $pricingQuote)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pricing quote deleted successfully.',
        ]);
    }

    private function summary(object $quote): array
    {
        $snapshot = $this->decodeSnapshot(
            $quote->snapshot_json ?? null
        );

        return [
            'id' => (int) $quote->id,
            'quote_number' => $quote->quote_number,
            'store_id' => $quote->store_id !== null
                ? (int) $quote->store_id
                : null,
            'parcel_weight' => (float) $quote->parcel_weight,
            'parcel_value' => (float) ($quote->parcel_value ?? 0),
            'parcel_type' => $quote->parcel_type,
            'payment_type' => $quote->payment_type,
            'pod_amount' => (float) ($quote->pod_amount ?? 0),
            'service_type' => $quote->service_type,
            'final_price' => (float) $quote->final_price,
            'currency' => $quote->currency ?? 'NPR',
            'estimated_hours' => $quote->estimated_hours !== null
                ? (int) $quote->estimated_hours
                : null,
            'status' => $quote->status,
            'packet_count' => (int) (
                $snapshot['packet_count']
                ?? count($snapshot['packets'] ?? [])
            ),
            'pickup_branch' => $snapshot['pickup_branch'] ?? null,
            'delivery_branch' => $snapshot['delivery_branch'] ?? null,
            'expires_at' => $this->iso($quote->expires_at ?? null),
            'created_at' => $this->iso($quote->created_at ?? null),
            'updated_at' => $this->iso($quote->updated_at ?? null),
            'is_expired' => $quote->expires_at
                ? Carbon::parse($quote->expires_at)->isPast()
                : false,
        ];
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

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->toIso8601String();
    }
}
