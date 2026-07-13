<?php

namespace Modules\Rate\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PricingEngineService
{
    public function calculate(array $data, ?int $merchantId = null): array
    {
        $service = $this->serviceType($data['service_type'] ?? 'standard');
        $this->checkSameDayCutoff($service);

        $pickupBranch = $this->nearestBranch((float)$data['pickup_latitude'], (float)$data['pickup_longitude']);
        $deliveryBranch = $this->nearestBranch((float)$data['delivery_latitude'], (float)$data['delivery_longitude']);

        $pickupRule = $this->branchPricingRule((int)$pickupBranch->id, (int)$service->id, 'Pickup');
        $deliveryRule = $this->branchPricingRule((int)$deliveryBranch->id, (int)$service->id, 'Delivery');

        $weight = (float)($data['parcel_weight'] ?? 0);
        $codAmount = (float)($data['cod_amount'] ?? 0);
        $paymentType = $data['payment_type'] ?? 'prepaid';

        $pickupDistance = $this->distanceKm((float)$pickupBranch->latitude, (float)$pickupBranch->longitude, (float)$data['pickup_latitude'], (float)$data['pickup_longitude']);
        $deliveryDistance = $this->distanceKm((float)$deliveryBranch->latitude, (float)$deliveryBranch->longitude, (float)$data['delivery_latitude'], (float)$data['delivery_longitude']);

        $this->validateDistance($pickupDistance, $pickupRule, 'pickup');
        $this->validateDistance($deliveryDistance, $deliveryRule, 'delivery');

        $pickupExtraKm = max(0, $pickupDistance - (float)($pickupRule->base_radius_km ?? 5));
        $deliveryExtraKm = max(0, $deliveryDistance - (float)($deliveryRule->base_radius_km ?? 5));
        $pickupExtraCharge = ceil($pickupExtraKm) * (float)($pickupRule->pickup_extra_per_km ?? 0);
        $deliveryExtraCharge = ceil($deliveryExtraKm) * (float)($deliveryRule->delivery_extra_per_km ?? 0);

        $transferFee = 0;
        $estimatedHours = (int)($service->estimated_max_hours ?? 48);
        if ((int)$pickupBranch->id !== (int)$deliveryBranch->id) {
            $lane = $this->transferLane((int)$pickupBranch->id, (int)$deliveryBranch->id, (int)$service->id);
            $transferFee = (float)$lane->base_transfer_fee + (ceil($weight) * (float)$lane->per_kg_fee);
            $estimatedHours = (int)($lane->estimated_hours ?? $estimatedHours);
        }

        $basePickupFee = (float)($pickupRule->base_pickup_fee ?? 0);
        $baseDeliveryFee = (float)($deliveryRule->base_delivery_fee ?? 0);
        $baseWeightKg = (float)($deliveryRule->base_weight_kg ?? 1);
        $extraWeightKg = max(0, $weight - $baseWeightKg);
        $weightCharge = ceil($extraWeightKg) * (float)($deliveryRule->extra_weight_per_kg ?? 0);

        $codFee = 0;
        if ($paymentType === 'cod') {
            $codFee = (float)($deliveryRule->cod_fee_fixed ?? 0) + ($codAmount * ((float)($deliveryRule->cod_fee_percentage ?? 0) / 100));
        }

        $before = $basePickupFee + $baseDeliveryFee + $transferFee + $pickupExtraCharge + $deliveryExtraCharge + $weightCharge + $codFee;
        $final = round(($before * (float)($service->price_multiplier ?? 1)) + (float)($service->fixed_addon_fee ?? 0), 2);
        $slaDueAt = now()->addHours(max((int)($service->estimated_max_hours ?? 48), $estimatedHours));

        return [
            'merchant_id' => $merchantId,
            'currency' => 'NPR',
            'service_type' => ['id'=>(int)$service->id,'code'=>$service->code,'name'=>$service->name],
            'pickup_branch' => ['id'=>(int)$pickupBranch->id,'name'=>$pickupBranch->name],
            'delivery_branch' => ['id'=>(int)$deliveryBranch->id,'name'=>$deliveryBranch->name],
            'estimated_hours' => $estimatedHours,
            'final_price' => $final,
            'sla_due_at' => $slaDueAt,
            'valid_until' => now()->addMinutes(30),
            'breakdown' => [
                'base_pickup_fee'=>round($basePickupFee,2),
                'base_delivery_fee'=>round($baseDeliveryFee,2),
                'base_transfer_fee'=>round($transferFee,2),
                'pickup_distance_km'=>round($pickupDistance,2),
                'pickup_base_radius_km'=>round((float)($pickupRule->base_radius_km ?? 5),2),
                'pickup_extra_km'=>round($pickupExtraKm,2),
                'pickup_billable_extra_km'=>(int)ceil($pickupExtraKm),
                'pickup_extra_charge'=>round($pickupExtraCharge,2),
                'delivery_distance_km'=>round($deliveryDistance,2),
                'delivery_base_radius_km'=>round((float)($deliveryRule->base_radius_km ?? 5),2),
                'delivery_extra_km'=>round($deliveryExtraKm,2),
                'delivery_billable_extra_km'=>(int)ceil($deliveryExtraKm),
                'delivery_extra_charge'=>round($deliveryExtraCharge,2),
                'parcel_weight'=>round($weight,2),
                'base_weight_kg'=>round($baseWeightKg,2),
                'extra_weight_kg'=>round($extraWeightKg,2),
                'billable_extra_weight_kg'=>(int)ceil($extraWeightKg),
                'weight_charge'=>round($weightCharge,2),
                'payment_type'=>$paymentType,
                'cod_amount'=>round($codAmount,2),
                'cod_fee'=>round($codFee,2),
                'discount'=>0,
                'price_before_service_adjustment'=>round($before,2),
                'service_multiplier'=>round((float)($service->price_multiplier ?? 1),2),
                'service_fixed_addon_fee'=>round((float)($service->fixed_addon_fee ?? 0),2),
                'final_price'=>$final,
            ],
        ];
    }

