<?php

namespace Modules\Shipment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Shipment\Models\Shipment;

class AdminShipmentController extends Controller
{
    public function index(Request $request) {
        $q = DB::table('shipments')->leftJoin('branches as pb','pb.id','=','shipments.pickup_branch_id')->leftJoin('branches as db','db.id','=','shipments.delivery_branch_id')->select('shipments.*','pb.name as pickup_branch_name','db.name as delivery_branch_name')->orderByDesc('shipments.id');
        return response()->json(['success'=>true,'data'=>$q->paginate($request->integer('per_page',25))]);
    }

    public function show(Request $request, $id) {
        $shipment = DB::table('shipments')->where('id', $id)->first();

        if (!$shipment) {
            return response()->json([
                'success' => false,
                'message' => 'Shipment not found.',
            ], 404);
        }

        // Same branch scope as the shipments list (ShipmentController@index).
        if (!$this->canViewShipment($request->user(), $shipment)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this shipment.',
            ], 403);
        }

        $this->attachListFields($shipment);

        $priceBreakdown = $this->optionalFirst('shipment_price_breakdowns', 'shipment_id', $id);
        if (!$priceBreakdown && !empty($shipment->delivery_charge_breakdown)) {
            $raw = is_string($shipment->delivery_charge_breakdown)
                ? json_decode($shipment->delivery_charge_breakdown, true)
                : (array) $shipment->delivery_charge_breakdown;
            if (!empty($raw) && is_array($raw)) {
                $b = $raw['breakdown'] ?? $raw;
                $routeBaseRate = (float) (
                    data_get($b, 'route_base_rate.total')
                    ?? data_get($b, 'route_base_rate.amount')
                    ?? data_get($raw, 'route.base_rate')
                    ?? ($shipment->route_fee ?? 0)
                );
                $extraDistTotal = (float) (
                    data_get($b, 'extra_delivery_distance.total')
                    ?? data_get($raw, 'distance.charge')
                    ?? ($shipment->last_mile_fee ?? 0)
                );
                $weightChg = (float) (
                    data_get($b, 'additional_weight.total')
                    ?? data_get($raw, 'weight_summary.total_weight_charge')
                    ?? data_get($raw, 'weight.charge')
                    ?? ($shipment->weight_fee ?? 0)
                );
                $delivCharge = (float) (data_get($raw, 'final_price') ?? ($shipment->delivery_charge ?? 0));
                if ($weightChg <= 0 && $delivCharge > ($routeBaseRate + $extraDistTotal)) {
                    $weightChg = round($delivCharge - ($routeBaseRate + $extraDistTotal), 2);
                }

                $includedDistKm = (float) (
                    data_get($b, 'extra_delivery_distance.included_distance_km')
                    ?? data_get($raw, 'distance.included_distance_km')
                    ?? 10.0
                );
                $deliveryDistKm = (float) (
                    data_get($b, 'extra_delivery_distance.delivery_distance_km')
                    ?? data_get($raw, 'distance.delivery_distance_km')
                    ?? 0
                );
                $extraDistKm = (float) (
                    data_get($b, 'extra_delivery_distance.extra_distance_km')
                    ?? data_get($raw, 'distance.extra_distance_km')
                    ?? 0
                );
                $extraDistRate = (float) (
                    data_get($b, 'extra_delivery_distance.rate_per_km')
                    ?? data_get($raw, 'distance.rate_per_km')
                    ?? 5.0
                );

                $priceBreakdown = (object) [
                    'pickup_branch_name'    => data_get($raw, 'pickup_branch.name') ?? ($shipment->origin_branch_name ?? null),
                    'delivery_branch_name'  => data_get($raw, 'delivery_branch.name') ?? ($shipment->destination_branch_name ?? null),
                    'same_branch'           => (bool) (data_get($raw, 'route.same_branch') ?? ((int) ($shipment->origin_branch_id ?? 0) === (int) ($shipment->destination_branch_id ?? -1))),
                    'route_base_rate'       => $routeBaseRate,
                    'base_pickup_fee'       => (float) ($shipment->pickup_fee ?? 0),
                    'base_delivery_fee'     => $routeBaseRate,
                    'base_transfer_fee'     => 0,
                    'pickup_extra_charge'   => 0,
                    'delivery_extra_charge' => $extraDistTotal,
                    'extra_distance_km'     => $extraDistKm,
                    'extra_distance_rate'   => $extraDistRate,
                    'extra_delivery_distance' => data_get($b, 'extra_delivery_distance') ?? [
                        'delivery_distance_km' => $deliveryDistKm,
                        'included_distance_km' => $includedDistKm,
                        'extra_distance_km'    => $extraDistKm,
                        'rate_per_km'          => $extraDistRate,
                        'total'                => $extraDistTotal,
                    ],
                    'weight_charge'         => $weightChg,
                    'included_weight_kg'    => (float) (data_get($b, 'additional_weight.included_weight_kg') ?? data_get($raw, 'weight_summary.included_weight_kg') ?? 2.0),
                    'additional_weight'     => data_get($b, 'additional_weight') ?? [
                        'total_weight_kg'   => (float) ($shipment->weight ?? 0),
                        'included_weight_kg'=> (float) (data_get($b, 'additional_weight.included_weight_kg') ?? 2.0),
                        'excess_weight_kg'  => (float) (data_get($b, 'additional_weight.excess_weight_kg') ?? 0),
                        'rate_per_kg'       => (float) (data_get($b, 'additional_weight.rate_per_kg') ?? 50.0),
                        'total'             => $weightChg,
                    ],
                    'fragile_charge'        => (float) (data_get($b, 'fragile.total') ?? data_get($raw, 'fragile.charge') ?? 0),
                    'same_day_charge'       => (float) (data_get($b, 'same_day.total') ?? data_get($raw, 'same_day.charge') ?? 0),
                    'express_charge'        => (float) (data_get($b, 'express.total') ?? data_get($raw, 'express.charge') ?? 0),
                    'pod_fee'               => (float) ($shipment->pod_charge ?? 0),
                    'final_price'           => $delivCharge,
                    'raw'                   => $raw,
                ];
            }
        }

        $distanceData = $this->calculateShipmentDistances($shipment, $priceBreakdown);

        return response()->json(['success' => true, 'data' => [
            'shipment' => $shipment,
            'price_breakdown' => $priceBreakdown,
            'distance_data' => $distanceData,
            'tasks' => $this->optionalGet('shipment_tasks', 'shipment_id', $id, 'id'),
            'status_logs' => $this->optionalGet('shipment_status_logs', 'shipment_id', $id, 'id'),
            'notifications' => $this->optionalGet('staff_notifications', 'shipment_id', $id, 'id', true),
        ]]);
    }

    /**
     * Add the same branch names and computed fields the shipments list uses, so the
     * drawer shows current branch names (not the stale path_text snapshot) and the
     * same transfer / collect values as the table. Additive only.
     */
    private function attachListFields(object $shipment): void
    {
        $keys = ['origin', 'destination', 'current', 'next_hop'];
        $subKeys = ['origin_sub', 'destination_sub', 'current_sub'];
        $allKeys = array_merge($keys, $subKeys);

        $ids = array_values(array_filter(array_map(
            fn ($key) => $shipment->{$key . '_branch_id'} ?? null,
            $allKeys
        )));
        $branches = $ids
            ? DB::table('branches')
                ->whereIn('id', $ids)
                ->get(['id', 'name', 'code', 'latitude', 'longitude', 'office_latitude', 'office_longitude'])
                ->keyBy('id')
            : collect();

        foreach ($allKeys as $key) {
            $branchId = $shipment->{$key . '_branch_id'} ?? null;
            $branch = $branchId ? $branches->get($branchId) : null;
            $shipment->{$key . '_branch'} = $branch;
            if (in_array($key, $keys, true)) {
                $shipment->{$key . '_branch_name'} = $branch->name ?? null;
            }
        }

        try {
            $model = Shipment::find($shipment->id);
            if ($model) {
                $shipment->is_transfer = $model->is_transfer;
                $shipment->transfer_stage_label = $model->transfer_stage_label;
                $shipment->collect_amount = $model->collect_amount;
            }
        } catch (\Throwable) {
            // computed fields are optional extras
        }
    }

    private function canViewShipment($user, object $shipment): bool
    {
        if (!$user) {
            return false;
        }

        $isGlobalAdmin = (bool) (
            $user->is_super_admin
            || $user->role === 'super_admin'
            || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (method_exists($user, 'isHqUser') && $user->isHqUser())
            || (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['main_admin', 'admin', 'hq_admin', 'hq_manager', 'hq_operations_staff', 'hq_accounts_staff']))
            || (method_exists($user, 'hasRole') && ($user->hasRole('main_admin') || $user->hasRole('hq_admin') || $user->hasRole('hq_manager') || $user->hasRole('hq_operations_staff')))
        );
        if ($isGlobalAdmin) {
            return true;
        }

        $branchId = (int) ($user->branch_id ?? 0);
        if (!$branchId) {
            return false;
        }

        return in_array($branchId, array_map('intval', [
            $shipment->origin_branch_id ?? 0,
            $shipment->origin_sub_branch_id ?? 0,
            $shipment->destination_branch_id ?? 0,
            $shipment->destination_sub_branch_id ?? 0,
            $shipment->current_branch_id ?? 0,
            $shipment->current_sub_branch_id ?? 0,
        ]), true);
    }
    private function optionalFirst(string $table, string $fk, mixed $id): mixed
    {
        if (!Schema::hasTable($table)) {
            return null;
        }

        return DB::table($table)->where($fk, $id)->first();
    }

    private function optionalGet(
        string $table,
        string $fk,
        mixed $id,
        string $orderCol,
        bool $desc = false
    ): array {
        if (!Schema::hasTable($table)) {
            return [];
        }

        $q = DB::table($table)->where($fk, $id);
        $q = $desc ? $q->orderByDesc($orderCol) : $q->orderBy($orderCol);

        return $q->get()->all();
    }

    private function calculateShipmentDistances(object $shipment, ?object $priceBreakdown = null): array
    {
        $totalDistance = (float) ($shipment->route_distance_km ?? 0);

        $originBranch = $shipment->origin_sub_branch ?? $shipment->origin_branch ?? null;
        $destBranch   = $shipment->destination_sub_branch ?? $shipment->destination_branch ?? null;
        $currentBranch= $shipment->current_sub_branch ?? $shipment->current_branch ?? $originBranch;

        $originLat = $originBranch->latitude ?? $originBranch->office_latitude ?? (float) ($shipment->pickup_lat ?? 0);
        $originLng = $originBranch->longitude ?? $originBranch->office_longitude ?? (float) ($shipment->pickup_lng ?? 0);

        $destLat = $destBranch->latitude ?? $destBranch->office_latitude ?? (float) ($shipment->delivery_lat ?? 0);
        $destLng = $destBranch->longitude ?? $destBranch->office_longitude ?? (float) ($shipment->delivery_lng ?? 0);

        $currentLat = $currentBranch->latitude ?? $currentBranch->office_latitude ?? $originLat;
        $currentLng = $currentBranch->longitude ?? $currentBranch->office_longitude ?? $originLng;

        $destToCustomer = null;
        $baseDistance = 10.0;
        $extraMile = 0.0;

        if ($priceBreakdown && !empty($priceBreakdown->extra_delivery_distance)) {
            $extraObj = (array) $priceBreakdown->extra_delivery_distance;
            $destToCustomer = !empty($extraObj['delivery_distance_km']) ? (float) $extraObj['delivery_distance_km'] : null;
            $baseDistance = !empty($extraObj['included_distance_km']) ? (float) $extraObj['included_distance_km'] : 10.0;
            $extraMile = !empty($extraObj['extra_distance_km']) ? (float) $extraObj['extra_distance_km'] : 0.0;
        }

        if ($destToCustomer === null && $destLat && !empty($shipment->delivery_lat) && !empty($shipment->delivery_lng)) {
            $destToCustomer = $this->haversineKm((float) $destLat, (float) $destLng, (float) $shipment->delivery_lat, (float) $shipment->delivery_lng);
            $extraMile = max(0.0, round($destToCustomer - $baseDistance, 2));
        }

        if ($totalDistance <= 0 && $originLat && !empty($shipment->delivery_lat) && !empty($shipment->delivery_lng)) {
            $totalDistance = $this->haversineKm((float) $originLat, (float) $originLng, (float) $shipment->delivery_lat, (float) $shipment->delivery_lng);
        }

        $status = strtolower((string) ($shipment->status ?? ''));
        $isDelivered = ($status === 'delivered');
        $isAtDestinationOrBeyond = in_array($status, [
            'received_at_destination',
            'received_at_destination_branch',
            'received_at_destination_sub_branch',
            'assigned_to_rider',
            'out_for_delivery',
            'delivered',
        ], true) || (
            !empty($shipment->current_branch_id) &&
            !empty($shipment->destination_branch_id) &&
            (int) $shipment->current_branch_id === (int) $shipment->destination_branch_id
        );

        if ($isDelivered) {
            $coveredDistance = $totalDistance;
            $distanceFromDest = 0.0;
            $progressPercent = 100;
        } elseif ($isAtDestinationOrBeyond) {
            $distanceFromDest = 0.0;
            $coveredDistance = $totalDistance * 0.9;
            $progressPercent = $totalDistance > 0 ? (int) round(($coveredDistance / $totalDistance) * 100) : 90;
        } elseif ($status === 'in_transit' || $status === 'received_at_transit_hub') {
            $distanceFromDest = ($currentLat && $destLat)
                ? $this->haversineKm((float) $currentLat, (float) $currentLng, (float) $destLat, (float) $destLng)
                : $totalDistance * 0.5;
            $coveredDistance = max(0.0, $totalDistance - $distanceFromDest);
            $progressPercent = $totalDistance > 0 ? (int) min(95, max(15, round(($coveredDistance / $totalDistance) * 100))) : 50;
        } elseif (in_array($status, ['picked_up', 'received_at_origin', 'received_at_origin_branch', 'sorted_for_transfer'], true)) {
            $distanceFromDest = ($originLat && $destLat)
                ? $this->haversineKm((float) $originLat, (float) $originLng, (float) $destLat, (float) $destLng)
                : $totalDistance;
            $coveredDistance = min($totalDistance * 0.1, 5.0);
            $progressPercent = 10;
        } else {
            $distanceFromDest = ($originLat && $destLat)
                ? $this->haversineKm((float) $originLat, (float) $originLng, (float) $destLat, (float) $destLng)
                : $totalDistance;
            $coveredDistance = 0.0;
            $progressPercent = 0;
        }

        return [
            'total_distance_km'                   => round($totalDistance, 2),
            'destination_to_customer_km'          => $destToCustomer !== null ? round($destToCustomer, 2) : null,
            'base_distance_km'                    => round($baseDistance, 2),
            'extra_mile_km'                       => round($extraMile, 2),
            'covered_distance_km'                 => round($coveredDistance, 2),
            'distance_from_destination_branch_km' => round($distanceFromDest, 2),
            'progress_percent'                    => (int) $progressPercent,
            'is_at_destination'                   => $isAtDestinationOrBeyond,
        ];
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return round($earthRadius * $c, 2);
    }
}