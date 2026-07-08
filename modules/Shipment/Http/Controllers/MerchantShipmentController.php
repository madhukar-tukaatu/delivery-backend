<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\MerchantShipmentGateService;
use Modules\Shipment\Services\ShipmentService;

class MerchantShipmentController extends Controller
{
    public function index(Request $request)
    {
        $merchant = $request->user()->merchant;

        $query = Shipment::query()
            ->with(['originBranch', 'originSubBranch', 'destinationBranch', 'destinationSubBranch'])
            ->where('merchant_id', $merchant->id)
            ->latest();

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($x) use ($q) {
                $x->where('tracking_number', 'like', "%{$q}%")
                    ->orWhere('merchant_order_id', 'like', "%{$q}%")
                    ->orWhere('receiver_name', 'like', "%{$q}%")
                    ->orWhere('receiver_phone', 'like', "%{$q}%");
            });
        }

        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function store(Request $request, ShipmentService $shipmentService, MerchantShipmentGateService $gate)
    {
        $merchant = $request->user()->merchant;
        $gate->ensureCanCreateShipment($merchant);

        $data = $request->validate([
            'merchant_order_id' => ['required', 'string', 'max:120'],
            'pickup_location_id' => ['nullable', 'exists:merchant_pickup_locations,id'],
            'pickup_name' => ['nullable', 'string', 'max:150'],
            'pickup_phone' => ['nullable', 'string', 'max:30'],
            'pickup_address' => ['nullable', 'string', 'max:500'],
            'pickup_city' => ['nullable', 'string', 'max:120'],
            'pickup_area' => ['nullable', 'string', 'max:120'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],

            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:150'],
            'customer_address' => ['required', 'string', 'max:500'],
            'customer_city' => ['required', 'string', 'max:120'],
            'customer_area' => ['nullable', 'string', 'max:120'],
            'delivery_lat' => ['required', 'numeric', 'between:-90,90'],
            'delivery_lng' => ['required', 'numeric', 'between:-180,180'],

            'parcel_type' => ['nullable', 'string', 'max:50'],
            'product_description' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'weight' => ['required', 'numeric', 'min:0.1'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'fragile' => ['nullable', 'boolean'],

            'payment_type' => ['required', 'in:cod,prepaid'],
            'cod_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_charge_paid_by' => ['nullable', 'in:customer,merchant'],
        ]);

        if (Shipment::where('merchant_id', $merchant->id)->where('merchant_order_id', $data['merchant_order_id'])->exists()) {
            return ApiResponse::error('A shipment already exists for this merchant order ID.', 422);
        }

        $data = $gate->enrichShipmentPayload($merchant, $data);

        $shipment = $shipmentService->create(
            $data,
            $request->user()->id,
            $merchant->id,
            'merchant_dashboard'
        );

        return ApiResponse::success($shipment, 'Shipment created.', 201);
    }

    public function show(Request $request, Shipment $shipment)
    {
        abort_unless($shipment->merchant_id === $request->user()->merchant_id, 403);

        return ApiResponse::success($shipment->load([
            'merchant',
            'trackingEvents',
            'originBranch',
            'originSubBranch',
            'destinationBranch',
            'destinationSubBranch',
            'currentBranch',
            'currentSubBranch',
            'routeSteps.fromBranch',
            'routeSteps.toBranch',
        ]));
    }
}
