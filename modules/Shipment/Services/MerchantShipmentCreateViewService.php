<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Merchant\Models\Merchant;

class MerchantShipmentCreateViewService
{
    public function quote(Merchant $merchant, array $payload): array
    {
        $pickupLocation = $this->resolvePickupLocation($merchant, $payload);
        $origin = $this->resolveOrigin($merchant, $pickupLocation);
        $destination = $this->resolveDestination($payload);
        $fare = $this->calculateFare($payload, $pickupLocation, $destination);

        return [
            'pickup_location' => $pickupLocation,
            'fare' => $fare,
            'route' => [
                'origin_branch_id' => $origin['branch_id'],
                'origin_sub_branch_id' => $origin['sub_branch_id'],
                'origin_branch' => $origin['branch'],
                'origin_sub_branch' => $origin['sub_branch'],
                'destination_branch_id' => $destination['branch_id'],
                'destination_sub_branch_id' => $destination['sub_branch_id'],
                'destination_branch' => $destination['branch'],
                'destination_sub_branch' => $destination['sub_branch'],
                'requires_transfer' => $this->requiresTransfer($origin, $destination),
                'steps' => $this->routeSteps($origin, $destination),
            ],
        ];
    }

    public function create($actor, Merchant $merchant, array $payload, string $source = 'merchant_panel'): array
    {
        $orderReference = data_get($payload, 'order_reference');
        $lockKey = 'shipment:create:merchant:' . $merchant->id . ':order:' . ($orderReference ?: Str::uuid());

        return Cache::lock($lockKey, 10)->block(5, function () use ($actor, $merchant, $payload, $source, $orderReference) {
            if ($orderReference && Schema::hasTable('shipments')) {
                $existing = DB::table('shipments')
                    ->where('merchant_id', $merchant->id)
                    ->where('order_reference', $orderReference)
                    ->first();

                if ($existing) {
                    return $this->show($merchant, $existing->id);
                }
            }

            return DB::transaction(function () use ($actor, $merchant, $payload, $source) {
                $quote = $this->quote($merchant, $payload);
                $trackingNumber = $this->trackingNumber();

                $shipmentData = [
                    'merchant_id' => $merchant->id,
                    'created_by' => $actor?->id,
                    'source' => $source,
                    'tracking_number' => $trackingNumber,
                    'order_reference' => data_get($payload, 'order_reference'),
                    'status' => data_get($payload, 'pickup_type') === 'self_drop' ? 'awaiting_self_drop' : 'pending_pickup',
                    'merchant_status' => data_get($payload, 'pickup_type') === 'self_drop' ? 'Awaiting Self Drop' : 'Pending Pickup',
                    'pickup_type' => data_get($payload, 'pickup_type', 'merchant_location'),
                    'pickup_location_id' => data_get($payload, 'pickup_location_id'),
                    'pickup_address' => data_get($quote, 'pickup_location.address'),
                    'origin_branch_id' => data_get($quote, 'route.origin_branch_id'),
                    'origin_sub_branch_id' => data_get($quote, 'route.origin_sub_branch_id'),
                    'destination_branch_id' => data_get($quote, 'route.destination_branch_id'),
                    'destination_sub_branch_id' => data_get($quote, 'route.destination_sub_branch_id'),
                    'requires_transfer' => data_get($quote, 'route.requires_transfer') ? 1 : 0,

                    'receiver_name' => data_get($payload, 'customer.name'),
                    'receiver_phone' => data_get($payload, 'customer.phone'),
                    'receiver_email' => data_get($payload, 'customer.email'),
                    'receiver_address' => data_get($payload, 'delivery.address'),
                    'destination_city' => data_get($payload, 'delivery.city'),
                    'destination_area' => data_get($payload, 'delivery.area'),
                    'delivery_landmark' => data_get($payload, 'delivery.landmark'),
                    'delivery_latitude' => data_get($payload, 'delivery.latitude'),
                    'delivery_longitude' => data_get($payload, 'delivery.longitude'),

                    'package_type' => data_get($payload, 'package.type', 'parcel'),
                    'package_description' => data_get($payload, 'package.description'),
                    'weight' => data_get($payload, 'package.weight'),
                    'actual_weight' => data_get($payload, 'package.weight'),
                    'volumetric_weight' => data_get($quote, 'fare.volumetric_weight'),
                    'chargeable_weight' => data_get($quote, 'fare.chargeable_weight'),
                    'length_cm' => data_get($payload, 'package.length_cm'),
                    'width_cm' => data_get($payload, 'package.width_cm'),
                    'height_cm' => data_get($payload, 'package.height_cm'),
                    'pieces' => data_get($payload, 'package.pieces', 1),
                    'declared_value' => data_get($payload, 'package.value', 0),

                    'payment_type' => data_get($payload, 'payment.type', 'prepaid'),
                    'cod_amount' => data_get($payload, 'payment.type') === 'cod' ? data_get($payload, 'payment.cod_amount', 0) : 0,
                    'delivery_charge_paid_by' => data_get($payload, 'payment.delivery_charge_paid_by', 'merchant'),
                    'delivery_charge' => data_get($quote, 'fare.delivery_charge', 0),
                    'total_collectable' => data_get($quote, 'fare.total_collectable', 0),
                    'special_instruction' => data_get($payload, 'special_instruction'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $shipmentId = DB::table('shipments')->insertGetId($this->filterColumns('shipments', $shipmentData));

                $this->createTracking($shipmentId, 'pending_pickup', 'Shipment created.');
                $this->createPaymentRecord($shipmentId, $merchant, $payload, $quote);

                if (data_get($payload, 'pickup_type') !== 'self_drop') {
                    $this->createPickupRequest($shipmentId, $merchant, $payload, $quote);
                }

                DB::afterCommit(function () use ($shipmentId) {
                    // Optional: wire your existing events/jobs here.
                    // event(new \App\Events\ShipmentStatusUpdated(\Modules\Shipment\Models\Shipment::find($shipmentId)));
                });

                return $this->show($merchant, $shipmentId);
            });
        });
    }

    public function show(Merchant $merchant, int|string $shipmentId): array
    {
        $shipment = DB::table('shipments')
            ->where('merchant_id', $merchant->id)
            ->where('id', $shipmentId)
            ->first();

        abort_if(!$shipment, 404, 'Shipment not found.');

        return [
            'shipment' => $shipment,
            'pickup' => $this->tableFirst('pickup_requests', 'shipment_id', $shipment->id),
            'delivery' => $this->tableFirst('delivery_assignments', 'shipment_id', $shipment->id),
            'payment' => $this->tableFirst('cod_transactions', 'shipment_id', $shipment->id)
                ?: $this->tableFirst('cod_records', 'shipment_id', $shipment->id),
            'route' => $this->buildRouteFromShipment($shipment),
            'history' => $this->history($shipment->id),
        ];
    }

    private function resolvePickupLocation(Merchant $merchant, array $payload): ?object
    {
        if (data_get($payload, 'pickup_type') === 'self_drop') {
            return null;
        }

        $id = data_get($payload, 'pickup_location_id');
        abort_if(!$id, 422, 'Pickup location is required.');

        $location = DB::table('merchant_pickup_locations')
            ->where('merchant_id', $merchant->id)
            ->where('id', $id)
            ->first();

        abort_if(!$location, 404, 'Pickup location not found.');

        return $location;
    }

    private function resolveOrigin(Merchant $merchant, ?object $pickupLocation): array
    {
        $branchId = $pickupLocation?->branch_id
            ?? $merchant->default_branch_id
            ?? null;

        $subBranchId = $pickupLocation?->sub_branch_id
            ?? $merchant->default_sub_branch_id
            ?? null;

        if (!$branchId && $pickupLocation?->latitude && $pickupLocation?->longitude) {
            $nearest = $this->nearestBranch((float) $pickupLocation->latitude, (float) $pickupLocation->longitude, false);
            $branchId = $nearest?->id;
        }

        if (!$subBranchId && $pickupLocation?->latitude && $pickupLocation?->longitude) {
            $nearestSub = $this->nearestBranch((float) $pickupLocation->latitude, (float) $pickupLocation->longitude, true);
            $subBranchId = $nearestSub?->id;
        }

        return [
            'branch_id' => $branchId,
            'sub_branch_id' => $subBranchId,
            'branch' => $branchId ? $this->branch($branchId) : null,
            'sub_branch' => $subBranchId ? $this->branch($subBranchId) : null,
        ];
    }

    private function resolveDestination(array $payload): array
    {
        $lat = data_get($payload, 'delivery.latitude');
        $lng = data_get($payload, 'delivery.longitude');

        $branch = null;
        $subBranch = null;

        if ($lat && $lng) {
            $branch = $this->nearestBranch((float) $lat, (float) $lng, false);
            $subBranch = $this->nearestBranch((float) $lat, (float) $lng, true);
        }

        if (!$branch) {
            $branch = $this->branchByCityArea(data_get($payload, 'delivery.city'), data_get($payload, 'delivery.area'), false);
        }

        if (!$subBranch) {
            $subBranch = $this->branchByCityArea(data_get($payload, 'delivery.city'), data_get($payload, 'delivery.area'), true);
        }

        return [
            'branch_id' => $branch?->id,
            'sub_branch_id' => $subBranch?->id,
            'branch' => $branch,
            'sub_branch' => $subBranch,
        ];
    }

    private function calculateFare(array $payload, ?object $pickupLocation, array $destination): array
    {
        $actualWeight = (float) data_get($payload, 'package.weight', 0);
        $length = (float) data_get($payload, 'package.length_cm', 0);
        $width = (float) data_get($payload, 'package.width_cm', 0);
        $height = (float) data_get($payload, 'package.height_cm', 0);
        $volumetricWeight = $length && $width && $height ? ($length * $width * $height) / 5000 : 0;
        $chargeableWeight = max($actualWeight, $volumetricWeight, 0.1);

        $pickupLat = $pickupLocation?->latitude;
        $pickupLng = $pickupLocation?->longitude;
        $deliveryLat = data_get($payload, 'delivery.latitude');
        $deliveryLng = data_get($payload, 'delivery.longitude');
        $distanceKm = ($pickupLat && $pickupLng && $deliveryLat && $deliveryLng)
            ? $this->distanceKm((float) $pickupLat, (float) $pickupLng, (float) $deliveryLat, (float) $deliveryLng)
            : 5.0;

        $baseFee = (float) config('delivery_workflow.pricing.base_fee', 80);
        $ratePerKm = (float) config('delivery_workflow.pricing.rate_per_km', 12);
        $ratePerKg = (float) config('delivery_workflow.pricing.rate_per_kg', 25);
        $codFeePercent = (float) config('delivery_workflow.pricing.cod_fee_percent', 1);
        $minimumCharge = (float) config('delivery_workflow.pricing.minimum_charge', 100);

        $distanceFee = round($distanceKm * $ratePerKm, 2);
        $weightFee = round($chargeableWeight * $ratePerKg, 2);
        $codAmount = data_get($payload, 'payment.type') === 'cod' ? (float) data_get($payload, 'payment.cod_amount', 0) : 0;
        $codFee = round($codAmount * $codFeePercent / 100, 2);
        $deliveryCharge = max($minimumCharge, round($baseFee + $distanceFee + $weightFee + $codFee, 2));
        $paidBy = data_get($payload, 'payment.delivery_charge_paid_by', 'merchant');
        $totalCollectable = $codAmount + ($paidBy === 'customer' ? $deliveryCharge : 0);

        return [
            'base_fee' => $baseFee,
            'distance_km' => round($distanceKm, 2),
            'distance_fee' => $distanceFee,
            'actual_weight' => $actualWeight,
            'volumetric_weight' => round($volumetricWeight, 2),
            'chargeable_weight' => round($chargeableWeight, 2),
            'weight_fee' => $weightFee,
            'cod_amount' => $codAmount,
            'cod_fee' => $codFee,
            'delivery_charge' => $deliveryCharge,
            'delivery_charge_paid_by' => $paidBy,
            'total_collectable' => round($totalCollectable, 2),
        ];
    }

    private function createPickupRequest(int $shipmentId, Merchant $merchant, array $payload, array $quote): void
    {
        if (!Schema::hasTable('pickup_requests')) return;

        $pickup = data_get($quote, 'pickup_location');
        DB::table('pickup_requests')->insert($this->filterColumns('pickup_requests', [
            'shipment_id' => $shipmentId,
            'merchant_id' => $merchant->id,
            'branch_id' => data_get($quote, 'route.origin_branch_id'),
            'sub_branch_id' => data_get($quote, 'route.origin_sub_branch_id'),
            'pickup_location_id' => $pickup?->id,
            'pickup_address' => $pickup?->address,
            'contact_person' => $pickup?->contact_person ?? $merchant->contact_person ?? null,
            'phone' => $pickup?->phone ?? $merchant->phone ?? null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function createPaymentRecord(int $shipmentId, Merchant $merchant, array $payload, array $quote): void
    {
        if (data_get($payload, 'payment.type') !== 'cod') return;

        $table = Schema::hasTable('cod_transactions') ? 'cod_transactions' : (Schema::hasTable('cod_records') ? 'cod_records' : null);
        if (!$table) return;

        DB::table($table)->insert($this->filterColumns($table, [
            'shipment_id' => $shipmentId,
            'merchant_id' => $merchant->id,
            'cod_amount' => data_get($quote, 'fare.cod_amount', 0),
            'delivery_charge' => data_get($quote, 'fare.delivery_charge', 0),
            'total_collectable' => data_get($quote, 'fare.total_collectable', 0),
            'status' => 'pending_collection',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function createTracking(int $shipmentId, string $status, string $description): void
    {
        $table = Schema::hasTable('shipment_tracking_histories')
            ? 'shipment_tracking_histories'
            : (Schema::hasTable('shipment_status_histories') ? 'shipment_status_histories' : null);

        if (!$table) return;

        DB::table($table)->insert($this->filterColumns($table, [
            'shipment_id' => $shipmentId,
            'status' => $status,
            'title' => Str::headline($status),
            'description' => $description,
            'remarks' => $description,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function buildRouteFromShipment(object $shipment): array
    {
        return [
            'origin_branch' => $shipment->origin_branch_id ? $this->branch($shipment->origin_branch_id) : null,
            'origin_sub_branch' => $shipment->origin_sub_branch_id ? $this->branch($shipment->origin_sub_branch_id) : null,
            'destination_branch' => $shipment->destination_branch_id ? $this->branch($shipment->destination_branch_id) : null,
            'destination_sub_branch' => $shipment->destination_sub_branch_id ? $this->branch($shipment->destination_sub_branch_id) : null,
            'requires_transfer' => (bool) ($shipment->requires_transfer ?? false),
        ];
    }

    private function history(int $shipmentId): array
    {
        $table = Schema::hasTable('shipment_tracking_histories')
            ? 'shipment_tracking_histories'
            : (Schema::hasTable('shipment_status_histories') ? 'shipment_status_histories' : null);

        if (!$table) return [];

        return DB::table($table)->where('shipment_id', $shipmentId)->orderBy('created_at')->get()->toArray();
    }

    private function tableFirst(string $table, string $column, mixed $value): ?object
    {
        if (!Schema::hasTable($table)) return null;
        return DB::table($table)->where($column, $value)->latest('id')->first();
    }

    private function requiresTransfer(array $origin, array $destination): bool
    {
        return $origin['branch_id'] && $destination['branch_id']
            && (int) $origin['branch_id'] !== (int) $destination['branch_id'];
    }

    private function routeSteps(array $origin, array $destination): array
    {
        $steps = ['Pickup Request', 'Origin Hub Scan'];
        if ($this->requiresTransfer($origin, $destination)) {
            $steps[] = 'Transfer Out';
            $steps[] = 'Transfer In';
        }
        $steps[] = 'Destination Branch';
        $steps[] = 'Delivery Rider Assignment';
        $steps[] = 'Delivered / Failed / Returned';
        return $steps;
    }

    private function trackingNumber(): string
    {
        return 'CDMS-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
    }

    private function branch(int|string $id): ?object
    {
        if (!Schema::hasTable('branches')) return null;
        return DB::table('branches')->where('id', $id)->first();
    }

    private function branchByCityArea(?string $city, ?string $area, bool $subBranch): ?object
    {
        if (!Schema::hasTable('branches')) return null;

        $query = DB::table('branches');
        if (Schema::hasColumn('branches', 'type')) {
            $query->where(function ($q) use ($subBranch) {
                if ($subBranch) {
                    $q->where('type', 'sub_branch');
                } else {
                    $q->whereIn('type', ['branch', 'main_branch'])->orWhereNull('parent_id');
                }
            });
        }
        if ($area && Schema::hasColumn('branches', 'area')) $query->where('area', 'like', '%' . $area . '%');
        if ($city && Schema::hasColumn('branches', 'city')) $query->orWhere('city', 'like', '%' . $city . '%');
        return $query->first();
    }

    private function nearestBranch(float $lat, float $lng, bool $subBranch): ?object
    {
        if (!Schema::hasTable('branches')) return null;

        $rows = DB::table('branches')
            ->when(Schema::hasColumn('branches', 'type'), function ($query) use ($subBranch) {
                if ($subBranch) {
                    $query->where('type', 'sub_branch');
                } else {
                    $query->where(function ($q) {
                        $q->whereIn('type', ['branch', 'main_branch'])->orWhereNull('parent_id');
                    });
                }
            })
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get();

        return $rows->sortBy(function ($branch) use ($lat, $lng) {
            return $this->distanceKm($lat, $lng, (float) $branch->latitude, (float) $branch->longitude);
        })->first();
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function filterColumns(string $table, array $data): array
    {
        if (!Schema::hasTable($table)) return $data;
        $columns = Schema::getColumnListing($table);
        return array_intersect_key($data, array_flip($columns));
    }
}
