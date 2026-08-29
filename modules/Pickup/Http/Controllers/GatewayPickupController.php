<?php

declare(strict_types=1);

namespace Modules\Pickup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Pickup\Http\Requests\GatewayCreatePickupRequest;
use Modules\Pickup\Services\GatewayPickupService;
use Throwable;

final class GatewayPickupController extends Controller
{
    public function __construct(
        private readonly GatewayPickupService $pickupService,
    ) {
    }

    /**
     * Request pickup from external Store Manager.
     *
     * The Store Manager does NOT send:
     *
     * - pickup_request_number
     * - shipment_tracking_numbers
     * - container_id
     *
     * Tukaatu already knows which open pickup container belongs
     * to this merchant + pickup location.
     */
    public function store(
        GatewayCreatePickupRequest $request
    ): JsonResponse {

        $merchantId =
            (int) $request
                ->attributes
                ->get('merchant_id');

        abort_unless(
            $merchantId > 0,
            401,
            'Invalid merchant authentication.'
        );

        try {

            $pickup =
                $this->pickupService->create(
                    merchantId: $merchantId,
                    data: $request->validated(),
                );

            return ApiResponse::success(
                $pickup,
                'Pickup request submitted successfully.',
                200
            );

        } catch (ValidationException $e) {

            return response()->json([
                'success' => false,

                'message' =>
                    'Pickup request validation failed.',

                'errors' =>
                    $e->errors(),

            ], 422);

        } catch (Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,

                'message' =>
                    'Unable to request pickup.',

                'errors' => [
                    'exception' =>
                        $e->getMessage(),
                ],

            ], 422);
        }
    }

    /**
     * Get pickup request.
     */
    public function show(
        Request $request,
        string $requestNumber
    ): JsonResponse {

        $merchantId =
            (int) $request
                ->attributes
                ->get('merchant_id');

        abort_unless(
            $merchantId > 0,
            401,
            'Invalid merchant authentication.'
        );

        $pickup =
            $this->pickupService->findForMerchant(
                merchantId: $merchantId,
                requestNumber: $requestNumber,
            );

        return ApiResponse::success(
            $pickup,
            'Pickup request retrieved successfully.'
        );
    }
}