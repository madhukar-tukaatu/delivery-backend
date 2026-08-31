<?php

declare(strict_types=1);

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\CourierStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\ShipmentService;
use Throwable;

final class GatewayShipmentController extends Controller
{
    public function __construct(
        private readonly ShipmentService $shipmentService,
    ) {
    }

    /**
     * Create shipment from external Store Manager.
     *
     * POST /api/v1/gateway/shipments
     */
    public function store(Request $request): JsonResponse
    {
        $merchantId = (int) $request
            ->attributes
            ->get('merchant_id');

        abort_unless(
            $merchantId > 0,
            401,
            'Invalid merchant authentication.'
        );

        $data = $request->validate([
            'merchant_order_id' => [
                'required',
                'string',
                'max:100',
            ],

            'pickup_location_id' => [
                'required',
                'integer',
                'min:1',
            ],

            'receiver_name' => [
                'required',
                'string',
                'max:150',
            ],

            'receiver_phone' => [
                'required',
                'string',
                'max:30',
            ],

            'receiver_email' => [
                'nullable',
                'email',
                'max:150',
            ],

            'delivery_address' => [
                'required',
                'string',
                'max:500',
            ],

            'delivery_city' => [
                'nullable',
                'string',
                'max:100',
            ],

            'delivery_area' => [
                'nullable',
                'string',
                'max:100',
            ],

            'delivery_lat' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'delivery_lng' => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            'service_type' => [
                'required',
                'string',
                'max:50',
            ],

            'packet' => [
                'required',
                'array',
            ],

            'packet.description' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'packet.quantity' => [
                'required',
                'integer',
                'min:1',
            ],

            'packet.weight' => [
                'required',
                'numeric',
                'min:0.01',
            ],

            'packet.declared_value' => [
                'required',
                'numeric',
                'min:0',
            ],

            'packet.parcel_type' => [
                'required',
                'string',
                'max:50',
            ],

            'packet.fragile' => [
                'required',
                'boolean',
            ],

            'packet.products' => [
                'nullable',
                'array',
                'max:100',
            ],

            'packet.products.*.product_id' => [
                'nullable',
                'string',
                'max:100',
            ],

            'packet.products.*.name' => [
                'required',
                'string',
                'max:255',
            ],

            'packet.products.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],

            'packet.products.*.unit_price' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'packet.products.*.unit_weight' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'packet.products.*.parcel_type' => [
                'nullable',
                'string',
                'max:50',
            ],

            'payment_type' => [
                'required',
                'string',
                'max:50',
            ],

            'delivery_charge_paid_by' => [
                'required',
                'string',
                'max:50',
            ],

            'self_drop' => [
                'nullable',
                'boolean',
            ],

            'special_instructions' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $data['pickup_location_id'] =
            (int) $data['pickup_location_id'];

        $data['delivery_lat'] =
            (float) $data['delivery_lat'];

        $data['delivery_lng'] =
            (float) $data['delivery_lng'];

        $data['self_drop'] =
            (bool) ($data['self_drop'] ?? false);

        $data['order_source'] =
            'store_manager';

        try {
            $shipment =
                $this->shipmentService->createFromGateway(
                    merchantId: $merchantId,
                    data: $data,
                );

            return ApiResponse::success(
                $shipment,
                'Shipment created successfully.',
                201
            );

        } catch (ValidationException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Shipment validation failed.',
                'errors' => $e->errors(),
            ], 422);

        } catch (Throwable $e) {

            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create shipment.',
                'errors' => [
                    'exception' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Retrieve shipment.
     *
     * GET /api/v1/gateway/shipments/{trackingNumber}
     */
    public function show(
        Request $request,
        string $trackingNumber
    ): JsonResponse {
        $merchantId = (int) $request
            ->attributes
            ->get('merchant_id');

        abort_unless(
            $merchantId > 0,
            401,
            'Invalid merchant authentication.'
        );

        $shipment = Shipment::query()
            ->where('merchant_id', $merchantId)
            ->where('tracking_number', $trackingNumber)
            ->with([
                'trackingEvents',
                'originBranch',
                'originSubBranch',
                'destinationBranch',
                'destinationSubBranch',
                'routeSteps.fromBranch',
                'routeSteps.toBranch',
            ])
            ->firstOrFail();

        return ApiResponse::success(
            $shipment,
            'Shipment retrieved successfully.'
        );
    }

    /**
     * Cancel shipment.
     *
     * POST /api/v1/gateway/shipments/{trackingNumber}/cancel
     */
    public function cancel(
        Request $request,
        string $trackingNumber
    ): JsonResponse {
        $merchantId = (int) $request
            ->attributes
            ->get('merchant_id');

        abort_unless(
            $merchantId > 0,
            401,
            'Invalid merchant authentication.'
        );

        $shipment = Shipment::query()
            ->where('merchant_id', $merchantId)
            ->where('tracking_number', $trackingNumber)
            ->firstOrFail();

        $cancellableStatuses = [
            CourierStatus::BOOKED,
            CourierStatus::AWAITING_PICKUP,
            CourierStatus::PICKUP_ASSIGNED,
        ];

        if (! in_array(
            $shipment->status,
            $cancellableStatuses,
            true
        )) {
            return ApiResponse::error(
                'Shipment cannot be cancelled after pickup or dispatch.',
                422
            );
        }

        try {
            $updated =
                $this->shipmentService->cancelFromGateway(
                    shipment: $shipment,
                );

            return ApiResponse::success(
                $updated,
                'Shipment cancelled successfully.'
            );

        } catch (ValidationException $e) {

            return response()->json([
                'success' => false,
                'message' => 'Shipment cancellation failed.',
                'errors' => $e->errors(),
            ], 422);
        }
    }
}