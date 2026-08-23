<?php
namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\CourierStatus;
use Illuminate\Http\Request;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\ShipmentService;

class GatewayShipmentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        /*
        |--------------------------------------------------------------------------
        | Merchant comes ONLY from authenticated API key
        |--------------------------------------------------------------------------
        */

        $merchantId = (int) $request->attributes->get(
            'merchant_id'
        );

        abort_unless($merchantId > 0, 401);

        $data = $request->validate([
            'merchant_order_id'       => [
                'required',
                'string',
                'max:100',
            ],

            'order_source'            => [
                'nullable',
                'string',
                'max:50',
            ],

            'pickup_location_id'      => [
                'nullable',
                'integer',
            ],

            'customer_name'           => [
                'required',
                'string',
                'max:150',
            ],

            'customer_phone'          => [
                'required',
                'string',
                'max:30',
            ],

            'customer_email'          => [
                'nullable',
                'email',
                'max:150',
            ],

            'customer_address'        => [
                'required',
                'string',
                'max:500',
            ],

            'customer_city'           => [
                'nullable',
                'string',
                'max:100',
            ],

            'customer_area'           => [
                'nullable',
                'string',
                'max:100',
            ],

            'delivery_lat'            => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'delivery_lng'            => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            'service_type'            => [
                'required',
                'string',
                'max:50',
            ],

            'items'                   => [
                'required',
                'array',
                'min:1',
            ],

            'items.*.name'            => [
                'required',
                'string',
                'max:255',
            ],

            'items.*.quantity'        => [
                'required',
                'integer',
                'min:1',
            ],

            'items.*.value'           => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'parcel_type'             => [
                'required',
                'string',
                'max:50',
            ],

            'product_description'     => [
                'nullable',
                'string',
                'max:1000',
            ],

            'quantity'                => [
                'required',
                'integer',
                'min:1',
            ],

            'weight'                  => [
                'required',
                'numeric',
                'min:0.01',
            ],

            'declared_value'          => [
                'required',
                'numeric',
                'min:0',
            ],

            'fragile'                 => [
                'boolean',
            ],

            'payment_type'            => [
                'required',
                'string',
                'max:50',
            ],

            'delivery_charge_paid_by' => [
                'required',
                'string',
                'max:50',
            ],

            'self_drop'               => [
                'boolean',
            ],

            'special_instructions'    => [
                'nullable',
                'string',
                'max:1000',
            ],

            'remarks'                 => [
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
        | External integration context
        |--------------------------------------------------------------------------
        */

        $data['merchant_id'] = $merchantId;

        $data['order_source'] =
        $data['order_source'] ?? 'store_manager';

        /*
        |--------------------------------------------------------------------------
        | Create shipment
        |--------------------------------------------------------------------------
        */

        $shipment = $this->shipmentService->createFromGateway(
            $merchantId,
            $data,
        );

        return ApiResponse::success(
            $shipment,
            'Shipment created successfully.',
            201
        );
    }

    public function show(Request $request, string $trackingNumber)
    {
        $merchant = $request->attributes->get('merchant');
        $shipment = Shipment::where('merchant_id', $merchant->id)
            ->where('tracking_number', $trackingNumber)
            ->with(['trackingEvents', 'originBranch', 'originSubBranch', 'destinationBranch', 'destinationSubBranch', 'routeSteps.fromBranch', 'routeSteps.toBranch'])
            ->firstOrFail();
        return ApiResponse::success($shipment);
    }

    public function cancel(Request $request, string $trackingNumber, ShipmentService $service)
    {
        $merchant = $request->attributes->get('merchant');
        $shipment = Shipment::where('merchant_id', $merchant->id)->where('tracking_number', $trackingNumber)->firstOrFail();
        if (! in_array($shipment->status, [CourierStatus::BOOKED, CourierStatus::PICKUP_ASSIGNED], true)) {
            return ApiResponse::error('Shipment cannot be cancelled after pickup/dispatch.', 422);
        }
        return ApiResponse::success($service->updateStatus($shipment, CourierStatus::CANCELLED, null, 'Cancelled by gateway API.'));
    }
}
