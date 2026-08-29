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
     * Request pickup from an external Store Manager.
     *
     * IMPORTANT:
     *
     * Creating a shipment does NOT create a pickup request.
     *
     * Shipment:
     *
     * POST /api/v1/gateway/shipments
     *
     * creates:
     *
     * awaiting_pickup
     *
     * Then the merchant explicitly requests collection:
     *
     * POST /api/v1/gateway/pickups
     *
     * At that point Tukaatu:
     *
     * 1. Validates the merchant.
     * 2. Validates the pickup location.
     * 3. Finds eligible awaiting_pickup shipments.
     * 4. Creates or reuses an appropriate pickup batch.
     * 5. Attaches the shipments to that pickup.
     */
    public function store(
        GatewayCreatePickupRequest $request
    ): JsonResponse {

        $merchantId = (int) $request
            ->attributes
            ->get('merchant_id');

        abort_unless(
            $merchantId > 0,
            401,
            'Invalid merchant authentication.'
        );

        try {

            $pickup = $this->pickupService->create(
                merchantId: $merchantId,
                data: $request->validated(),
            );

            return ApiResponse::success(
                $pickup,
                'Pickup request submitted successfully.',
                201
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
     * Get pickup request belonging to authenticated merchant.
     */
    public function show(
        Request $request,
        string $requestNumber
    ): JsonResponse {

        $merchantId = (int) $request
            ->attributes
            ->get('merchant_id');

        abort_unless(
            $merchantId > 0,
            401,
            'Invalid merchant authentication.'
        );

        $pickup = $this->pickupService->findForMerchant(
            merchantId: $merchantId,
            requestNumber: $requestNumber,
        );

        return ApiResponse::success(
            $pickup,
            'Pickup request retrieved successfully.'
        );
    }
}