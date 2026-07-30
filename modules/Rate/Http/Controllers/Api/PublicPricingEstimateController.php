<?php

declare(strict_types=1);

namespace Modules\Rate\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Rate\Http\Requests\PublicWebsitePricingEstimateRequest;
use Modules\Rate\Services\PricingEngineService;
use Throwable;

final class PublicPricingEstimateController extends Controller
{
    public function __invoke(
        PublicWebsitePricingEstimateRequest $request,
        PricingEngineService $pricingEngine
    ): JsonResponse {
        $data = $request->validated();

        try {
            $submittedProducts = array_values($data['products']);

            $products = [];
            $productCount = 0;
            $packedWeightKg = 0.0;
            $containsFragileProduct = false;

            foreach ($submittedProducts as $product) {
                $quantity = (int) $product['quantity'];
                $unitWeight = (float) $product['unit_weight'];

                $productCount += $quantity;
                $packedWeightKg += $quantity * $unitWeight;

                if ($product['parcel_type'] === 'fragile') {
                    $containsFragileProduct = true;
                }

                /*
                 * The public form does not collect product value.
                 * Keep an internal zero value only for compatibility with
                 * the existing pricing engine product normalizer.
                 */
                $products[] = [
                    'product_id' => $product['product_id'] ?? null,
                    'name' => (string) $product['name'],
                    'quantity' => $quantity,
                    'unit_weight' => $unitWeight,
                    'unit_price' => 0.0,
                    'parcel_type' => (string) $product['parcel_type'],
                ];
            }

            $packedWeightKg = round($packedWeightKg, 3);
            $parcelType = $containsFragileProduct
                ? 'fragile'
                : 'non_fragile';

            /*
             * Public website packing policy:
             * all submitted products are consolidated into one physical
             * packet, matching the live marketplace single_per_store mode.
             */
            $pricingPayload = [
                'store_id' => null,

                'pickup_address' =>
                    (string) $data['pickup_address'],

                'pickup_latitude' =>
                    (float) $data['pickup_latitude'],

                'pickup_longitude' =>
                    (float) $data['pickup_longitude'],

                'delivery_address' =>
                    (string) $data['delivery_address'],

                'delivery_latitude' =>
                    (float) $data['delivery_latitude'],

                'delivery_longitude' =>
                    (float) $data['delivery_longitude'],

                'service_type' =>
                    (string) $data['service_type'],

                /*
                 * The public estimator calculates prepaid delivery charges
                 * only. Payment selection and collection amounts are not
                 * exposed on the landing-page form.
                 */
                'payment_type' => 'prepaid',
                'pod_amount' => 0.0,

                'products' => $products,

                'packets' => [
                    [
                        'packet_reference' =>
                            'PUBLIC-PKT-001',

                        'product_id' => null,

                        'name' =>
                            count($products) === 1
                                ? (string) $products[0]['name']
                                : 'Combined public website parcel',

                        'quantity' => 1,

                        'actual_weight_kg' =>
                            $packedWeightKg,

                        'unit_price' => 0.0,

                        'declared_value' => 0.0,

                        'parcel_type' =>
                            $parcelType,
                    ],
                ],

                'packet_count' => 1,
                'parcel_weight' => $packedWeightKg,
                'parcel_value' => 0.0,
                'parcel_type' => $parcelType,
            ];

            $result = $pricingEngine->calculate(
                $pricingPayload,
                null
            );

            $finalPrice = data_get(
                $result,
                'final_price'
            ) ?? data_get(
                $result,
                'breakdown.final_price'
            );

            if (
                $finalPrice === null ||
                (float) $finalPrice < 0
            ) {
                Log::warning(
                    'Public pricing returned no valid final price.',
                    [
                        'pricing_payload' => $pricingPayload,
                        'pricing_result' => $result,
                    ]
                );

                throw ValidationException::withMessages([
                    'pricing' => [
                        'A valid delivery price could not be calculated.',
                    ],
                ]);
            }

            $estimatedHours = data_get(
                $result,
                'estimated_hours'
            );

            $estimatedHours = $estimatedHours !== null
                ? (int) $estimatedHours
                : null;

            $validUntil = $this->serializeDate(
                data_get($result, 'valid_until')
            );

            return response()->json([
                'success' => true,
                'message' =>
                    'Delivery price estimated successfully.',
                'data' => [
                    'currency' =>
                        (string) data_get(
                            $result,
                            'currency',
                            'NPR'
                        ),

                    'price' =>
                        round((float) $finalPrice, 2),

                    'delivery_charge' =>
                        round((float) $finalPrice, 2),

                    'free_delivery_applied' => false,

                    'packing_policy' =>
                        'single_per_store',

                    'product_count' =>
                        $productCount,

                    'packet_count' => 1,

                    'packed_weight_kg' =>
                        $packedWeightKg,


                    'chargeable_weight_kg' =>
                        (float) data_get(
                            $result,
                            'weight_summary.total_chargeable_weight_kg',
                            $packedWeightKg
                        ),

                    'estimated_delivery_label' =>
                        $this->formatEstimatedDeliveryLabel(
                            $estimatedHours
                        ),

                    'estimated_delivery_hours' =>
                        $estimatedHours,

                    'valid_until' =>
                        $validUntil,
                ],
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            Log::error(
                'Public website pricing estimate failed.',
                [
                    'pickup_latitude' =>
                        $data['pickup_latitude'] ?? null,
                    'pickup_longitude' =>
                        $data['pickup_longitude'] ?? null,
                    'delivery_latitude' =>
                        $data['delivery_latitude'] ?? null,
                    'delivery_longitude' =>
                        $data['delivery_longitude'] ?? null,
                    'service_type' =>
                        $data['service_type'] ?? null,
                    'exception' =>
                        $exception->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' =>
                    'The delivery price could not be calculated.',
            ], 500);
        }
    }

    private function formatEstimatedDeliveryLabel(
        ?int $estimatedHours
    ): ?string {
        if (
            $estimatedHours === null ||
            $estimatedHours <= 0
        ) {
            return null;
        }

        if ($estimatedHours < 24) {
            return $estimatedHours === 1
                ? '1 hour'
                : "{$estimatedHours} hours";
        }

        if ($estimatedHours % 24 === 0) {
            $days = (int) ($estimatedHours / 24);

            return $days === 1
                ? '1 day'
                : "{$days} days";
        }

        return sprintf(
            '%.1f days',
            $estimatedHours / 24
        );
    }

    private function serializeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        return Carbon::parse((string) $value)
            ->toIso8601String();
    }
}
