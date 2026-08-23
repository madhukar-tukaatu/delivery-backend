<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\CourierStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\ShipmentService;

class GatewayShipmentController extends Controller
{
    public function __construct(
        private readonly ShipmentService $shipmentService
    ) {
    }


    /*
    |--------------------------------------------------------------------------
    | Create Shipment
    |--------------------------------------------------------------------------
    */

    public function store(
        Request $request
    ): JsonResponse {

        /*
        |--------------------------------------------------------------------------
        | Merchant comes ONLY from API key middleware
        |--------------------------------------------------------------------------
        */

        $merchantId =
            (int) $request
                ->attributes
                ->get('merchant_id');


        if ($merchantId <= 0) {

            return ApiResponse::error(
                'Merchant authentication failed.',
                401
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Validate request
        |--------------------------------------------------------------------------
        */

        $data = $request->validate([

            'merchant_order_id' => [
                'required',
                'string',
                'max:100',
            ],

            'order_source' => [
                'nullable',
                'string',
                'max:50',
            ],

            'pickup_location_id' => [
                'nullable',
                'integer',
            ],

            /*
            |--------------------------------------------------------------------------
            | Customer
            |--------------------------------------------------------------------------
            */

            'customer_name' => [
                'required',
                'string',
                'max:150',
            ],

            'customer_phone' => [
                'required',
                'string',
                'max:30',
            ],

            'customer_email' => [
                'nullable',
                'email',
                'max:150',
            ],

            'customer_address' => [
                'required',
                'string',
                'max:500',
            ],

            'customer_city' => [
                'nullable',
                'string',
                'max:100',
            ],

            'customer_area' => [
                'nullable',
                'string',
                'max:100',
            ],

            /*
            |--------------------------------------------------------------------------
            | Destination coordinates
            |--------------------------------------------------------------------------
            */

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
            | Shipment
            |--------------------------------------------------------------------------
            */

            'service_type' => [
                'required',
                'string',
                'max:50',
            ],

            'items' => [
                'required',
                'array',
                'min:1',
            ],

            'items.*.name' => [
                'required',
                'string',
                'max:255',
            ],

            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],

            'items.*.value' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'parcel_type' => [
                'required',
                'string',
                'max:50',
            ],

            'product_description' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'quantity' => [
                'required',
                'integer',
                'min:1',
            ],

            'weight' => [
                'required',
                'numeric',
                'min:0.01',
            ],

            'declared_value' => [
                'required',
                'numeric',
                'min:0',
            ],

            'fragile' => [
                'sometimes',
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

            'self_drop' => [
                'sometimes',
                'boolean',
            ],

            /*
            |--------------------------------------------------------------------------
            | Notes
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
        | Never trust merchant_id from request body
        |--------------------------------------------------------------------------
        */

        unset($data['merchant_id']);


        /*
        |--------------------------------------------------------------------------
        | Attach authenticated merchant
        |--------------------------------------------------------------------------
        */

        $data['merchant_id'] =
            $merchantId;


        /*
        |--------------------------------------------------------------------------
        | External source
        |--------------------------------------------------------------------------
        */

        $data['order_source'] =
            $data['order_source']
            ?? 'store_manager';


        /*
        |--------------------------------------------------------------------------
        | Create shipment
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | No pricing calculation here.
        |
        */

        $shipment =
            $this->shipmentService
                ->createFromGateway(
                    $merchantId,
                    $data
                );


        /*
        |--------------------------------------------------------------------------
        | Response
        |--------------------------------------------------------------------------
        */

        return ApiResponse::success(
            $shipment,
            'Shipment created successfully.',
            201
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Show Shipment
    |--------------------------------------------------------------------------
    */

    public function show(
        Request $request,
        string $trackingNumber
    ): JsonResponse {

        $merchantId =
            (int) $request
                ->attributes
                ->get('merchant_id');


        if ($merchantId <= 0) {

            return ApiResponse::error(
                'Merchant authentication failed.',
                401
            );
        }


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
            ->first();


        if (!$shipment) {

            return ApiResponse::error(
                'Shipment not found.',
                404
            );
        }


        return ApiResponse::success(
            $shipment
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Cancel Shipment
    |--------------------------------------------------------------------------
    */

    public function cancel(
        Request $request,
        string $trackingNumber
    ): JsonResponse {

        $merchantId =
            (int) $request
                ->attributes
                ->get('merchant_id');


        if ($merchantId <= 0) {

            return ApiResponse::error(
                'Merchant authentication failed.',
                401
            );
        }


        $shipment = Shipment::query()
            ->where(
                'merchant_id',
                $merchantId
            )
            ->where(
                'tracking_number',
                $trackingNumber
            )
            ->first();


        if (!$shipment) {

            return ApiResponse::error(
                'Shipment not found.',
                404
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Cancellation window
        |--------------------------------------------------------------------------
        */

        $allowedStatuses = [
            'awaiting_pickup',
            'pickup_pending',
            'pickup_assigned',
            CourierStatus::BOOKED,
        ];


        if (!in_array(
            $shipment->status,
            $allowedStatuses,
            true
        )) {

            return ApiResponse::error(
                'Shipment cannot be cancelled after pickup or dispatch.',
                422
            );
        }


        $shipment =
            $this->shipmentService
                ->updateStatus(
                    $shipment,
                    CourierStatus::CANCELLED,
                    null,
                    'Cancelled by external store API.'
                );


        return ApiResponse::success(
            $shipment,
            'Shipment cancelled successfully.'
        );
    }
}