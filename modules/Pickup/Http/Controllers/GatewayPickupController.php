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
     * Store pickup request from external Store Manager.
     */
    public function store(
        GatewayCreatePickupRequest $request
    ): JsonResponse {

        /*
        |--------------------------------------------------------------------------
        | Merchant authentication
        |--------------------------------------------------------------------------
        |
        | Your X-Tukaatu-Key / X-Tukaatu-Secret middleware should put
        | merchant_id into request attributes.
        |
        */

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
                'Pickup request created successfully.',
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
                    'Unable to create pickup request.',

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
            \Modules\Pickup\Models\PickupRequest::query()
                ->where(
                    'merchant_id',
                    $merchantId
                )
                ->where(
                    'request_number',
                    $requestNumber
                )
                ->with([
                    'pickupLocation',
                    'pickupBranch',
                    'pickupSubBranch',
                    'assignedStaff',
                    'shipments.shipment',
                ])
                ->firstOrFail();

        return ApiResponse::success(
            $pickup,
            'Pickup request retrieved successfully.'
        );
    }
}