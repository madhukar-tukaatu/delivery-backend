<?php

namespace Modules\Rate\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Modules\Rate\Http\Requests\StorePublicPricingQuoteRequest;
use Modules\Rate\Services\PricingEngineService;

final class AdminPricingTestController extends Controller
{
    public function calculate(
        StorePublicPricingQuoteRequest $request,
        PricingEngineService $pricingEngine
    ): JsonResponse {
        $result = $pricingEngine->calculate(
            $request->validated(),
            null
        );

        return response()->json([
            'success' => true,
            'message' => 'Pricing simulation completed successfully.',
            'data' => $this->serializeDates($result),
        ]);
    }

    private function serializeDates(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->serializeDates($item);
            }
        }

        return $value;
    }
}