    private function serviceType(string $code): object
    {
        $service = DB::table('service_types')->where('code',$code)->where('is_active',true)->first();
        if (!$service) throw ValidationException::withMessages(['service_type'=>"Invalid or inactive service type: {$code}"]);
        return $service;
    }

    private function checkSameDayCutoff(object $service): void
    {
        if (($service->same_day_only ?? false) && !empty($service->pickup_cutoff_time) && now()->format('H:i:s') > $service->pickup_cutoff_time) {
            throw ValidationException::withMessages(['service_type'=>'Same day delivery cutoff time has passed for today.']);
        }
    }

    private function nearestBranch(float $lat, float $lng): object
    {
        $query = DB::table('branches')->whereNotNull('latitude')->whereNotNull('longitude');
        if (Schema::hasColumn('branches','is_active')) $query->where('is_active', true);
        $branches = $query->get();
        if ($branches->isEmpty()) throw ValidationException::withMessages(['branch'=>'No active branch found with latitude/longitude.']);
        return $branches->map(function($b) use ($lat,$lng) { $b->distance_km = $this->distanceKm((float)$b->latitude,(float)$b->longitude,$lat,$lng); return $b; })->sortBy('distance_km')->first();
    }

    private function branchPricingRule(int $branchId, int $serviceTypeId, string $side): object
    {
        $rule = DB::table('branch_pricing_rules')->where('branch_id',$branchId)->where('service_type_id',$serviceTypeId)->where('is_active',true)->latest()->first();
        if (!$rule) throw ValidationException::withMessages(['pricing'=>"{$side} branch pricing rule is missing for this service."]);
        return $rule;
    }

    private function transferLane(int $from, int $to, int $serviceTypeId): object
    {
        $lane = DB::table('branch_transfer_lanes')->where('from_branch_id',$from)->where('to_branch_id',$to)->where('service_type_id',$serviceTypeId)->where('is_active',true)->latest()->first();
        if (!$lane) throw ValidationException::withMessages(['route'=>'No active branch transfer lane found for this route and service type.']);
        return $lane;
    }

    private function validateDistance(float $distance, object $rule, string $type): void
    {
        $col = $type === 'pickup' ? 'max_pickup_distance_km' : 'max_delivery_distance_km';
        if (($rule->{$col} ?? null) && $distance > (float)$rule->{$col}) {
            throw ValidationException::withMessages([$type => ucfirst($type).' location is outside max allowed distance.']);
        }
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371;
        $dLat = deg2rad($lat2 - $lat1); $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2)**2 + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)**2;
        return $earth * (2 * atan2(sqrt($a), sqrt(1 - $a)));
    }
}
