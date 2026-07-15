<?php

namespace Modules\Shipment\Services;

use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Modules\COD\Models\CodRecord;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Pickup\Services\PickupWorkflowService;
use Modules\Rate\Services\RateCalculatorService;
use Modules\Routing\Services\ShipmentRoutingService;
use Modules\Shipment\Models\Shipment;
use Modules\Tracking\Services\TrackingService;
use Modules\Webhook\Services\WebhookService;

class ShipmentService
{
    public function __construct(
        private ShipmentNumberService $numberService,
        private RateCalculatorService $rateCalculator,
        private TrackingService $trackingService,
        private WebhookService $webhookService,
        private ShipmentRoutingService $shipmentRoutingService,
        private PickupWorkflowService $pickupWorkflowService,
    ) {}

    public function create(array $data, ?int $userId = null, ?int $merchantId = null, string $source = 'manual'): Shipment
    {
        return DB::transaction(function () use ($data, $userId, $merchantId, $source) {
            $merchantId = $merchantId ?: ($data['merchant_id'] ?? null);
            $merchant = $merchantId ? Merchant::find($merchantId) : null;

            if ($merchant && $source !== 'manual' && $merchant->status !== 'active') {
                abort(403, 'Merchant account is not active.');
            }

            $data = $this->applyPickupDefaults($data, $merchant);

            $codAmount = (float) ($data['cod_amount'] ?? 0);
            $paymentType = $data['payment_type'] ?? ($codAmount > 0 ? 'cod' : 'prepaid');
            $paidBy = $data['delivery_charge_paid_by'] ?? 'customer';
            $useAutoRouting = $this->shouldUseAutoRouting($data);

            $rate = $useAutoRouting
                ? ['delivery_charge' => 0, 'cod_charge' => 0]
                : $this->rateCalculator->calculate($data, $merchantId);

            $deliveryCharge = (float) ($data['delivery_charge'] ?? $rate['delivery_charge'] ?? 0);
            $codCharge = (float) ($data['cod_charge'] ?? $rate['cod_charge'] ?? 0);

            $shipment = Shipment::create([
                'tracking_number' => $this->numberService->generate(),
                'merchant_id' => $merchantId,
                'pickup_location_id' => $data['pickup_location_id'] ?? null,
                'merchant_order_id' => $data['merchant_order_id'] ?? null,
                'source' => $source,

                'origin_branch_id' => $useAutoRouting ? null : ($data['origin_branch_id'] ?? $data['pickup_branch_id'] ?? null),
                'origin_sub_branch_id' => $useAutoRouting ? null : ($data['origin_sub_branch_id'] ?? $data['pickup_sub_branch_id'] ?? null),
                'destination_branch_id' => $useAutoRouting ? null : ($data['destination_branch_id'] ?? null),
                'destination_sub_branch_id' => $useAutoRouting ? null : ($data['destination_sub_branch_id'] ?? null),
                'current_branch_id' => $useAutoRouting ? null : ($data['origin_branch_id'] ?? $data['pickup_branch_id'] ?? null),
                'current_sub_branch_id' => $useAutoRouting ? null : ($data['origin_sub_branch_id'] ?? $data['pickup_sub_branch_id'] ?? null),

                'pickup_lat' => $data['pickup_lat'] ?? null,
                'pickup_lng' => $data['pickup_lng'] ?? null,
                'delivery_lat' => $data['delivery_lat'] ?? null,
                'delivery_lng' => $data['delivery_lng'] ?? null,

                'created_by' => $userId,
                'sender_name' => $data['sender_name'] ?? $data['pickup_name'] ?? $merchant?->name,
                'sender_phone' => $data['sender_phone'] ?? $data['pickup_phone'] ?? $merchant?->phone,
                'sender_address' => $data['sender_address'] ?? $data['pickup_address'] ?? $merchant?->pickup_address ?? $merchant?->address,
                'sender_city' => $data['sender_city'] ?? $data['pickup_city'] ?? $merchant?->pickup_city,
                'sender_area' => $data['sender_area'] ?? $data['pickup_area'] ?? $merchant?->pickup_area,
                'receiver_name' => $data['receiver_name'] ?? $data['customer_name'],
                'receiver_phone' => $data['receiver_phone'] ?? $data['customer_phone'],
                'receiver_email' => $data['receiver_email'] ?? $data['customer_email'] ?? null,
                'receiver_address' => $data['receiver_address'] ?? $data['customer_address'],
                'receiver_city' => $data['receiver_city'] ?? $data['customer_city'] ?? null,
                'receiver_area' => $data['receiver_area'] ?? $data['customer_area'] ?? null,
                'parcel_type' => $data['parcel_type'] ?? 'product',
                'description' => $data['description'] ?? $data['product_description'] ?? null,
                'quantity' => (int) ($data['quantity'] ?? 1),
                'weight' => (float) ($data['weight'] ?? 1),
                'declared_value' => (float) ($data['declared_value'] ?? 0),
                'fragile' => (bool) ($data['fragile'] ?? false),
                'payment_type' => $paymentType,
                'cod_amount' => $codAmount,
                'delivery_charge' => $deliveryCharge,
                'cod_charge' => $codCharge,
                'total_collectable_amount' => 0,
                'delivery_charge_paid_by' => $paidBy,
                'status' => CourierStatus::BOOKED,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::BOOKED),
                'cod_status' => $codAmount > 0 ? 'pending' : 'not_required',
                'settlement_status' => $codAmount > 0 ? 'not_ready' : 'not_required',
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->persistShipmentExtras($shipment, $data);
            $this->persistShipmentItems($shipment, $data);

            if ($useAutoRouting) {
                $shipment = $this->shipmentRoutingService->applyToShipment($shipment, [
                    'pickup_lat' => $data['pickup_lat'],
                    'pickup_lng' => $data['pickup_lng'],
                    'delivery_lat' => $data['delivery_lat'],
                    'delivery_lng' => $data['delivery_lng'],
                    'weight' => $data['weight'] ?? 1,
                    'cod_amount' => $codAmount,
                ])->fresh();
            }

            $deliveryCharge = (float) ($shipment->delivery_charge ?? 0);
            $breakdown = is_array($shipment->delivery_charge_breakdown) ? $shipment->delivery_charge_breakdown : [];
            $codCharge = (float) ($shipment->cod_charge ?? ($breakdown['cod_fee'] ?? 0));

            $totalCollectable = $paymentType === 'cod'
                ? $codAmount + ($paidBy === 'customer' ? $deliveryCharge : 0)
                : ($paidBy === 'customer' ? $deliveryCharge : 0);

            $shipment->update([
                'delivery_charge' => $deliveryCharge,
                'cod_charge' => $codCharge,
                'total_collectable_amount' => $totalCollectable,
            ]);

            $this->trackingService->record($shipment->fresh(), CourierStatus::BOOKED, 'Shipment booked.', $userId);

            if ($codAmount > 0) {
                CodRecord::create([
                    'shipment_id' => $shipment->id,
                    'merchant_id' => $shipment->merchant_id,
                    'cod_amount' => $codAmount,
                    'delivery_charge' => $deliveryCharge,
                    'cod_charge' => $codCharge,
                    'status' => 'pending',
                ]);
            }

            $shipment = $shipment->fresh();
            $this->pickupWorkflowService->createForShipment($shipment);
            $this->webhookService->queueShipmentEvent($shipment, 'shipment.created');

            return $shipment->fresh([
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
            ]);
        });
    }

    private function applyPickupDefaults(array $data, ?Merchant $merchant): array
    {
        if (!empty($data['pickup_location_id'])) {
            $location = MerchantPickupLocation::query()
                ->where('id', $data['pickup_location_id'])
                ->when($merchant, fn($q) => $q->where('merchant_id', $merchant->id))
                ->firstOrFail();

            return array_merge($data, [
                'pickup_name' => $data['pickup_name'] ?? $location->name,
                'pickup_phone' => $data['pickup_phone'] ?? $location->phone,
                'pickup_address' => $data['pickup_address'] ?? $location->address,
                'pickup_city' => $data['pickup_city'] ?? $location->city,
                'pickup_area' => $data['pickup_area'] ?? $location->area,
                'pickup_lat' => $data['pickup_lat'] ?? $location->latitude,
                'pickup_lng' => $data['pickup_lng'] ?? $location->longitude,
                'origin_branch_id' => $data['origin_branch_id'] ?? $location->branch_id,
                'origin_sub_branch_id' => $data['origin_sub_branch_id'] ?? $location->sub_branch_id,
            ]);
        }

        if ($merchant && (empty($data['pickup_lat']) || empty($data['pickup_lng']))) {
            $defaultLocation = MerchantPickupLocation::where('merchant_id', $merchant->id)
                ->where('is_default', true)
                ->first();

            if ($defaultLocation) {
                return array_merge($data, [
                    'pickup_name' => $data['pickup_name'] ?? $defaultLocation->name,
                    'pickup_phone' => $data['pickup_phone'] ?? $defaultLocation->phone,
                    'pickup_address' => $data['pickup_address'] ?? $defaultLocation->address,
                    'pickup_city' => $data['pickup_city'] ?? $defaultLocation->city,
                    'pickup_area' => $data['pickup_area'] ?? $defaultLocation->area,
                    'pickup_lat' => $defaultLocation->latitude,
                    'pickup_lng' => $defaultLocation->longitude,
                    'origin_branch_id' => $data['origin_branch_id'] ?? $defaultLocation->branch_id,
                    'origin_sub_branch_id' => $data['origin_sub_branch_id'] ?? $defaultLocation->sub_branch_id,
                ]);
            }

            return array_merge($data, [
                'pickup_name' => $data['pickup_name'] ?? $merchant->name,
                'pickup_phone' => $data['pickup_phone'] ?? $merchant->phone,
                'pickup_address' => $data['pickup_address'] ?? $merchant->pickup_address ?? $merchant->address,
                'pickup_city' => $data['pickup_city'] ?? $merchant->pickup_city,
                'pickup_area' => $data['pickup_area'] ?? $merchant->pickup_area,
                'pickup_lat' => $data['pickup_lat'] ?? $merchant->pickup_lat,
                'pickup_lng' => $data['pickup_lng'] ?? $merchant->pickup_lng,
                'origin_branch_id' => $data['origin_branch_id'] ?? $merchant->default_branch_id,
                'origin_sub_branch_id' => $data['origin_sub_branch_id'] ?? $merchant->default_sub_branch_id,
            ]);
        }

        return $data;
    }

    private function shouldUseAutoRouting(array $data): bool
    {
        if (!empty($data['manual_branch_override'])) {
            return false;
        }

        return !empty($data['pickup_lat'])
            && !empty($data['pickup_lng'])
            && !empty($data['delivery_lat'])
            && !empty($data['delivery_lng']);
    }
}
