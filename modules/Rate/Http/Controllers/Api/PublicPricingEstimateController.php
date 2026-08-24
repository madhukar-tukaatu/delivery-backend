<?php

declare (strict_types = 1);

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
            // ------------------------------------------------------------------
            // Weight & dimensions – either actual weight OR complete dimensions
            // (or both; engine takes the higher value)
            // ------------------------------------------------------------------
            $actualWeightKg = round((float) ($data['actual_weight_kg'] ?? 1.0), 3);

            $rawLength = data_get($data, 'parcel_dimensions.length_cm');
            $rawWidth  = data_get($data, 'parcel_dimensions.width_cm');
            $rawHeight = data_get($data, 'parcel_dimensions.height_cm');

            $lengthCm = ($rawLength !== null && $rawLength !== '')
                ? round((float) $rawLength, 2)
                : null;

            $widthCm = ($rawWidth !== null && $rawWidth !== '')
                ? round((float) $rawWidth, 2)
                : null;

            $heightCm = ($rawHeight !== null && $rawHeight !== '')
                ? round((float) $rawHeight, 2)
                : null;

            $dimensions = [
                'length_cm' => $lengthCm,
                'width_cm'  => $widthCm,
                'height_cm' => $heightCm,
            ];

            $hasAnyDimension       = $lengthCm !== null || $widthCm !== null || $heightCm !== null;
            $hasCompleteDimensions = $lengthCm !== null && $widthCm !== null && $heightCm !== null;

            // Reject partial dimensions
            if ($hasAnyDimension && ! $hasCompleteDimensions) {
                throw ValidationException::withMessages([
                    'parcel_dimensions' => [
                        'All three dimensions (length, width, height) are required when any dimension is supplied.',
                    ],
                ]);
            }

            // actual_weight_kg is always present — defaulted to 1.0 kg in the request
            // when the caller omits it. Dimensions are optional on top of that.

            // Engine requires actual_weight_kg > 0.
            // When only dimensions are supplied we pass a tiny placeholder;
            // the engine will override with volumetric weight.
            $engineActualWeight = ($actualWeightKg !== null && $actualWeightKg > 0)
                ? $actualWeightKg
                : 0.001;

            $pricingPayload = [
                'store_id'           => null,

                'pickup_address'     => (string) $data['pickup_address'],
                'pickup_latitude'    => (float) $data['pickup_latitude'],
                'pickup_longitude'   => (float) $data['pickup_longitude'],

                'delivery_address'   => (string) $data['delivery_address'],
                'delivery_latitude'  => (float) $data['delivery_latitude'],
                'delivery_longitude' => (float) $data['delivery_longitude'],

                'service_type'       => (string) $data['service_type'],

                /*
                 * Payment fields are internal compatibility values only.
                 * They are not exposed on the public estimator form.
                 */
                'payment_type'       => 'prepaid',
                'pod_amount'         => 0.0,

                /*
                 * One public calculator request represents one physical
                 * packed parcel. Product details are not required.
                 */
                'packets'            => [
                    [
                        'packet_reference' => 'PUBLIC-PKT-001',
                        'product_id'       => null,
                        'name'             => 'Public website parcel',
                        'quantity'         => 1,

                        'actual_weight_kg' => $engineActualWeight,

                        'unit_price'       => 0.0,
                        'declared_value'   => 0.0,

                        'parcel_type'      => (string) $data['parcel_type'],

                        'length_cm'        => $lengthCm,
                        'width_cm'         => $widthCm,
                        'height_cm'        => $heightCm,
                    ],
                ],

                'packet_count'       => 1,
                'parcel_weight'      => $engineActualWeight,
                'parcel_value'       => 0.0,
                'parcel_type'        => (string) $data['parcel_type'],
            ];

            $result = $pricingEngine->calculate(
                $pricingPayload,
                null
            );

            $finalPrice = data_get($result, 'final_price') ?? data_get($result, 'breakdown.final_price');

            if ($finalPrice === null || (float) $finalPrice < 0) {
                Log::warning(
                    'Public pricing returned no valid final price.',
                    [
                        'pricing_payload' => $pricingPayload,
                        'pricing_result'  => $result,
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
                    $actualWeightKg ?? 0
                ),
                3
            );

            $volumetricWeightKg = data_get(
                $result,
                'weight_summary.total_volumetric_weight_kg'
            );

            $volumetricWeightKg = $volumetricWeightKg !== null
                ? round((float) $volumetricWeightKg, 3)
                : 0.0;

            // NOTE: We no longer throw when volumetric weight is 0.
            // That is a valid outcome when only actual weight is supplied.

            $chargeableWeightKg = round(
                (float) data_get(
                    $result,
                    'weight_summary.total_chargeable_weight_kg',
                    max($calculatedActualWeightKg, $volumetricWeightKg)
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

            $estimatedHours = data_get($result, 'estimated_hours');
            $estimatedHours = $estimatedHours !== null
                ? (int) $estimatedHours
                : null;

            return response()->json([
                'success' => true,
                'message' => 'Delivery price estimated successfully.',
                'data'    => [
                    'currency'                 => (string) data_get($result, 'currency', 'NPR'),

                    'price'                    => round((float) $finalPrice, 2),
                    'delivery_charge'          => round((float) $finalPrice, 2),
                    'pickup_branch'            => [
                        'id'                               => (int) data_get($result, 'pickup_branch.id'),
                        'name'                             => (string) data_get($result, 'pickup_branch.name'),
                        'distance_from_pickup_location_km' => round(
                            (float) data_get($result, 'pickup_branch.distance_km', 0),
                            3
                        ),
                    ],

                    'delivery_branch'          => [
                        'id'                               => (int) data_get($result, 'delivery_branch.id'),
                        'name'                             => (string) data_get($result, 'delivery_branch.name'),
                        'distance_to_delivery_location_km' => round(
                            (float) data_get($result, 'delivery_branch.distance_km', 0),
                            3
                        ),
                    ],

                    'route'                    => [
                        'base_rate'         => round(
                            (float) data_get($result, 'route.base_rate', 0),
                            2
                        ),

                        'total_distance_km' => round(
                            (float) data_get($result, 'route.total_distance_km', 0),
                            3
                        ),
                    ],

                    'packet_count'             => 1,
                    'parcel_type'              => (string) $data['parcel_type'],

                    'weight_calculation_rule'  => 'higher_of_actual_or_volumetric',

                    'actual_weight_kg'         => $calculatedActualWeightKg,
                    'volumetric_weight_kg'     => $volumetricWeightKg,
                    'chargeable_weight_kg'     => $chargeableWeightKg,
                    'final_weight_kg'          => $chargeableWeightKg,

                    'weight_source'            => $weightSource,
                    'volumetric_applied'       => $volumetricApplied,

                    'volumetric_divisor'       => (float) data_get(
                        $result,
                        'packets.0.volumetric_divisor',
                        5000
                    ),

                    'dimensions'               => $dimensions,

                    'estimated_delivery_label' => $this->formatEstimatedDeliveryLabel(
                        $estimatedHours
                    ),
                    'estimated_delivery_hours' => $estimatedHours,

                    'valid_until'              => $this->serializeDate(
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
                    'pickup_latitude'    => $data['pickup_latitude'] ?? null,
                    'pickup_longitude'   => $data['pickup_longitude'] ?? null,
                    'delivery_latitude'  => $data['delivery_latitude'] ?? null,
                    'delivery_longitude' => $data['delivery_longitude'] ?? null,
                    'service_type'       => $data['service_type'] ?? null,
                    'actual_weight_kg'   => $data['actual_weight_kg'] ?? null,
                    'exception'          => $exception->getMessage(),
                ]
            );

            return response()->json([
                'success' => false,
                'message' => 'The delivery price could not be calculated.',
            ], 500);
        }
    }

    private function formatEstimatedDeliveryLabel(?int $estimatedHours): ?string
    {
        if ($estimatedHours === null || $estimatedHours <= 0) {
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

        return sprintf('%.1f days', $estimatedHours / 24);
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

        return Carbon::parse((string) $value)->toIso8601String();
    }
}
