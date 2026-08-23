<?php

declare(strict_types=1);

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\CourierStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * Authentication:
     * X-Tukaatu-Api-Key
     * X-Tukaatu-Secret
     */
    public function store(Request $request): JsonResponse
    {
        /*
        |--------------------------------------------------------------------------
        | Merchant comes ONLY from authenticated API credentials
        |--------------------------------------------------------------------------
        */

        $merchantId = (int) $request->attributes->get('merchant_id');

        abort_unless($merchantId > 0, 401, 'Invalid merchant authentication.');

        /*
        |--------------------------------------------------------------------------
        | Validate external shipment payload
        |--------------------------------------------------------------------------
        */

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
            | Pickup
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
            | Single packet
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
            | Additional information
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
        | Force gateway source
        |--------------------------------------------------------------------------
        |
        | The external store must not decide the internal order source.
        |
        */

        $data['order_source'] = 'store_manager';

        /*
        |--------------------------------------------------------------------------
        | Never trust merchant_id from request
        |--------------------------------------------------------------------------
        */

        unset($data['merchant_id']);

        $data['merchant_id'] = $merchantId;

        /*
        |--------------------------------------------------------------------------
        | Create shipment
        |--------------------------------------------------------------------------
        */

        try {
            $shipment = $this->shipmentService->createFromGateway(
                merchantId: $merchantId,
                data: $data,
            );

            return ApiResponse::success(
                $shipment,
                'Shipment created successfully.',
                201
            );
        } catch (Throwable $e) {
            report($e);

            return ApiResponse::error(
                'Unable to create shipment.',
                422
            );
        }
    }

    /**
     * Get shipment by tracking number.
     */
    public function show(
        Request $request,
        string $trackingNumber
    ): JsonResponse {
        $merchantId = (int) $request->attributes->get('merchant_id');

        abort_unless($merchantId > 0, 401);

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

        return ApiResponse::success($shipment);
    }

    /**
     * Cancel shipment.
     */
    public function cancel(
        Request $request,
        string $trackingNumber
    ): JsonResponse {
        $merchantId = (int) $request->attributes->get('merchant_id');

        abort_unless($merchantId > 0, 401);

        $shipment = Shipment::query()
            ->where('merchant_id', $merchantId)
            ->where('tracking_number', $trackingNumber)
            ->firstOrFail();

        if (! in_array(
            $shipment->status,
            [
                CourierStatus::BOOKED,
                CourierStatus::AWAITING_PICKUP,
                CourierStatus::PICKUP_ASSIGNED,
            ],
            true
        )) {
            return ApiResponse::error(
                'Shipment cannot be cancelled after pickup or dispatch.',
                422
            );
        }

        $updated = $this->shipmentService->updateStatus(
            $shipment,
            CourierStatus::CANCELLED,
            null,
            'Cancelled by Store Manager API.'
        );

        return ApiResponse::success(
            $updated,
            'Shipment cancelled successfully.'
        );
    }
}