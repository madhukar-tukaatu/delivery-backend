<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Rate\Services\RateCalculatorService;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\MerchantShipmentGateService;
use Modules\Shipment\Services\ShipmentService;

class MerchantShipmentController extends Controller
{
    public function index(Request $request)
    {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return ApiResponse::error('Merchant account not found.', 404);
        }

        $query = Shipment::query()
            ->with([
                'originBranch',
                'originSubBranch',
                'destinationBranch',
                'destinationSubBranch',
            ])
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

        return ApiResponse::success(
            $query->paginate((int) $request->get('per_page', 20))
        );
    }

    public function quote(
        Request $request,
        MerchantShipmentGateService $gate,
        RateCalculatorService $rateCalculator
    ) {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return ApiResponse::error('Merchant account not found.', 404);
        }

        $gate->ensureCanCreateShipment($merchant);

        $data = $this->validatedPayload($request);

        $data = $gate->enrichShipmentPayload($merchant, $data);

        $rate = $rateCalculator->calculate($data, $merchant->id);

        $deliveryCharge = (float) ($rate['delivery_charge'] ?? 0);
        $codCharge = (float) ($rate['cod_charge'] ?? 0);

        $paymentType = $data['payment_type'] ?? 'prepaid';
        $codAmount = (float) ($data['cod_amount'] ?? 0);
        $paidBy = $data['delivery_charge_paid_by'] ?? 'customer';

        $totalCollectable = $paymentType === 'cod'
            ? $codAmount + ($paidBy === 'customer' ? $deliveryCharge : 0)
            : ($paidBy === 'customer' ? $deliveryCharge : 0);

        return ApiResponse::success([
            'merchant_order_id' => $data['merchant_order_id'],
            'service_type' => [
                'code' => $data['service_type'] ?? 'standard',
                'name' => $this->serviceTypeLabel($data['service_type'] ?? 'standard'),
            ],
            'delivery_charge' => $deliveryCharge,
            'cod_charge' => $codCharge,
            'final_delivery_fee' => $deliveryCharge + $codCharge,
            'cod_amount' => $codAmount,
            'total_collectable_amount' => $totalCollectable,
            'payment_type' => $paymentType,
            'delivery_charge_paid_by' => $paidBy,
            'breakdown' => $rate,
        ], 'Fare calculated successfully.');
    }

    public function store(
        Request $request,
        ShipmentService $shipmentService,
        MerchantShipmentGateService $gate
    ) {
        $merchant = $request->user()->merchant;

        if (!$merchant) {
            return ApiResponse::error('Merchant account not found.', 404);
        }

        $gate->ensureCanCreateShipment($merchant);

        $data = $this->validatedPayload($request);

        $exists = Shipment::query()
            ->where('merchant_id', $merchant->id)
            ->where('merchant_order_id', $data['merchant_order_id'])
            ->exists();

        if ($exists) {
            return ApiResponse::error(
                'A shipment already exists for this merchant order ID.',
                422
            );
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
        $merchant = $request->user()->merchant;

        abort_unless($merchant && (int) $shipment->merchant_id === (int) $merchant->id, 403);

        return ApiResponse::success($shipment->load([
            'merchant',
            'items',
            'trackingEvents',
            'originBranch',
            'originSubBranch',
            'destinationBranch',
            'destinationSubBranch',
            'currentBranch',
            'currentSubBranch',
            'routeSteps.fromBranch',
            'routeSteps.toBranch',
            'pickupRequest',
        ]));
    }

    private function validatedPayload(Request $request): array
    {
        $this->mergeFrontendAliases($request);

        return $request->validate([
            'merchant_order_id' => ['required', 'string', 'max:120'],
            'order_source' => ['nullable', 'string', 'max:60'],

            'pickup_location_id' => [
                'required',
                'integer',
                'exists:merchant_pickup_locations,id',
            ],

            'pickup_name' => ['nullable', 'string', 'max:150'],
            'pickup_phone' => ['nullable', 'string', 'max:30'],
            'pickup_address' => ['nullable', 'string', 'max:500'],
            'pickup_city' => ['nullable', 'string', 'max:120'],
            'pickup_area' => ['nullable', 'string', 'max:120'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],

            'sender_name' => ['nullable', 'string', 'max:150'],
            'sender_phone' => ['nullable', 'string', 'max:30'],

            'customer_name' => ['required', 'string', 'max:150'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:150'],
            'customer_address' => ['required', 'string', 'max:500'],
            'customer_city' => ['required', 'string', 'max:120'],
            'customer_area' => ['nullable', 'string', 'max:120'],

            'delivery_lat' => ['required', 'numeric', 'between:-90,90'],
            'delivery_lng' => ['required', 'numeric', 'between:-180,180'],

            'service_type' => ['required', 'string', 'in:standard,express,same_day'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.value' => ['nullable', 'numeric', 'min:0'],

            'parcel_type' => ['nullable', 'string', 'max:50'],
            'product_description' => ['nullable', 'string', 'max:1000'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'weight' => ['required', 'numeric', 'min:0.1'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'fragile' => ['nullable', 'boolean'],

            'payment_type' => ['required', 'in:cod,prepaid'],
            'cod_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_charge_paid_by' => ['nullable', 'in:customer,merchant'],

            'self_drop' => ['nullable', 'boolean'],
            'special_instructions' => ['nullable', 'string', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function mergeFrontendAliases(Request $request): void
    {
        $items = is_array($request->input('items'))
            ? $request->input('items')
            : [];

        $totalQuantity = collect($items)->sum(function ($item) {
            return (int) ($item['quantity'] ?? 1);
        });

        $itemsValue = collect($items)->sum(function ($item) {
            $quantity = (int) ($item['quantity'] ?? 1);
            $value = (float) ($item['value'] ?? 0);

            return $quantity * $value;
        });

        $itemNames = collect($items)
            ->pluck('name')
            ->filter()
            ->values()
            ->all();

        $productDescription = $request->input('product_description')
            ?: $request->input('package_description')
            ?: implode(', ', $itemNames);

        $request->merge([
            'customer_name' => $request->input('customer_name')
                ?: $request->input('receiver_name'),

            'customer_phone' => $request->input('customer_phone')
                ?: $request->input('receiver_phone'),

            'customer_email' => $request->input('customer_email')
                ?: $request->input('receiver_email'),

            'customer_address' => $request->input('customer_address')
                ?: $request->input('delivery_address'),

            'customer_city' => $request->input('customer_city')
                ?: $request->input('delivery_city'),

            'customer_area' => $request->input('customer_area')
                ?: $request->input('delivery_area'),

            'delivery_lat' => $request->input('delivery_lat')
                ?: $request->input('delivery_latitude'),

            'delivery_lng' => $request->input('delivery_lng')
                ?: $request->input('delivery_longitude'),

            'weight' => $request->input('weight')
                ?: $request->input('package_weight')
                ?: $request->input('parcel_weight'),

            'declared_value' => $request->input('declared_value')
                ?: $request->input('package_value')
                ?: $request->input('parcel_value')
                ?: $itemsValue,

            'product_description' => $productDescription,

            'quantity' => $request->input('quantity') ?: max($totalQuantity, 1),

            'parcel_type' => $request->input('parcel_type') ?: 'product',

            'delivery_charge_paid_by' => $request->input('delivery_charge_paid_by')
                ?: 'customer',

            'remarks' => $request->input('remarks')
                ?: $request->input('special_instructions'),
        ]);
    }

    private function serviceTypeLabel(string $serviceType): string
    {
        return match ($serviceType) {
            'express' => 'Express Delivery',
            'same_day' => 'Same Day Delivery',
            default => 'Standard Delivery',
        };
    }
}