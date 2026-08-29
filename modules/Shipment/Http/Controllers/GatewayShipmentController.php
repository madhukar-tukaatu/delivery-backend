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
     * Create shipment from an external Store Manager.
     *
     * IMPORTANT:
     *
     * Creating a shipment does NOT create a pickup request.
     *
     * Shipment lifecycle:
     *
     * Store Manager
     *      ↓
     * POST /gateway/shipments
     *      ↓
     * Shipment created
     *      ↓
     * awaiting_pickup
     *      ↓
     * Store Manager prepares parcel
     *      ↓
     * POST /gateway/pickups
     *      ↓
     * Pickup request created
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
            /*
            |--------------------------------------------------------------------------
            | Merchant order
            |--------------------------------------------------------------------------
            */

            'merchant_order_id' => [
                'required',
                'string',
                'max:100',
            ],

            /*
            |--------------------------------------------------------------------------
            | Pickup location
            |--------------------------------------------------------------------------
            */

            'pickup_location_id' => [
                'required',
                'integer',
                'min:1',
            ],

            /*
            |--------------------------------------------------------------------------
            | Receiver
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | Delivery
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | Service
            |--------------------------------------------------------------------------
            */

            'service_type' => [
                'required',
                'string',
                'max:50',
            ],

            /*
            |--------------------------------------------------------------------------
            | Packet
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | Products
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | Payment
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | Pickup mode
            |--------------------------------------------------------------------------
            */

            'self_drop' => [
                'nullable',
                'boolean',
            ],

            /*
            |--------------------------------------------------------------------------
            | Additional
            |--------------------------------------------------------------------------
            */

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

        /*
        |--------------------------------------------------------------------------
        | Normalize
        |--------------------------------------------------------------------------
        */

        $data['self_drop'] =
            $data['self_drop'] ?? false;

        $data['order_source'] =
            'store_manager';

        /*
        |--------------------------------------------------------------------------
        | Create shipment
        |--------------------------------------------------------------------------
        */

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
     * Retrieve shipment belonging to authenticated merchant.
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
            ->where(
                'merchant_id',
                $merchantId
            )
            ->where(
                'tracking_number',
                $trackingNumber
            )
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
     * Cancel shipment belonging to authenticated merchant.
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
            ->where(
                'merchant_id',
                $merchantId
            )
            ->where(
                'tracking_number',
                $trackingNumber
            )
            ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Cancellation rules
        |--------------------------------------------------------------------------
        |
        | Once the parcel has actually been collected, the Store Manager
        | cannot cancel it through the gateway.
        |
        */

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

        $updated =
            $this->shipmentService->updateStatus(
                shipment: $shipment,
                status: CourierStatus::CANCELLED,
                userId: null,
                note: 'Cancelled by Store Manager API.'
            );

        return ApiResponse::success(
            $updated,
            'Shipment cancelled successfully.'
        );
    }
}