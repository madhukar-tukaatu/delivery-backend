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
            $actualWeightKg = round(
                (float) $data['actual_weight_kg'],
                3
            );

            $dimensions = [
                'length_cm' => round(
                    (float) data_get(
                        $data,
                        'parcel_dimensions.length_cm'
                    ),
                    2
                ),
                'width_cm' => round(
                    (float) data_get(
                        $data,
                        'parcel_dimensions.width_cm'
                    ),
                    2
                ),
                'height_cm' => round(
                    (float) data_get(
                        $data,
                        'parcel_dimensions.height_cm'
                    ),
                    2
                ),
            ];

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
                 * Payment fields are internal compatibility values only.
                 * They are not exposed on the public estimator form.
                 */
                'payment_type' => 'prepaid',
                'pod_amount' => 0.0,

                /*
                 * One public calculator request represents one physical
                 * packed parcel. Product details are not required.
                 */
                'packets' => [
                    [
                        'packet_reference' =>
                            'PUBLIC-PKT-001',

                        'product_id' => null,
                        'name' => 'Public website parcel',
                        'quantity' => 1,

                        'actual_weight_kg' =>
                            $actualWeightKg,

                        'unit_price' => 0.0,
                        'declared_value' => 0.0,

                        'parcel_type' =>
                            (string) $data['parcel_type'],

                        'length_cm' =>
                            $dimensions['length_cm'],

                        'width_cm' =>
                            $dimensions['width_cm'],

                        'height_cm' =>
                            $dimensions['height_cm'],
                    ],
                ],

                'packet_count' => 1,
                'parcel_weight' => $actualWeightKg,
                'parcel_value' => 0.0,
                'parcel_type' =>
                    (string) $data['parcel_type'],
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

            $calculatedActualWeightKg = round(
                (float) data_get(
                    $result,
                    'weight_summary.total_actual_weight_kg',
                    $actualWeightKg
                ),
                3
            );

            $volumetricWeightKg = round(
                (float) data_get(
                    $result,
                    'weight_summary.total_volumetric_weight_kg',
                    0
                ),
                3
            );

            if ($volumetricWeightKg <= 0) {
                throw ValidationException::withMessages([
                    'parcel_dimensions' => [
                        'The volumetric weight could not be calculated from the supplied dimensions.',
                    ],
                ]);
            }

            $chargeableWeightKg = round(
                (float) data_get(
                    $result,
                    'weight_summary.total_chargeable_weight_kg',
                    max(
                        $calculatedActualWeightKg,
                        $volumetricWeightKg
                    )
                ),
                3
            );

            $weightSource = (string) data_get(
                $result,
                'packets.0.weight_source',
                $volumetricWeightKg > $calculatedActualWeightKg
                    ? 'volumetric_weight'
                    : 'actual_weight'
            );

            $volumetricApplied = (bool) data_get(
                $result,
                'packets.0.volumetric_applied',
                $volumetricWeightKg > $calculatedActualWeightKg
            );

            $estimatedHours = data_get(
                $result,
                'estimated_hours'
            );

            $estimatedHours = $estimatedHours !== null
                ? (int) $estimatedHours
                : null;

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

                    'packet_count' => 1,
                    'parcel_type' =>
                        (string) $data['parcel_type'],

                    'weight_calculation_rule' =>
                        'higher_of_actual_or_volumetric',

                    'actual_weight_kg' =>
                        $calculatedActualWeightKg,

                    'volumetric_weight_kg' =>
                        $volumetricWeightKg,

                    'chargeable_weight_kg' =>
                        $chargeableWeightKg,

                    'final_weight_kg' =>
                        $chargeableWeightKg,

                    'weight_source' =>
                        $weightSource,

                    'volumetric_applied' =>
                        $volumetricApplied,

                    'volumetric_divisor' =>
                        (float) data_get(
                            $result,
                            'packets.0.volumetric_divisor',
                            5000
                        ),

                    'dimensions' => $dimensions,

                    'estimated_delivery_label' =>
                        $this->formatEstimatedDeliveryLabel(
                            $estimatedHours
                        ),

                    'estimated_delivery_hours' =>
                        $estimatedHours,

                    'valid_until' =>
                        $this->serializeDate(
                            data_get($result, 'valid_until')
                        ),
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
                    'actual_weight_kg' =>
                        $data['actual_weight_kg'] ?? null,
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
