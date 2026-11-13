<?php

declare(strict_types=1);

namespace Modules\Dispatch\Services;

use App\Support\CourierStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Dispatch\Models\DispatchManifest;
use Modules\Dispatch\Models\TransferCorridor;
use Modules\Dispatch\Models\TransferRun;
use Modules\Dispatch\Models\TransferRunItem;
use Modules\Dispatch\Models\TransferRunStop;
use Modules\Pickup\Support\PickupCustody;
use Modules\Rate\Models\BranchTransferRoute;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Models\TransferRequest;
use Modules\Shipment\Services\TransferRouteProgressService;

final class TransferPlanningService
{
    public const LINEHAUL_VEHICLES = ['van', 'pickup', 'truck'];

    public const SERVICE_PRIORITY = [
        'same_day' => 3,
        'express' => 2,
        'flight' => 2,
        'standard' => 1,
    ];

    public const SLA_HOURS = [
        'same_day' => 8,
        'express' => 12,
        'flight' => 12,
        'standard' => 24,
    ];

    private const ROAD_FACTOR = 1.35;

    private const DEFAULT_SPEED_KMH = 35.0;

    public function __construct(
        private readonly TransferContainerService $containers,
        private readonly TransferRouteProgressService $progress,
    ) {
    }

    public function demand(?int $originBranchId, ?string $serviceType, ?string $priority, int $scopeBranchId): array
    {
        $shipments = $this->waitingShipments($scopeBranchId, $originBranchId, $serviceType);
        $branches = $this->operationalBranches()->keyBy('id');
        $cells = [];

        foreach ($shipments as $shipment) {
            $originId = $this->waitingAtBranchId($shipment);
            $destId = (int) ($this->progress->resolveOperationalBranchId((int) ($shipment->destination_branch_id ?? 0)) ?? $shipment->destination_branch_id ?? 0);
            if ($originId <= 0 || $destId <= 0 || $originId === $destId) {
                continue;
            }
            $service = strtolower((string) ($shipment->service_type ?? 'standard')) ?: 'standard';
            $key = $originId.'|'.$destId.'|'.$service;
            if (! isset($cells[$key])) {
                $cells[$key] = [
                    'origin_branch_id' => $originId,
                    'destination_branch_id' => $destId,
                    'service_type' => $service,
                    'count' => 0,
                    'weight_kg' => 0.0,
                    'oldest_at' => null,
                    'sla_risk_count' => 0,
                    'shipment_ids' => [],
                ];
            }
            $weight = (float) ($shipment->chargeable_weight ?? $shipment->weight ?? 1);
            $waitingSince = $shipment->sorted_at ?? $shipment->updated_at ?? $shipment->created_at;
            $cells[$key]['count']++;
            $cells[$key]['weight_kg'] += $weight;
            $cells[$key]['shipment_ids'][] = (int) $shipment->id;
            if ($waitingSince && ($cells[$key]['oldest_at'] === null || $waitingSince < $cells[$key]['oldest_at'])) {
                $cells[$key]['oldest_at'] = $waitingSince;
            }
            if ($this->isSlaRisk($shipment, $waitingSince)) {
                $cells[$key]['sla_risk_count']++;
            }
        }

        if ($priority === 'sla_risk') {
            $cells = array_filter($cells, fn ($c) => $c['sla_risk_count'] > 0);
        } elseif ($priority === 'express') {
            $cells = array_filter($cells, fn ($c) => in_array($c['service_type'], ['express', 'same_day', 'flight'], true));
        }

        $matrixMap = [];
        $flowMap = [];
        foreach ($cells as &$cell) {
            $origin = $branches->get($cell['origin_branch_id']);
            $dest = $branches->get($cell['destination_branch_id']);
            $cell['origin_name'] = $origin?->name ?? ('Branch #'.$cell['origin_branch_id']);
            $cell['origin_code'] = $origin?->code;
            $cell['origin_city'] = $origin?->city;
            $cell['origin_lat'] = $origin ? (float) $origin->latitude : null;
            $cell['origin_lng'] = $origin ? (float) $origin->longitude : null;
            $cell['destination_name'] = $dest?->name ?? ('Branch #'.$cell['destination_branch_id']);
            $cell['destination_code'] = $dest?->code;
            $cell['destination_city'] = $dest?->city;
            $cell['destination_lat'] = $dest ? (float) $dest->latitude : null;
            $cell['destination_lng'] = $dest ? (float) $dest->longitude : null;
            $cell['weight_kg'] = round((float) $cell['weight_kg'], 2);
            $cell['oldest_at'] = $cell['oldest_at'] instanceof \DateTimeInterface ? $cell['oldest_at']->toISOString() : $cell['oldest_at'];
            $cell['oldest_age_hours'] = $cell['oldest_at']
                ? round((now()->getTimestamp() - Carbon::parse($cell['oldest_at'])->getTimestamp()) / 3600, 1)
                : 0;

            $oid = $cell['origin_branch_id'];
            $did = $cell['destination_branch_id'];
            if (! isset($matrixMap[$oid])) {
                $matrixMap[$oid] = [
                    'origin_branch_id' => $oid,
                    'origin_name' => $cell['origin_name'],
                    'origin_code' => $cell['origin_code'],
                    'origin_city' => $cell['origin_city'],
                    'origin_lat' => $cell['origin_lat'],
                    'origin_lng' => $cell['origin_lng'],
                    'count' => 0,
                    'weight_kg' => 0.0,
                    'sla_risk_count' => 0,
                    'destinations' => [],
                ];
            }
            if (! isset($matrixMap[$oid]['destinations'][$did])) {
                $matrixMap[$oid]['destinations'][$did] = [
                    'destination_branch_id' => $did,
                    'destination_name' => $cell['destination_name'],
                    'destination_code' => $cell['destination_code'],
                    'destination_city' => $cell['destination_city'],
                    'destination_lat' => $cell['destination_lat'],
                    'destination_lng' => $cell['destination_lng'],
                    'count' => 0,
                    'weight_kg' => 0.0,
                    'sla_risk_count' => 0,
                    'oldest_at' => $cell['oldest_at'],
                    'oldest_age_hours' => $cell['oldest_age_hours'],
                    'by_service' => [],
                ];
            }
            $destRow =& $matrixMap[$oid]['destinations'][$did];
            $destRow['count'] += $cell['count'];
            $destRow['weight_kg'] = round($destRow['weight_kg'] + $cell['weight_kg'], 2);
            $destRow['sla_risk_count'] += $cell['sla_risk_count'];
            if ($cell['oldest_at'] && ($destRow['oldest_at'] === null || $cell['oldest_at'] < $destRow['oldest_at'])) {
                $destRow['oldest_at'] = $cell['oldest_at'];
                $destRow['oldest_age_hours'] = $cell['oldest_age_hours'];
            }
            $destRow['by_service'][] = [
                'service_type' => $cell['service_type'],
                'count' => $cell['count'],
                'weight_kg' => $cell['weight_kg'],
                'oldest_at' => $cell['oldest_at'],
                'oldest_age_hours' => $cell['oldest_age_hours'],
                'sla_risk_count' => $cell['sla_risk_count'],
                'shipment_ids' => $cell['shipment_ids'],
            ];
            $matrixMap[$oid]['count'] += $cell['count'];
            $matrixMap[$oid]['weight_kg'] = round($matrixMap[$oid]['weight_kg'] + $cell['weight_kg'], 2);
            $matrixMap[$oid]['sla_risk_count'] += $cell['sla_risk_count'];
            unset($destRow);

            $fkey = $oid.'|'.$did;
            if (! isset($flowMap[$fkey])) {
                $flowMap[$fkey] = [
                    'origin_branch_id' => $oid,
                    'destination_branch_id' => $did,
                    'origin_name' => $cell['origin_name'],
                    'destination_name' => $cell['destination_name'],
                    'from_lat' => $cell['origin_lat'],
                    'from_lng' => $cell['origin_lng'],
                    'to_lat' => $cell['destination_lat'],
                    'to_lng' => $cell['destination_lng'],
                    'count' => 0,
                    'weight_kg' => 0.0,
                    'sla_risk_count' => 0,
                ];
            }
            $flowMap[$fkey]['count'] += $cell['count'];
            $flowMap[$fkey]['weight_kg'] = round($flowMap[$fkey]['weight_kg'] + $cell['weight_kg'], 2);
            $flowMap[$fkey]['sla_risk_count'] += $cell['sla_risk_count'];
        }
        unset($cell);

        $matrix = array_values(array_map(function (array $row) {
            $row['destinations'] = array_values($row['destinations']);
            usort($row['destinations'], fn ($a, $b) => $b['count'] <=> $a['count']);
            return $row;
        }, $matrixMap));
        usort($matrix, fn ($a, $b) => $b['count'] <=> $a['count']);

        $cellList = array_values($cells);
        usort($cellList, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'matrix' => $matrix,
            'cells' => $cellList,
            'flows' => array_values($flowMap),
            'requests' => $this->pendingRequests($scopeBranchId, $originBranchId),
            'branches' => $branches->map(fn (Branch $b) => $this->branchPayload($b))->values()->all(),
            'totals' => [
                'parcels' => array_sum(array_column($cellList, 'count')),
                'weight_kg' => round(array_sum(array_column($cellList, 'weight_kg')), 2),
                'sla_risk' => array_sum(array_column($cellList, 'sla_risk_count')),
                'od_pairs' => count($flowMap),
                'origins' => count($matrix),
            ],
            'service_types' => collect($cellList)->pluck('service_type')->unique()->values()->all(),
        ];
    }

    public function recommend(?int $originBranchId, ?string $serviceType, int $scopeBranchId): array
    {
        return $this->planFleet($originBranchId, $serviceType, $scopeBranchId);
    }

    public function createRun(array $payload, ?int $actorId, int $scopeBranchId): array
    {
        $originId = (int) ($payload['origin_branch_id'] ?? 0);
        if ($originId <= 0 && empty($payload['recommendation_key'])) {
            throw ValidationException::withMessages(['origin_branch_id' => ['Origin branch is required.']]);
        }

        $stopIds = array_values(array_filter(array_map('intval', $payload['stop_branch_ids'] ?? []), fn ($id) => $id !== $originId));
        $shipmentIds = array_values(array_unique(array_map('intval', $payload['shipment_ids'] ?? [])));

        if (! empty($payload['recommendation_key'])) {
            $rec = $this->findRecommendation((string) $payload['recommendation_key'], $originId ?: (int) ($payload['origin_branch_id'] ?? 0), $scopeBranchId, $payload);
            if ($rec !== null) {
                if ($originId <= 0) {
                    $originId = (int) $rec['origin_branch_id'];
                }
                if ($stopIds === []) {
                    $stopIds = array_values(array_filter(array_map(fn ($s) => (int) $s['branch_id'], array_filter($rec['stops'], fn ($s) => ($s['kind'] ?? '') !== 'origin'))));
                }
                if ($shipmentIds === []) {
                    $shipmentIds = $rec['shipment_ids'];
                }
                $payload['vehicle_type'] = $payload['vehicle_type'] ?? $rec['vehicle_type'];
                $payload['corridor_code'] = $payload['corridor_code'] ?? $rec['corridor_code'];
                $payload['tour_code'] = $payload['tour_code'] ?? ($rec['tour_code'] ?? null);
                $payload['leg_role'] = $payload['leg_role'] ?? ($rec['leg_role'] ?? $rec['tour_role'] ?? null);
                $payload['planned_ahead'] = $payload['planned_ahead'] ?? ($rec['planned_ahead'] ?? false);
                $payload['why'] = $payload['why'] ?? $rec['why'];
                $payload['estimated_km'] = $payload['estimated_km'] ?? $rec['estimated_km'];
                $payload['estimated_hours'] = $payload['estimated_hours'] ?? $rec['estimated_hours'];
                $payload['recommendation_key'] = $rec['key'];
            } elseif ($shipmentIds === []) {
                throw ValidationException::withMessages(['recommendation_key' => ['That recommendation is no longer valid. Refresh and try again.']]);
            }
        }

        if ($originId <= 0) {
            throw ValidationException::withMessages(['origin_branch_id' => ['Origin branch is required.']]);
        }
        if ($scopeBranchId !== 0 && $scopeBranchId !== $originId) {
            throw ValidationException::withMessages(['origin_branch_id' => ['You can only create a TR run from your own branch.']]);
        }
        if ($stopIds === []) {
            throw ValidationException::withMessages(['stop_branch_ids' => ['Select at least one destination or transit stop.']]);
        }
        if ($shipmentIds === []) {
            throw ValidationException::withMessages(['shipment_ids' => ['Select parcels to load on this TR run.']]);
        }

        $shipments = $this->waitingShipments($scopeBranchId, $originId, $payload['service_type'] ?? null)
            ->whereIn('id', $shipmentIds)
            ->values();
        $plannedAhead = (bool) ($payload['planned_ahead'] ?? false);
        if ($shipments->isEmpty()) {
            if (! $plannedAhead) {
                throw ValidationException::withMessages(['shipment_ids' => ['None of the selected parcels are still waiting to transfer from this origin.']]);
            }
            $shipments = Shipment::query()->whereIn('id', $shipmentIds)->get();
            if ($shipments->isEmpty()) {
                throw ValidationException::withMessages(['shipment_ids' => ['None of the selected parcels could be found.']]);
            }
        }

        $firstHop = $stopIds[0];
        $pathIds = array_values(array_unique([$originId, ...$stopIds]));
        $vehicleType = (string) ($payload['vehicle_type'] ?? 'pickup');
        $caps = TransferContainerService::VEHICLE_CAPACITIES[$vehicleType] ?? TransferContainerService::VEHICLE_CAPACITIES['pickup'];
        $branches = $this->operationalBranches()->keyBy('id');

        $dropCounts = [];
        foreach ($shipments as $s) {
            $dest = (int) ($this->progress->resolveOperationalBranchId((int) $s->destination_branch_id) ?? $s->destination_branch_id);
            $drop = in_array($dest, $stopIds, true) ? $dest : $stopIds[count($stopIds) - 1];
            $dropCounts[$drop] = ($dropCounts[$drop] ?? 0) + 1;
        }

        $weight = round((float) $shipments->sum(fn ($s) => (float) ($s->chargeable_weight ?? $s->weight ?? 1)), 2);
        $services = $shipments->pluck('service_type')->map(fn ($v) => strtolower((string) $v) ?: 'standard')->unique()->values()->all();
        $km = isset($payload['estimated_km']) ? (float) $payload['estimated_km'] : $this->pathDistanceKm($pathIds, $branches);
        $hours = isset($payload['estimated_hours']) ? (float) $payload['estimated_hours'] : $this->hoursFor($km, count($stopIds), null);

        if ($plannedAhead) {
            return $this->createPlannedRun($payload, $actorId, $originId, $stopIds, $pathIds, $shipments, $vehicleType, $caps, $dropCounts, $weight, $services, $km, $hours, $branches);
        }

        return DB::transaction(function () use (

            $payload, $actorId, $originId, $stopIds, $pathIds, $shipments, $firstHop,
            $vehicleType, $caps, $dropCounts, $weight, $services, $km, $hours, $branches
        ) {
            $run = TransferRun::query()->create([
                'run_number' => $this->nextRunNumber($originId),
                'status' => TransferRun::STATUS_LOADING,
                'origin_branch_id' => $originId,
                'vehicle_type' => $vehicleType,
                'vehicle_number' => $payload['vehicle_number'] ?? null,
                'rider_user_id' => $payload['rider_user_id'] ?? null,
                'driver_name' => $payload['driver_name'] ?? null,
                'driver_phone' => $payload['driver_phone'] ?? null,
                'seal_number' => $payload['seal_number'] ?? null,
                'planned_departure_at' => $payload['planned_departure_at'] ?? null,
                'corridor_code' => $payload['corridor_code'] ?? null,
                'tour_code' => $payload['tour_code'] ?? null,
                'leg_role' => $payload['leg_role'] ?? null,
                'recommendation_key' => $payload['recommendation_key'] ?? null,
                'why' => $payload['why'] ?? $this->manualWhy($originId, $stopIds, $shipments->count(), $branches),
                'estimated_km' => $km,
                'estimated_hours' => $hours,
                'current_stop_sequence' => 0,
                'transport_cost' => $payload['transport_cost'] ?? null,
                'expected_count' => $shipments->count(),
                'expected_weight_kg' => $weight,
                'max_parcels' => $caps['max_parcels'],
                'max_weight_kg' => $caps['max_kg'],
                'service_types' => $services,
                'created_by' => $actorId,
                'notes' => $payload['notes'] ?? null,
            ]);

            $seq = 0;
            TransferRunStop::query()->create([
                'transfer_run_id' => $run->id,
                'sequence' => $seq++,
                'branch_id' => $originId,
                'kind' => 'origin',
                'status' => 'pending',
                'expected_parcels' => 0,
            ]);
            foreach ($stopIds as $i => $bid) {
                TransferRunStop::query()->create([
                    'transfer_run_id' => $run->id,
                    'sequence' => $seq++,
                    'branch_id' => $bid,
                    'kind' => ($dropCounts[$bid] ?? 0) > 0 ? 'drop' : 'hub',
                    'status' => 'pending',
                    'expected_parcels' => (int) ($dropCounts[$bid] ?? 0),
                    'planned_arrival_at' => ! empty($payload['planned_departure_at'])
                        ? Carbon::parse($payload['planned_departure_at'])->addMinutes((int) (($hours / max(1, count($stopIds))) * 60 * ($i + 1)))
                        : null,
                ]);
            }

            foreach ($shipments as $s) {
                $dest = (int) ($this->progress->resolveOperationalBranchId((int) $s->destination_branch_id) ?? $s->destination_branch_id);
                $drop = in_array($dest, $stopIds, true) ? $dest : $stopIds[count($stopIds) - 1];
                TransferRunItem::query()->create([
                    'transfer_run_id' => $run->id,
                    'shipment_id' => $s->id,
                    'drop_stop_branch_id' => $drop,
                    'service_type' => strtolower((string) ($s->service_type ?? 'standard')) ?: 'standard',
                    'status' => 'planned',
                ]);
                $destIndex = array_search($drop, $pathIds, true);
                $routePath = $destIndex === false ? $pathIds : array_slice($pathIds, 0, (int) $destIndex + 1);
                if (count($routePath) < 2) {
                    $routePath = [$originId, $drop];
                }
                $route = $this->ensureRoute($routePath, strtolower((string) ($s->service_type ?? 'standard')) ?: 'standard');
                if ($route && Schema::hasColumn('shipments', 'transfer_route_id')) {
                    $s->forceFill(['transfer_route_id' => $route->id])->save();
                }
                $this->progress->applyProgressToShipment($s->fresh(), $originId);
            }

            $dispatch = $this->containers->dispatch(
                $shipments->map(fn ($s) => $s->fresh()),
                $firstHop,
                $originId,
                [
                    'hold' => true,
                    'vehicle_type' => $vehicleType,
                    'vehicle_number' => $payload['vehicle_number'] ?? null,
                    'rider_user_id' => $payload['rider_user_id'] ?? null,
                    'driver_name' => $payload['driver_name'] ?? null,
                    'driver_phone' => $payload['driver_phone'] ?? null,
                    'seal_number' => $payload['seal_number'] ?? null,
                    'notes' => $payload['notes'] ?? ('TMS run '.$run->run_number),
                    'transport_cost' => $payload['transport_cost'] ?? null,
                ],
                $actorId,
            );

            $manifests = $dispatch['manifests'] ?? [];
            $skipped = $dispatch['skipped'] ?? [];
            if ($manifests === []) {
                throw ValidationException::withMessages([
                    'shipment_ids' => [
                        'Could not open a TR container for this run. '.($skipped ? implode(' ', array_slice(array_values($skipped), 0, 3)) : 'Check next-hop routes.'),
                    ],
                ]);
            }

            $manifest = $manifests[0];
            $manifest->forceFill([
                'transfer_run_id' => $run->id,
                'planned_departure_at' => $payload['planned_departure_at'] ?? null,
                'estimated_hours' => $hours,
                'estimated_km' => $km,
                'estimated_arrival_at' => ! empty($payload['planned_departure_at'])
                    ? Carbon::parse($payload['planned_departure_at'])->addMinutes((int) ($hours * 60))
                    : now()->addMinutes((int) ($hours * 60)),
                'max_parcels' => $caps['max_parcels'],
                'max_weight_kg' => $caps['max_kg'],
                'corridor_code' => $payload['corridor_code'] ?? null,
                'recommendation_why' => $payload['why'] ?? $run->why,
            ])->save();

            TransferRunStop::query()->where('transfer_run_id', $run->id)->where('branch_id', $firstHop)
                ->update(['dispatch_manifest_id' => $manifest->id]);

            $addedIds = collect($manifests)->flatMap(fn ($m) => $m->items->pluck('shipment_id'))->map(fn ($id) => (int) $id)->all();
            TransferRunItem::query()->where('transfer_run_id', $run->id)->whereIn('shipment_id', $addedIds)->update(['status' => 'loaded']);
            if ($skipped !== []) {
                TransferRunItem::query()->where('transfer_run_id', $run->id)
                    ->whereIn('shipment_id', array_map('intval', array_keys($skipped)))
                    ->update(['status' => 'skipped']);
            }
            $run->update(['expected_count' => count($addedIds)]);

            return [
                'run' => $this->serializeRun($run->fresh(['originBranch', 'stops.branch', 'items', 'rider', 'manifests'])),
                'manifests' => array_map(fn ($m) => [
                    'id' => $m->id,
                    'transfer_number' => $m->display_number,
                    'status' => $m->status,
                    'from_branch_id' => $m->from_branch_id,
                    'to_branch_id' => $m->to_branch_id,
                    'expected_count' => $m->expected_count,
                ], $manifests),
                'skipped' => $skipped,
            ];
        });
    }

    public function dispatchRun(TransferRun $run, array $meta, ?int $actorId): array
    {
        if (! in_array($run->status, [TransferRun::STATUS_PLANNED, TransferRun::STATUS_LOADING], true)) {
            throw ValidationException::withMessages(['status' => ['Only a planned or loading TR run can be dispatched.']]);
        }
        $manifest = $run->manifests()->where('status', DispatchManifest::STATUS_OPEN)->first();
        if (! $manifest) {
            throw ValidationException::withMessages(['status' => ['This run has no open TR container to dispatch.']]);
        }
        $meta = array_merge([
            'vehicle_type' => $run->vehicle_type,
            'vehicle_number' => $run->vehicle_number,
            'rider_user_id' => $run->rider_user_id,
            'driver_name' => $run->driver_name,
            'driver_phone' => $run->driver_phone,
            'seal_number' => $run->seal_number,
            'transport_cost' => $run->transport_cost,
        ], $meta);

        $this->containers->dispatchOpen($manifest, $meta, $actorId);
        $run->update([
            'status' => TransferRun::STATUS_DISPATCHED,
            'dispatched_at' => now(),
            'vehicle_type' => $meta['vehicle_type'] ?? $run->vehicle_type,
            'vehicle_number' => $meta['vehicle_number'] ?? $run->vehicle_number,
            'rider_user_id' => $meta['rider_user_id'] ?? $run->rider_user_id,
            'driver_name' => $meta['driver_name'] ?? $run->driver_name,
            'driver_phone' => $meta['driver_phone'] ?? $run->driver_phone,
            'seal_number' => $meta['seal_number'] ?? $run->seal_number,
            'current_stop_sequence' => 1,
        ]);
        $run->stops()->where('kind', 'origin')->update(['status' => 'departed', 'departed_at' => now()]);
        TransferRunItem::query()->where('transfer_run_id', $run->id)->where('status', 'loaded')->update(['status' => 'in_transit']);

        return $this->serializeRun($run->fresh(['originBranch', 'stops.branch', 'items', 'rider', 'manifests.fromBranch', 'manifests.toBranch']));
    }

    public function cancelRun(TransferRun $run, ?string $reason, ?int $actorId): array
    {
        if (in_array($run->status, [TransferRun::STATUS_COMPLETED, TransferRun::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages(['status' => ['This TR run is already closed.']]);
        }
        foreach ($run->manifests()->where('status', DispatchManifest::STATUS_OPEN)->get() as $open) {
            $this->containers->cancel($open, $reason ?: 'TMS run cancelled', $actorId);
        }
        $run->update([
            'status' => TransferRun::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancelled_by' => $actorId,
            'cancel_reason' => $reason,
        ]);
        $run->items()->whereNotIn('status', ['received'])->update(['status' => 'cancelled']);

        return $this->serializeRun($run->fresh(['originBranch', 'stops.branch', 'items', 'rider', 'manifests']));
    }

    public function continueRun(TransferRun $run, ?int $actorId): array
    {
        $currentSeq = (int) $run->current_stop_sequence;
        $stops = $run->stops()->orderBy('sequence')->get();
        $current = $stops->firstWhere('sequence', $currentSeq) ?: $stops->firstWhere('kind', '!=', 'origin');
        if (! $current) {
            throw ValidationException::withMessages(['status' => ['No current stop to continue from.']]);
        }
        $next = $stops->first(fn ($s) => (int) $s->sequence > (int) $current->sequence);
        if (! $next) {
            $run->update(['status' => TransferRun::STATUS_COMPLETED, 'completed_at' => now()]);
            return $this->serializeRun($run->fresh(['originBranch', 'stops.branch', 'items', 'rider', 'manifests']));
        }

        $downstreamIds = $stops->where('sequence', '>', $current->sequence)->pluck('branch_id')->all();
        $remainingIds = $run->items()->whereIn('drop_stop_branch_id', $downstreamIds)->whereNotIn('status', ['cancelled', 'skipped'])->pluck('shipment_id')->all();
        $shipments = Shipment::query()->whereIn('id', $remainingIds)->get();
        if ($shipments->isEmpty()) {
            $run->update(['status' => TransferRun::STATUS_COMPLETED, 'completed_at' => now()]);
            return $this->serializeRun($run->fresh(['originBranch', 'stops.branch', 'items', 'rider', 'manifests']));
        }

        $from = (int) $current->branch_id;
        $hop = (int) $next->branch_id;
        $pathTail = $stops->where('sequence', '>=', $current->sequence)->pluck('branch_id')->map(fn ($id) => (int) $id)->values()->all();
        $run->loadMissing('items');

        foreach ($shipments as $s) {
            $drop = (int) ($run->items->firstWhere('shipment_id', $s->id)?->drop_stop_branch_id ?? $hop);
            $destIndex = array_search($drop, $pathTail, true);
            $routePath = $destIndex === false ? [$from, $hop] : array_slice($pathTail, 0, (int) $destIndex + 1);
            if (count($routePath) < 2) {
                $routePath = [$from, $drop ?: $hop];
            }
            $route = $this->ensureRoute($routePath, strtolower((string) ($s->service_type ?? 'standard')) ?: 'standard');
            if ($route && Schema::hasColumn('shipments', 'transfer_route_id')) {
                $s->forceFill(['transfer_route_id' => $route->id, 'current_branch_id' => $from])->save();
            }
            $this->progress->applyProgressToShipment($s->fresh(), $from);
        }

        $dispatch = $this->containers->dispatch(
            $shipments->map(fn ($s) => $s->fresh()),
            $hop,
            $from,
            [
                'hold' => false,
                'vehicle_type' => $run->vehicle_type,
                'vehicle_number' => $run->vehicle_number,
                'rider_user_id' => $run->rider_user_id,
                'driver_name' => $run->driver_name,
                'driver_phone' => $run->driver_phone,
                'seal_number' => $run->seal_number,
                'notes' => 'Continuation of '.$run->run_number,
            ],
            $actorId,
        );
        $manifests = $dispatch['manifests'] ?? [];
        if ($manifests !== []) {
            $m = $manifests[0];
            $m->forceFill(['transfer_run_id' => $run->id, 'corridor_code' => $run->corridor_code, 'recommendation_why' => 'Next leg of '.$run->run_number])->save();
            $next->update(['dispatch_manifest_id' => $m->id]);
        }
        $current->update(['status' => 'departed', 'departed_at' => now()]);
        $run->update(['status' => TransferRun::STATUS_DISPATCHED, 'current_stop_sequence' => (int) $next->sequence]);

        return [
            'run' => $this->serializeRun($run->fresh(['originBranch', 'stops.branch', 'items', 'rider', 'manifests'])),
            'skipped' => $dispatch['skipped'] ?? [],
            'manifests' => array_map(fn ($m) => ['id' => $m->id, 'transfer_number' => $m->display_number, 'status' => $m->status], $manifests),
        ];
    }

    public function listRuns(?string $status, ?int $originBranchId, int $scopeBranchId): array
    {
        $q = TransferRun::query()
            ->with(['originBranch:id,name,code,city,latitude,longitude', 'stops.branch:id,name,code,city,latitude,longitude', 'rider:id,name,phone', 'manifests'])
            ->latest('id');
        if ($status && $status !== 'all') {
            if ($status === 'active') {
                $q->whereIn('status', TransferRun::ACTIVE_STATUSES);
            } else {
                $q->where('status', $status);
            }
        }
        if ($originBranchId) {
            $q->where('origin_branch_id', $originBranchId);
        }
        if ($scopeBranchId !== 0) {
            $q->where(function ($w) use ($scopeBranchId) {
                $w->where('origin_branch_id', $scopeBranchId)
                    ->orWhereHas('stops', fn ($s) => $s->where('branch_id', $scopeBranchId));
            });
        }

        return $q->limit(200)->get()->map(fn (TransferRun $r) => $this->serializeRun($r))->all();
    }

    public function serializeRun(TransferRun $run): array
    {
        $run->loadMissing(['originBranch', 'stops.branch', 'items', 'rider', 'manifests.fromBranch', 'manifests.toBranch']);
        $fill = $run->max_parcels ? round(($run->expected_count / max(1, $run->max_parcels)) * 100, 1) : null;
        $eta = null;
        if ($run->dispatched_at && $run->estimated_hours) {
            $eta = $run->dispatched_at->copy()->addMinutes((int) ($run->estimated_hours * 60));
        } elseif ($run->planned_departure_at && $run->estimated_hours) {
            $eta = $run->planned_departure_at->copy()->addMinutes((int) ($run->estimated_hours * 60));
        }
        $delayMinutes = null;
        if ($run->planned_departure_at && $run->dispatched_at) {
            $delayMinutes = (int) $run->planned_departure_at->diffInMinutes($run->dispatched_at, false);
        } elseif ($run->status === TransferRun::STATUS_DISPATCHED && $eta && now()->gt($eta)) {
            $delayMinutes = (int) $eta->diffInMinutes(now());
        }

        return [
            'id' => $run->id,
            'run_number' => $run->run_number,
            'status' => $run->status,
            'origin_branch_id' => $run->origin_branch_id,
            'origin_name' => $run->originBranch?->name,
            'origin_code' => $run->originBranch?->code,
            'vehicle_type' => $run->vehicle_type,
            'vehicle_number' => $run->vehicle_number,
            'rider_user_id' => $run->rider_user_id,
            'driver_name' => $run->rider?->name ?: $run->driver_name,
            'driver_phone' => $run->rider?->phone ?: $run->driver_phone,
            'seal_number' => $run->seal_number,
            'planned_departure_at' => $run->planned_departure_at?->toISOString(),
            'dispatched_at' => $run->dispatched_at?->toISOString(),
            'completed_at' => $run->completed_at?->toISOString(),
            'corridor_code' => $run->corridor_code,
            'tour_code' => $run->tour_code,
            'leg_role' => $run->leg_role,
            'recommendation_key' => $run->recommendation_key,
            'why' => $run->why,
            'estimated_km' => $run->estimated_km,
            'estimated_hours' => $run->estimated_hours,
            'eta' => $eta?->toISOString(),
            'delay_minutes' => $delayMinutes,
            'current_stop_sequence' => $run->current_stop_sequence,
            'expected_count' => $run->expected_count,
            'expected_weight_kg' => $run->expected_weight_kg,
            'max_parcels' => $run->max_parcels,
            'max_weight_kg' => $run->max_weight_kg,
            'fill_pct' => $fill,
            'service_types' => $run->service_types ?? [],
            'transport_cost' => $run->transport_cost,
            'cost_per_parcel' => ($run->transport_cost && $run->expected_count) ? round($run->transport_cost / $run->expected_count, 2) : null,
            'notes' => $run->notes,
            'stops' => $run->stops->map(fn (TransferRunStop $s) => [
                'id' => $s->id,
                'sequence' => $s->sequence,
                'branch_id' => $s->branch_id,
                'branch_name' => $s->branch?->name,
                'branch_code' => $s->branch?->code,
                'lat' => $s->branch ? (float) $s->branch->latitude : null,
                'lng' => $s->branch ? (float) $s->branch->longitude : null,
                'kind' => $s->kind,
                'status' => $s->status,
                'planned_arrival_at' => $s->planned_arrival_at?->toISOString(),
                'arrived_at' => $s->arrived_at?->toISOString(),
                'departed_at' => $s->departed_at?->toISOString(),
                'expected_parcels' => $s->expected_parcels,
                'received_parcels' => $s->received_parcels,
                'dispatch_manifest_id' => $s->dispatch_manifest_id,
            ])->values()->all(),
            'manifests' => $run->manifests->map(fn (DispatchManifest $m) => [
                'id' => $m->id,
                'transfer_number' => $m->display_number,
                'status' => $m->status,
                'from_branch_id' => $m->from_branch_id,
                'from_branch_name' => $m->fromBranch?->name,
                'to_branch_id' => $m->to_branch_id,
                'to_branch_name' => $m->toBranch?->name,
                'expected_count' => $m->expected_count,
                'received_count' => $m->received_count,
                'dispatched_at' => $m->dispatched_at?->toISOString(),
            ])->values()->all(),
        ];
    }

    public function ensureRoute(array $pathBranchIds, string $serviceType): ?BranchTransferRoute
    {
        $pathBranchIds = array_values(array_unique(array_map('intval', $pathBranchIds)));
        if (count($pathBranchIds) < 2) {
            return null;
        }
        $coverage = [];
        foreach ($pathBranchIds as $bid) {
            $cov = $this->progress->coverageIdForBranch($bid);
            if (! $cov) {
                return null;
            }
            $coverage[] = (int) $cov;
        }
        $coverage = array_values(array_unique($coverage));
        if (count($coverage) < 2) {
            return null;
        }
        $origin = $coverage[0];
        $dest = $coverage[count($coverage) - 1];
        $transit = array_slice($coverage, 1, -1);
        $code = 'TMS-'.implode('-', $pathBranchIds).'-'.strtoupper($serviceType);
        $existing = BranchTransferRoute::query()->where('route_code', $code)->first();
        if ($existing) {
            return $existing;
        }
        $candidates = BranchTransferRoute::query()
            ->where('is_active', true)
            ->where('origin_branch_id', $origin)
            ->where('destination_branch_id', $dest)
            ->where(function ($q) use ($serviceType) {
                $q->where('service_type', $serviceType)->orWhere('service_type', 'standard');
            })
            ->get();
        foreach ($candidates as $cand) {
            if ($cand->getPathBranchIds() === $coverage) {
                return $cand;
            }
        }
        $names = Branch::query()->whereIn('id', $pathBranchIds)->pluck('name', 'id');
        $label = collect($pathBranchIds)->map(fn ($id) => $names[$id] ?? ('#'.$id))->implode(' -> ');
        $km = $this->pathDistanceKm($pathBranchIds, $this->operationalBranches()->keyBy('id'));

        return BranchTransferRoute::query()->create([
            'route_code' => $code,
            'name' => $label,
            'origin_branch_id' => $origin,
            'destination_branch_id' => $dest,
            'transit_branch_ids' => $transit,
            'service_type' => $serviceType,
            'base_rate' => 0,
            'currency' => 'NPR',
            'distance_km' => $km,
            'estimated_hours' => max(1, (int) ceil($this->hoursFor($km, max(0, count($pathBranchIds) - 1), null))),
            'priority' => 80,
            'is_default' => false,
            'is_active' => true,
            'notes' => 'Created by TMS line-haul planning',
        ]);
    }

    private function waitingShipments(int $scopeBranchId, ?int $originBranchId, ?string $serviceType): Collection
    {
        $query = Shipment::query();
        $this->applyOutboundScope($query, $originBranchId ?: $scopeBranchId);
        if ($serviceType) {
            $query->where('service_type', $serviceType);
        }
        if (Schema::hasTable('dispatch_manifest_items')) {
            $query->whereNotIn('id', function ($q) {
                $q->select('dmi.shipment_id')
                    ->from('dispatch_manifest_items as dmi')
                    ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
                    ->whereIn('dmi.status', ['sent', 'dispatched', 'in_transit', 'draft', 'pending', 'added'])
                    ->whereIn('dm.status', ['open', 'draft', 'dispatched', 'in_transit', 'partially_received', 'pending', 'created']);
            });
        }
        return $query->get();
    }

    private function applyOutboundScope($query, int $branchId): void
    {
        $query->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
            ->where(function ($q) {
                $q->whereIn('status', [
                    CourierStatus::SORTED_FOR_TRANSFER,
                    CourierStatus::RECEIVED_AT_ORIGIN_BRANCH,
                    CourierStatus::RECEIVED_AT_ORIGIN_SUB_BRANCH,
                    CourierStatus::RECEIVED_AT_TRANSIT_HUB,
                    CourierStatus::PICKED_UP,
                ])->orWhere(function ($q2) {
                    $q2->where('status', CourierStatus::SORTED_FOR_DELIVERY)
                        ->where(function ($q3) {
                            $q3->whereNull('current_branch_id')
                                ->orWhereColumn('current_branch_id', '=', 'origin_branch_id');
                        });
                });
            });
        if ($branchId !== 0) {
            $query->where(function ($q) use ($branchId) {
                $q->where('current_branch_id', $branchId)
                    ->orWhere(function ($q2) use ($branchId) {
                        $q2->where('origin_branch_id', $branchId)
                            ->where(function ($q3) {
                                $q3->whereNull('current_branch_id')
                                    ->orWhereColumn('current_branch_id', 'origin_branch_id');
                            });
                    });
            });
            $query->where(function ($q) use ($branchId) {
                $q->whereNull('destination_branch_id')->orWhere('destination_branch_id', '!=', $branchId);
            });
        }
        PickupCustody::excludeAwaitingBranchReceive($query);
    }

    private function waitingAtBranchId(Shipment $shipment): int
    {
        $id = (int) ($shipment->current_branch_id ?: $shipment->origin_branch_id ?: 0);
        return (int) ($this->progress->resolveOperationalBranchId($id) ?? $id);
    }

    private function isSlaRisk(Shipment $shipment, mixed $waitingSince): bool
    {
        if ($shipment->estimated_delivery_time && Carbon::parse($shipment->estimated_delivery_time)->isPast()) {
            return true;
        }
        $service = strtolower((string) ($shipment->service_type ?? 'standard')) ?: 'standard';
        $limit = self::SLA_HOURS[$service] ?? 24;
        return $waitingSince ? Carbon::parse($waitingSince)->lt(now()->subHours($limit)) : false;
    }

    private function operationalBranches(): Collection
    {
        return Branch::query()
            ->whereIn('type', ['franchise_branch', 'head_branch', 'branch', 'delivery_hub'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    private function resolvedCorridors(): array
    {
        $out = [];
        $rows = TransferCorridor::query()->with('stops')->where('is_active', true)->orderBy('priority')->orderBy('code')->get();
        $byCode = $this->operationalBranches()->keyBy('code');
        foreach ($rows as $corridor) {
            $stops = [];
            foreach ($corridor->stops as $stop) {
                $branch = $byCode->get($stop->branch_code);
                if (! $branch) {
                    continue;
                }
                $stops[] = [
                    'sequence' => (int) $stop->sequence,
                    'branch_id' => (int) $branch->id,
                    'branch_code' => $branch->code,
                    'name' => $branch->name,
                    'lat' => (float) $branch->latitude,
                    'lng' => (float) $branch->longitude,
                    'distance_from_prev_km' => $stop->distance_from_prev_km,
                    'hours_from_prev' => $stop->hours_from_prev,
                ];
            }
            if (count($stops) < 2) {
                continue;
            }
            $out[] = [
                'id' => $corridor->id,
                'code' => $corridor->code,
                'name' => $corridor->name,
                'highway' => $corridor->highway,
                'avg_speed_kmh' => (int) $corridor->avg_speed_kmh,
                'dwell_minutes' => (int) $corridor->dwell_minutes,
                'stops' => $stops,
                'branch_ids' => array_map(fn ($s) => (int) $s['branch_id'], $stops),
            ];
        }
        return $out;
    }

    private function packRuns(int $originId, array $cand, array $cells): array
    {
        $branches = $this->operationalBranches()->keyBy('id');
        $origin = $branches->get($originId);
        $destIndex = [];
        foreach ($cand['downstream'] as $i => $bid) {
            $destIndex[(int) $bid] = $i;
        }
        $lastDropPos = 0;
        foreach ($cells as $c) {
            $lastDropPos = max($lastDropPos, $destIndex[(int) $c['destination_branch_id']] ?? 0);
        }
        $pathAfter = array_slice($cand['downstream'], 0, $lastDropPos + 1);
        $pathIds = [$originId, ...$pathAfter];

        $shipmentsMeta = [];
        foreach ($cells as $c) {
            foreach ($c['shipment_ids'] as $sid) {
                $shipmentsMeta[] = [
                    'id' => (int) $sid,
                    'dest' => (int) $c['destination_branch_id'],
                    'service' => $c['service_type'],
                    'weight' => $c['count'] > 0 ? $c['weight_kg'] / $c['count'] : 1,
                    'priority' => self::SERVICE_PRIORITY[$c['service_type']] ?? 1,
                    'sla' => $c['sla_risk_count'] > 0,
                ];
            }
        }
        usort($shipmentsMeta, fn ($a, $b) => [$b['sla'], $b['priority'], $a['id']] <=> [$a['sla'], $a['priority'], $b['id']]);

        $runs = [];
        foreach ($this->splitToVehicles($shipmentsMeta) as $i => $batch) {
            $ids = array_column($batch['items'], 'id');
            $byDest = [];
            $byService = [];
            $sla = 0;
            $express = 0;
            $weight = 0.0;
            foreach ($batch['items'] as $item) {
                $byDest[$item['dest']] = ($byDest[$item['dest']] ?? 0) + 1;
                $byService[$item['service']] = ($byService[$item['service']] ?? 0) + 1;
                $weight += $item['weight'];
                if ($item['sla']) {
                    $sla++;
                }
                if (($item['priority'] ?? 1) >= 2) {
                    $express++;
                }
            }
            $stops = [];
            foreach ($pathIds as $seq => $bid) {
                $b = $branches->get($bid);
                $stops[] = [
                    'sequence' => $seq,
                    'branch_id' => $bid,
                    'name' => $b?->name ?? ('Branch #'.$bid),
                    'code' => $b?->code,
                    'lat' => $b ? (float) $b->latitude : null,
                    'lng' => $b ? (float) $b->longitude : null,
                    'drop_count' => (int) ($byDest[$bid] ?? 0),
                    'kind' => $seq === 0 ? 'origin' : (($byDest[$bid] ?? 0) > 0 ? 'drop' : 'hub'),
                ];
            }
            $km = $this->pathDistanceKm($pathIds, $branches);
            $hours = $this->hoursFor($km, count($pathIds) - 1, $cand['corridor']['avg_speed_kmh'] ?? null);
            $runs[] = [
                'origin_branch_id' => $originId,
                'origin_name' => $origin?->name,
                'origin_code' => $origin?->code,
                'corridor_code' => $cand['corridor']['code'],
                'corridor_name' => $cand['corridor']['name'],
                'highway' => $cand['corridor']['highway'],
                'direction' => $cand['direction'],
                'mode' => count($pathIds) > 2 ? 'milk_run' : 'direct',
                'stops' => $stops,
                'stop_branch_ids' => $pathAfter,
                'parcels' => count($ids),
                'weight_kg' => round($weight, 2),
                'express_count' => $express,
                'sla_risk_count' => $sla,
                'by_stop' => collect($stops)->filter(fn ($s) => $s['kind'] !== 'origin' && $s['drop_count'] > 0)
                    ->map(fn ($s) => ['branch_id' => $s['branch_id'], 'name' => $s['name'], 'count' => $s['drop_count']])
                    ->values()->all(),
                'by_service' => collect($byService)->map(fn ($c, $k) => ['service_type' => $k, 'count' => $c])->values()->all(),
                'shipment_ids' => $ids,
                'vehicle_type' => $batch['vehicle_type'],
                'max_parcels' => $batch['max_parcels'],
                'max_kg' => $batch['max_kg'],
                'fill_pct' => $batch['fill_pct'],
                'fill_weight_pct' => $batch['fill_weight_pct'],
                'estimated_km' => $km,
                'estimated_hours' => $hours,
                'why' => $this->explain($cand, $origin, $stops, $batch, count($ids), $sla, $express, $i + 1, count($this->splitToVehicles($shipmentsMeta))),
                'why_points' => $this->whyPoints($cand, $stops, $batch, $sla, $express),
            ];
        }
        return $runs;
    }

    private function splitToVehicles(array $items): array
    {
        $weight = array_sum(array_column($items, 'weight'));
        $count = count($items);
        $choice = $this->chooseVehicle($count, $weight);
        if ($choice !== null) {
            return [[
                'items' => $items,
                'vehicle_type' => $choice['type'],
                'max_parcels' => $choice['max_parcels'],
                'max_kg' => $choice['max_kg'],
                'fill_pct' => round($count / max(1, $choice['max_parcels']) * 100, 1),
                'fill_weight_pct' => round($weight / max(0.1, $choice['max_kg']) * 100, 1),
            ]];
        }
        $truck = TransferContainerService::VEHICLE_CAPACITIES['truck'];
        $batches = [];
        $chunk = [];
        $chunkW = 0.0;
        foreach ($items as $item) {
            if ($chunk !== [] && (count($chunk) + 1 > $truck['max_parcels'] || $chunkW + $item['weight'] > $truck['max_kg'])) {
                $batches[] = $this->batchFrom($chunk, $chunkW, $truck);
                $chunk = [];
                $chunkW = 0.0;
            }
            $chunk[] = $item;
            $chunkW += $item['weight'];
        }
        if ($chunk !== []) {
            $fit = $this->chooseVehicle(count($chunk), $chunkW) ?? ['type' => 'truck', 'max_parcels' => $truck['max_parcels'], 'max_kg' => $truck['max_kg']];
            $batches[] = $this->batchFrom($chunk, $chunkW, $fit);
        }
        return $batches;
    }

    private function batchFrom(array $chunk, float $weight, array $cap): array
    {
        $type = $cap['type'] ?? 'truck';
        $maxP = (int) ($cap['max_parcels'] ?? TransferContainerService::VEHICLE_CAPACITIES[$type]['max_parcels']);
        $maxW = (float) ($cap['max_kg'] ?? TransferContainerService::VEHICLE_CAPACITIES[$type]['max_kg']);
        return [
            'items' => $chunk,
            'vehicle_type' => $type,
            'max_parcels' => $maxP,
            'max_kg' => $maxW,
            'fill_pct' => round(count($chunk) / max(1, $maxP) * 100, 1),
            'fill_weight_pct' => round($weight / max(0.1, $maxW) * 100, 1),
        ];
    }

    private function chooseVehicle(int $count, float $weight): ?array
    {
        foreach (self::LINEHAUL_VEHICLES as $type) {
            $cap = TransferContainerService::VEHICLE_CAPACITIES[$type];
            if ($count <= $cap['max_parcels'] && $weight <= $cap['max_kg']) {
                return ['type' => $type, 'max_parcels' => $cap['max_parcels'], 'max_kg' => $cap['max_kg']];
            }
        }
        return null;
    }

    private function hubRouteLeftovers(array $cells, array $corridors, array &$assigned): array
    {
        $runs = [];
        foreach ($cells as $cell) {
            $ids = array_values(array_filter($cell['shipment_ids'], fn ($id) => ! isset($assigned[$id])));
            if ($ids === []) {
                continue;
            }
            $hubPath = $this->bestHubPath((int) $cell['origin_branch_id'], (int) $cell['destination_branch_id'], $corridors);
            if ($hubPath === null) {
                continue;
            }
            $fakeCand = [
                'corridor' => [
                    'code' => $hubPath['corridor_code'],
                    'name' => $hubPath['corridor_name'],
                    'highway' => $hubPath['highway'],
                    'avg_speed_kmh' => $hubPath['avg_speed_kmh'],
                ],
                'direction' => 'hub',
                'downstream' => array_slice($hubPath['path'], 1),
                'path' => $hubPath['path'],
            ];
            $cell['shipment_ids'] = $ids;
            $cell['count'] = count($ids);
            foreach ($this->packRuns((int) $cell['origin_branch_id'], $fakeCand, [$cell]) as $run) {
                $run['mode'] = 'hub';
                $run['why'] = 'Direct volume on this lane is not on a single trunk corridor. Routed via transit hub '.$hubPath['hub_name'].' ('.$hubPath['corridor_name'].'). '.$run['why'];
                $runs[] = $run;
            }
        }
        return $runs;
    }

    private function bestHubPath(int $origin, int $dest, array $corridors): ?array
    {
        $best = null;
        $branches = $this->operationalBranches()->keyBy('id');
        foreach ($corridors as $c1) {
            foreach ([$c1['branch_ids'], array_reverse($c1['branch_ids'])] as $pathFromOrigin) {
                $oi = array_search($origin, $pathFromOrigin, true);
                if ($oi === false) {
                    continue;
                }
                foreach (array_slice($pathFromOrigin, (int) $oi + 1) as $hub) {
                    if ((int) $hub === $dest) {
                        continue;
                    }
                    foreach ($corridors as $c2) {
                        foreach ([$c2['branch_ids'], array_reverse($c2['branch_ids'])] as $pathToDest) {
                            $hi = array_search((int) $hub, $pathToDest, true);
                            $di = array_search($dest, $pathToDest, true);
                            if ($hi === false || $di === false || $di <= $hi) {
                                continue;
                            }
                            $path = array_merge(
                                array_slice($pathFromOrigin, (int) $oi, (int) array_search((int) $hub, $pathFromOrigin, true) - (int) $oi + 1),
                                array_slice($pathToDest, (int) $hi + 1, (int) $di - (int) $hi)
                            );
                            $path = array_values(array_unique(array_map('intval', $path)));
                            if (count($path) < 3) {
                                continue;
                            }
                            $km = $this->pathDistanceKm($path, $branches);
                            if ($best === null || $km < $best['km']) {
                                $best = [
                                    'path' => $path,
                                    'km' => $km,
                                    'hub' => (int) $hub,
                                    'hub_name' => $branches->get((int) $hub)?->name ?? ('Branch #'.$hub),
                                    'corridor_code' => $c1['code'].'+'.$c2['code'],
                                    'corridor_name' => $c1['name'].' then '.$c2['name'],
                                    'highway' => trim((string) $c1['highway'].' / '.$c2['highway'], ' /'),
                                    'avg_speed_kmh' => (int) min($c1['avg_speed_kmh'], $c2['avg_speed_kmh']),
                                ];
                            }
                        }
                    }
                }
            }
        }
        return $best;
    }

    private function directRun(array $cell, array $ids): ?array
    {
        if ($ids === []) {
            return null;
        }
        $branches = $this->operationalBranches()->keyBy('id');
        $origin = $branches->get($cell['origin_branch_id']);
        $dest = $branches->get($cell['destination_branch_id']);
        $ratio = $cell['count'] > 0 ? count($ids) / $cell['count'] : 1;
        $weight = round($cell['weight_kg'] * $ratio, 2);
        $items = array_map(fn ($id) => [
            'id' => $id,
            'dest' => $cell['destination_branch_id'],
            'service' => $cell['service_type'],
            'weight' => $cell['count'] > 0 ? $cell['weight_kg'] / $cell['count'] : 1,
            'priority' => self::SERVICE_PRIORITY[$cell['service_type']] ?? 1,
            'sla' => $cell['sla_risk_count'] > 0,
        ], $ids);
        $batch = $this->splitToVehicles($items)[0];
        $pathIds = [(int) $cell['origin_branch_id'], (int) $cell['destination_branch_id']];
        $km = $this->pathDistanceKm($pathIds, $branches);
        $hours = $this->hoursFor($km, 1, null);
        $stops = [
            ['sequence' => 0, 'branch_id' => (int) $cell['origin_branch_id'], 'name' => $origin?->name, 'code' => $origin?->code, 'lat' => $origin ? (float) $origin->latitude : null, 'lng' => $origin ? (float) $origin->longitude : null, 'drop_count' => 0, 'kind' => 'origin'],
            ['sequence' => 1, 'branch_id' => (int) $cell['destination_branch_id'], 'name' => $dest?->name, 'code' => $dest?->code, 'lat' => $dest ? (float) $dest->latitude : null, 'lng' => $dest ? (float) $dest->longitude : null, 'drop_count' => count($ids), 'kind' => 'drop'],
        ];
        return [
            'origin_branch_id' => (int) $cell['origin_branch_id'],
            'origin_name' => $origin?->name,
            'origin_code' => $origin?->code,
            'corridor_code' => 'DIRECT',
            'corridor_name' => 'Point-to-point',
            'highway' => null,
            'direction' => 'direct',
            'mode' => 'direct',
            'stops' => $stops,
            'stop_branch_ids' => [(int) $cell['destination_branch_id']],
            'parcels' => count($ids),
            'weight_kg' => $weight,
            'express_count' => in_array($cell['service_type'], ['express', 'same_day', 'flight'], true) ? count($ids) : 0,
            'sla_risk_count' => (int) round($cell['sla_risk_count'] * $ratio),
            'by_stop' => [['branch_id' => (int) $cell['destination_branch_id'], 'name' => $dest?->name, 'count' => count($ids)]],
            'by_service' => [['service_type' => $cell['service_type'], 'count' => count($ids)]],
            'shipment_ids' => $ids,
            'vehicle_type' => $batch['vehicle_type'],
            'max_parcels' => $batch['max_parcels'],
            'max_kg' => $batch['max_kg'],
            'fill_pct' => $batch['fill_pct'],
            'fill_weight_pct' => $batch['fill_weight_pct'],
            'estimated_km' => $km,
            'estimated_hours' => $hours,
            'why' => sprintf('No shared Nepal trunk corridor matched this lane, so a direct %s run from %s to %s is suggested for %d parcels (fill %s%%).', $batch['vehicle_type'], $origin?->name ?? 'origin', $dest?->name ?? 'destination', count($ids), $batch['fill_pct']),
            'why_points' => ['Point-to-point leftover lane', 'Vehicle: '.$batch['vehicle_type']],
        ];
    }

    private function explain(array $cand, ?Branch $origin, array $stops, array $batch, int $parcels, int $sla, int $express, int $part, int $parts): string
    {
        $drops = array_values(array_filter($stops, fn ($s) => ($s['kind'] ?? '') === 'drop'));
        $dropText = collect($drops)->map(fn ($s) => ($s['name'] ?? 'stop').' ('.$s['drop_count'].')')->implode(', ');
        $mode = count($drops) > 1
            ? 'Consolidated into a multi-stop milk run along '.$cand['corridor']['name']
            : 'Direct trunk run on '.$cand['corridor']['name'];
        $highway = ! empty($cand['corridor']['highway']) ? ' ('.$cand['corridor']['highway'].')' : '';
        $prio = ($express || $sla) ? sprintf(' Express/SLA-risk parcels were packed first (%d express, %d SLA-risk).', $express, $sla) : '';
        $split = $parts > 1 ? " Vehicle {$part} of {$parts} because the load exceeds a single smaller vehicle." : '';
        $capNote = ($parcels > 50 && $batch['vehicle_type'] === 'pickup') ? ' A van (50 parcels) is too small, so a pickup is used.' : '';
        $km = $this->pathDistanceKmFromStops($stops);
        $hours = $this->hoursFor($km, max(1, count($stops) - 1), $cand['corridor']['avg_speed_kmh'] ?? null);
        return sprintf('%s%s from %s, dropping at %s. %d parcels on a %s at %s%% fill (capacity %d). Est. %s km / %s h.%s%s%s', $mode, $highway, $origin?->name ?? 'origin', $dropText ?: 'downstream stops', $parcels, $batch['vehicle_type'], $batch['fill_pct'], $batch['max_parcels'], $km, $hours, $prio, $capNote, $split);
    }

    private function whyPoints(array $cand, array $stops, array $batch, int $sla, int $express): array
    {
        $points = ['Corridor: '.($cand['corridor']['name'] ?? $cand['corridor']['code'])];
        if (! empty($cand['corridor']['highway'])) {
            $points[] = 'Highway: '.$cand['corridor']['highway'];
        }
        $drops = array_values(array_filter($stops, fn ($s) => ($s['kind'] ?? '') === 'drop'));
        $points[] = count($drops) > 1
            ? 'Milk-run consolidation of '.count($drops).' destinations on the same corridor'
            : 'Direct lane on this corridor';
        $points[] = sprintf('Vehicle %s ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â %s%% parcel fill, %s%% weight fill', $batch['vehicle_type'], $batch['fill_pct'], $batch['fill_weight_pct']);
        if ($express) {
            $points[] = $express.' express / same-day parcels packed first';
        }
        if ($sla) {
            $points[] = $sla.' parcels already past the service-type waiting cut-off (SLA risk)';
        }
        return $points;
    }

    private function pathDistanceKm(array $pathIds, Collection $branches): float
    {
        $km = 0.0;
        for ($i = 1; $i < count($pathIds); $i++) {
            $a = $branches->get($pathIds[$i - 1]);
            $b = $branches->get($pathIds[$i]);
            if (! $a || ! $b || ! $a->latitude || ! $b->latitude) {
                $km += 80;
                continue;
            }
            $km += $this->haversine((float) $a->latitude, (float) $a->longitude, (float) $b->latitude, (float) $b->longitude) * self::ROAD_FACTOR;
        }
        return round($km, 1);
    }

    private function pathDistanceKmFromStops(array $stops): float
    {
        $km = 0.0;
        for ($i = 1; $i < count($stops); $i++) {
            if (empty($stops[$i - 1]['lat']) || empty($stops[$i]['lat'])) {
                $km += 80;
                continue;
            }
            $km += $this->haversine((float) $stops[$i - 1]['lat'], (float) $stops[$i - 1]['lng'], (float) $stops[$i]['lat'], (float) $stops[$i]['lng']) * self::ROAD_FACTOR;
        }
        return round($km, 1);
    }

    private function hoursFor(float $km, int $stopCount, ?int $speed): float
    {
        $speed = (float) ($speed ?: self::DEFAULT_SPEED_KMH);
        return round(($km / max(15.0, $speed)) + (max(0, $stopCount) * (20 / 60)), 1);
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }

    private function findRecommendation(string $key, int $originId, int $scopeBranchId, array $payload): ?array
    {
        $recs = $this->recommend($originId ?: null, $payload['service_type'] ?? null, $scopeBranchId);
        foreach ($recs['recommendations'] as $rec) {
            if (($rec['key'] ?? '') === $key) {
                return $rec;
            }
        }
        return null;
    }

    private function nextRunNumber(int $fromBranchId): string
    {
        $branch = Branch::query()->find($fromBranchId);
        $code = (string) ($branch?->code ?? '');
        $tag = preg_replace('/^NP-BR-/i', '', $code) ?: ('B'.$fromBranchId);
        $tag = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', (string) $tag));
        $prefix = 'TRR-'.$tag.'-';
        $last = TransferRun::query()->where('run_number', 'like', $prefix.'%')->orderByDesc('id')->value('run_number');
        $seq = 1;
        if (is_string($last) && preg_match('/(\d+)$/', $last, $m)) {
            $seq = (int) $m[1] + 1;
        }
        return $prefix.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    private function manualWhy(int $originId, array $stopIds, int $count, Collection $branches): string
    {
        $origin = $branches->get($originId)?->name ?? 'origin';
        $stops = collect($stopIds)->map(fn ($id) => $branches->get($id)?->name ?? ('#'.$id))->implode(' -> ');
        return "Manual TR run planned by transport manager: {$count} parcels from {$origin} via {$stops}.";
    }

    private function pendingRequests(int $scopeBranchId, ?int $originBranchId): array
    {
        if (! Schema::hasTable('transfer_requests') || ! class_exists(TransferRequest::class)) {
            return [];
        }
        $q = TransferRequest::query();
        if (Schema::hasColumn('transfer_requests', 'from_branch_id')) {
            $q->with(['fromBranch:id,name,code,city', 'toBranch:id,name,code,city']);
        }
        if (Schema::hasColumn('transfer_requests', 'status')) {
            $q->whereIn('status', ['pending', 'open', 'requested', 'approved']);
        }
        if ($originBranchId && Schema::hasColumn('transfer_requests', 'from_branch_id')) {
            $q->where('from_branch_id', $originBranchId);
        } elseif ($scopeBranchId !== 0 && Schema::hasColumn('transfer_requests', 'from_branch_id')) {
            $q->where(function ($w) use ($scopeBranchId) {
                $w->where('from_branch_id', $scopeBranchId)->orWhere('to_branch_id', $scopeBranchId);
            });
        }
        return $q->latest('id')->limit(100)->get()->map(function ($r) {
            return [
                'id' => $r->id,
                'from_branch_id' => $r->from_branch_id ?? null,
                'from_branch_name' => $r->fromBranch?->name,
                'to_branch_id' => $r->to_branch_id ?? null,
                'to_branch_name' => $r->toBranch?->name,
                'service_type' => $r->service_type ?? $r->preferred_service_type ?? null,
                'vehicle_type' => $r->vehicle_type ?? null,
                'parcel_count' => $r->parcel_count ?? $r->expected_count ?? null,
                'status' => $r->status ?? 'pending',
                'notes' => $r->notes ?? $r->remark ?? null,
                'created_at' => optional($r->created_at)->toISOString(),
            ];
        })->all();
    }

    private function branchPayload(Branch $b): array
    {
        return [
            'id' => $b->id,
            'name' => $b->name,
            'code' => $b->code,
            'city' => $b->city,
            'lat' => $b->latitude !== null ? (float) $b->latitude : null,
            'lng' => $b->longitude !== null ? (float) $b->longitude : null,
        ];
    }

    /**
     * Daily fleet plan: capacity-split vehicles, hub cross-docking, and opposite-direction backhauls.
     *
     * @return array<string, mixed>
     */
    public function planFleet(?int $originBranchId, ?string $serviceType, int $scopeBranchId): array
    {
        $demand = $this->demand($originBranchId, $serviceType, null, $scopeBranchId);
        $assigned = [];
        $runs = [];
        $seq = 1;
        $corridors = $this->resolvedCorridors();

        foreach ($this->crossDockPlans($demand, $corridors, $assigned) as $run) {
            foreach ($run['shipment_ids'] as $sid) {
                $assigned[(int) $sid] = true;
            }
            $run['key'] = sprintf('REC-%03d', $seq++);
            $runs[] = $run;
        }

        $cellsByOrigin = [];
        foreach ($demand['cells'] as $cell) {
            $ids = array_values(array_filter($cell['shipment_ids'], fn ($id) => ! isset($assigned[$id])));
            if ($ids === []) {
                continue;
            }
            $ratio = $cell['count'] > 0 ? count($ids) / $cell['count'] : 0;
            $cell['shipment_ids'] = $ids;
            $cell['count'] = count($ids);
            $cell['weight_kg'] = round($cell['weight_kg'] * $ratio, 2);
            $cell['sla_risk_count'] = (int) round($cell['sla_risk_count'] * $ratio);
            $cellsByOrigin[$cell['origin_branch_id']][] = $cell;
        }

        foreach ($cellsByOrigin as $originId => $originCells) {
            $candidates = [];
            foreach ($corridors as $corridor) {
                foreach (['forward', 'reverse'] as $dir) {
                    $path = $dir === 'forward' ? $corridor['branch_ids'] : array_reverse($corridor['branch_ids']);
                    $idx = array_search((int) $originId, $path, true);
                    if ($idx === false) {
                        continue;
                    }
                    $downstream = array_slice($path, (int) $idx + 1);
                    if ($downstream === []) {
                        continue;
                    }
                    $matched = array_values(array_filter(
                        $originCells,
                        fn ($c) => in_array((int) $c['destination_branch_id'], $downstream, true)
                    ));
                    if ($matched === []) {
                        continue;
                    }
                    $candidates[] = [
                        'corridor' => $corridor,
                        'direction' => $dir,
                        'path' => $path,
                        'downstream' => $downstream,
                        'cells' => $matched,
                        'parcels' => array_sum(array_column($matched, 'count')),
                        'dest_count' => count(array_unique(array_column($matched, 'destination_branch_id'))),
                    ];
                }
            }
            usort($candidates, fn ($a, $b) => [$b['dest_count'], $b['parcels'], $a['corridor']['code']] <=> [$a['dest_count'], $a['parcels'], $b['corridor']['code']]);

            foreach ($candidates as $cand) {
                $available = [];
                foreach ($cand['cells'] as $c) {
                    $ids = array_values(array_filter($c['shipment_ids'], fn ($id) => ! isset($assigned[$id])));
                    if ($ids === []) {
                        continue;
                    }
                    $ratio = $c['count'] > 0 ? count($ids) / $c['count'] : 0;
                    $c['shipment_ids'] = $ids;
                    $c['count'] = count($ids);
                    $c['weight_kg'] = round($c['weight_kg'] * $ratio, 2);
                    $c['sla_risk_count'] = (int) round($c['sla_risk_count'] * $ratio);
                    $available[] = $c;
                }
                if ($available === []) {
                    continue;
                }
                foreach ($this->packRuns((int) $originId, $cand, $available) as $run) {
                    foreach ($run['shipment_ids'] as $sid) {
                        $assigned[(int) $sid] = true;
                    }
                    $run['key'] = sprintf('REC-%03d', $seq++);
                    $run['leg_role'] = $run['leg_role'] ?? 'one_way';
                    $runs[] = $run;
                }
            }
        }

        $leftoverCells = [];
        foreach ($demand['cells'] as $cell) {
            $ids = array_values(array_filter($cell['shipment_ids'], fn ($id) => ! isset($assigned[$id])));
            if ($ids === []) {
                continue;
            }
            $ratio = $cell['count'] > 0 ? count($ids) / $cell['count'] : 0;
            $cell['shipment_ids'] = $ids;
            $cell['count'] = count($ids);
            $cell['weight_kg'] = round($cell['weight_kg'] * $ratio, 2);
            $cell['sla_risk_count'] = (int) round($cell['sla_risk_count'] * $ratio);
            $leftoverCells[] = $cell;
        }
        foreach ($this->hubRouteLeftovers($leftoverCells, $corridors, $assigned) as $run) {
            foreach ($run['shipment_ids'] as $sid) {
                $assigned[(int) $sid] = true;
            }
            $run['key'] = sprintf('REC-%03d', $seq++);
            $run['leg_role'] = $run['leg_role'] ?? 'one_way';
            $runs[] = $run;
        }
        foreach ($demand['cells'] as $cell) {
            $ids = array_values(array_filter($cell['shipment_ids'], fn ($id) => ! isset($assigned[$id])));
            if ($ids === []) {
                continue;
            }
            $direct = $this->directRun($cell, $ids);
            if ($direct === null) {
                continue;
            }
            foreach ($direct['shipment_ids'] as $sid) {
                $assigned[(int) $sid] = true;
            }
            $direct['key'] = sprintf('REC-%03d', $seq++);
            $direct['leg_role'] = 'one_way';
            $runs[] = $direct;
        }

        $unassigned = [];
        foreach ($demand['cells'] as $cell) {
            $ids = array_values(array_filter($cell['shipment_ids'], fn ($id) => ! isset($assigned[$id])));
            if ($ids === []) {
                continue;
            }
            $cell['shipment_ids'] = $ids;
            $cell['count'] = count($ids);
            $unassigned[] = $cell;
        }

        $tours = $this->pairBackhauls($runs);
        $summary = $this->fleetSummary($tours, $runs, $demand, $unassigned);

        usort($runs, fn ($a, $b) => [$b['sla_risk_count'] ?? 0, $b['parcels'] ?? 0] <=> [$a['sla_risk_count'] ?? 0, $a['parcels'] ?? 0]);

        return [
            'recommendations' => $runs,
            'tours' => $tours,
            'plan' => $summary,
            'unassigned' => $unassigned,
            'totals' => [
                'suggested_runs' => count($runs),
                'vehicles_needed' => $summary['vehicles_needed'],
                'parcels_planned' => $summary['parcels_covered'],
                'parcels_unassigned' => $summary['parcels_left'],
                'avg_fill_pct' => $summary['avg_fill_pct'],
                'empty_km_avoided' => $summary['empty_km_avoided'],
            ],
        ];
    }

    /**
     * Accept several recommended (or edited) runs in one daily plan.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{created: list<array<string, mixed>>, errors: array<string, string>}
     */
    public function createRuns(array $items, ?int $actorId, int $scopeBranchId): array
    {
        $created = [];
        $errors = [];
        foreach ($items as $i => $item) {
            $key = (string) ($item['recommendation_key'] ?? $item['key'] ?? ('item-'.$i));
            try {
                $created[] = $this->createRun($item, $actorId, $scopeBranchId);
            } catch (ValidationException $e) {
                $errors[$key] = collect($e->errors())->flatten()->first() ?? $e->getMessage();
            } catch (\Throwable $e) {
                $errors[$key] = $e->getMessage();
            }
        }

        return ['created' => $created, 'errors' => $errors];
    }

    /**
     * Where corridors intersect at a transit hub, split long milk runs into
     * origin-to-hub feeders and a merged onward fleet from the hub.
     *
     * @param  array<string, mixed>  $demand
     * @param  list<array<string, mixed>>  $corridors
     * @param  array<int, bool>  $assigned
     * @return list<array<string, mixed>>
     */
    private function crossDockPlans(array $demand, array $corridors, array &$assigned): array
    {
        $hubCodes = ['NP-BR-HETAU', 'NP-BR-BUTWA', 'NP-BR-KATHM', 'NP-BR-POKHA'];
        $branches = $this->operationalBranches()->keyBy('id');
        $hubs = $branches->filter(fn (Branch $b) => in_array((string) $b->code, $hubCodes, true))->sortBy(fn (Branch $b) => array_search((string) $b->code, $hubCodes, true));
        $runs = [];
        $usedOnward = [];

        foreach ($hubs as $hub) {
            $hubId = (int) $hub->id;
            foreach ($corridors as $corridor) {
                foreach (['forward', 'reverse'] as $dir) {
                    $path = $dir === 'forward' ? $corridor['branch_ids'] : array_reverse($corridor['branch_ids']);
                    $hi = array_search($hubId, $path, true);
                    if ($hi === false) {
                        continue;
                    }
                    $downstream = array_slice($path, (int) $hi + 1);
                    if ($downstream === []) {
                        continue;
                    }
                    $onwardKey = $hubId.'|'.implode(',', $downstream);
                    if (isset($usedOnward[$onwardKey])) {
                        continue;
                    }

                    $sourceCells = []; // originId => list of cells (dest at hub or downstream)
                    $feederPaths = [];
                    $onwardOrigins = [];

                    foreach ($demand['cells'] as $cell) {
                        $ids = array_values(array_filter($cell['shipment_ids'], fn ($id) => ! isset($assigned[$id])));
                        if ($ids === []) {
                            continue;
                        }
                        $origin = (int) $cell['origin_branch_id'];
                        $dest = (int) $cell['destination_branch_id'];
                        $finalAtHub = $dest === $hubId;
                        $beyond = in_array($dest, $downstream, true);
                        if (! $finalAtHub && ! $beyond) {
                            continue;
                        }
                        if ($origin === $hubId) {
                            if (! $beyond) {
                                continue;
                            }
                            $c = $cell;
                            $c['shipment_ids'] = $ids;
                            $c['count'] = count($ids);
                            $sourceCells[$origin][] = $c;
                            $onwardOrigins[$origin] = true;
                            continue;
                        }
                        $pathToHub = $this->pathBetween($origin, $hubId, $corridors);
                        if ($pathToHub === null) {
                            continue;
                        }
                        $pathDirect = $this->pathBetween($origin, $dest, $corridors);
                        if ($pathDirect !== null && $dest !== $hubId) {
                            $hubToDest = $this->pathBetween($hubId, $dest, $corridors);
                            $via = array_values(array_unique(array_merge($pathToHub, $hubToDest ?? [$dest])));
                            if (count($pathDirect) <= count($via) && ! in_array($hubId, $pathDirect, true)) {
                                continue;
                            }
                        }
                        $c = $cell;
                        $c['shipment_ids'] = $ids;
                        $ratio = $cell['count'] > 0 ? count($ids) / $cell['count'] : 1;
                        $c['count'] = count($ids);
                        $c['weight_kg'] = round($cell['weight_kg'] * $ratio, 2);
                        $c['sla_risk_count'] = (int) round($cell['sla_risk_count'] * $ratio);
                        $sourceCells[$origin][] = $c;
                        $feederPaths[$origin] = $pathToHub;
                        if ($beyond) {
                            $onwardOrigins[$origin] = true;
                        }
                    }

                    if (count($onwardOrigins) < 2) {
                        continue;
                    }

                    $usedOnward[$onwardKey] = true;
                    $onwardCellsByDest = [];

                    foreach ($sourceCells as $originId => $cells) {
                        if ($originId === $hubId) {
                            foreach ($cells as $cell) {
                                $onwardCellsByDest[] = $cell;
                            }
                            continue;
                        }
                        $pathToHub = $feederPaths[$originId] ?? [$originId, $hubId];
                        $feederCells = [];
                        $transship = 0;
                        $finalHere = 0;
                        foreach ($cells as $cell) {
                            $dest = (int) $cell['destination_branch_id'];
                            if ($dest === $hubId) {
                                $finalHere += $cell['count'];
                                $feederCells[] = $cell;
                            } else {
                                $transship += $cell['count'];
                                $onwardCellsByDest[] = $cell;
                                $pseudo = $cell;
                                $pseudo['destination_branch_id'] = $hubId;
                                $pseudo['destination_name'] = $hub->name.' (cross-dock)';
                                $feederCells[] = $pseudo;
                            }
                        }
                        $cand = [
                            'corridor' => $corridor,
                            'direction' => $dir,
                            'downstream' => array_slice($pathToHub, 1),
                            'path' => $pathToHub,
                        ];
                        foreach ($this->packRuns((int) $originId, $cand, $feederCells) as $run) {
                            $run['mode'] = 'feeder';
                            $run['leg_role'] = 'feeder';
                            $run['cross_dock_hub_id'] = $hubId;
                            $run['cross_dock_hub_name'] = $hub->name;
                            $run['planned_ahead'] = false;
                            $run['why'] = sprintf(
                                'Feeder into %s cross-dock (%s). %d parcels stay here, %d transfer to an onward truck. %s',
                                $hub->name,
                                $corridor['name'],
                                $finalHere,
                                $transship,
                                $run['why']
                            );
                            $run['why_points'] = array_merge([
                                'Hub cross-dock at '.$hub->name,
                                $finalHere.' final drops + '.$transship.' transship',
                            ], $run['why_points'] ?? []);
                            $runs[] = $run;
                            foreach ($run['shipment_ids'] as $sid) {
                                $assigned[(int) $sid] = true;
                            }
                        }
                    }

                    if ($onwardCellsByDest === []) {
                        continue;
                    }
                    $onwardCand = [
                        'corridor' => $corridor,
                        'direction' => $dir,
                        'downstream' => $downstream,
                        'path' => array_merge([$hubId], $downstream),
                    ];
                    $sourceNote = collect(array_keys($onwardOrigins))->map(function ($oid) use ($branches) {
                        return $branches->get($oid)?->name ?? ('#'.$oid);
                    })->implode(', ');
                    foreach ($this->packRuns($hubId, $onwardCand, $onwardCellsByDest) as $i => $run) {
                        $run['mode'] = 'onward';
                        $run['leg_role'] = 'onward';
                        $run['cross_dock_hub_id'] = $hubId;
                        $run['cross_dock_hub_name'] = $hub->name;
                        $run['planned_ahead'] = true;
                        $run['why'] = sprintf(
                            'Onward truck %d from %s cross-dock, merging loads from %s onto %s. Load after inbound feeders arrive. %s',
                            $i + 1,
                            $hub->name,
                            $sourceNote,
                            $corridor['name'],
                            $run['why']
                        );
                        $run['why_points'] = array_merge([
                            'Cross-dock merge at '.$hub->name,
                            'Sources: '.$sourceNote,
                            'Planned until feeders arrive',
                        ], $run['why_points'] ?? []);
                        $runs[] = $run;
                            foreach ($run['shipment_ids'] as $sid) {
                                $assigned[(int) $sid] = true;
                            }
                    }
                }
            }
        }

        return $runs;
    }

    /**
     * @param  list<array<string, mixed>>  $corridors
     * @return list<int>|null
     */
    private function pathBetween(int $from, int $to, array $corridors): ?array
    {
        $best = null;
        foreach ($corridors as $corridor) {
            foreach ([$corridor['branch_ids'], array_reverse($corridor['branch_ids'])] as $path) {
                $a = array_search($from, $path, true);
                $b = array_search($to, $path, true);
                if ($a === false || $b === false || $b <= $a) {
                    continue;
                }
                $slice = array_values(array_map('intval', array_slice($path, (int) $a, (int) $b - (int) $a + 1)));
                if ($best === null || count($slice) < count($best)) {
                    $best = $slice;
                }
            }
        }
        return $best;
    }

    /**
     * Pair opposite-direction legs into round-trip vehicles to cut empty km.
     *
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function pairBackhauls(array &$runs): array
    {
        $n = count($runs);
        $pairs = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $km = $this->backhaulOverlapKm($runs[$i], $runs[$j]);
                if ($km > 1) {
                    $pairs[] = ['i' => $i, 'j' => $j, 'km' => $km];
                }
            }
        }
        usort($pairs, fn ($a, $b) => $b['km'] <=> $a['km']);

        $used = [];
        $tours = [];
        $tourSeq = 1;
        foreach ($pairs as $pair) {
            if (isset($used[$pair['i']]) || isset($used[$pair['j']])) {
                continue;
            }
            $a = $runs[$pair['i']];
            $b = $runs[$pair['j']];
            // Prefer the heavier / first-departing as outbound: earlier origin... use more parcels as outbound if it starts at the other's end? 
            // If A's last stop is B's origin, A is outbound.
            $aEnd = $this->runEndId($a);
            $bStart = (int) $b['origin_branch_id'];
            $outboundIdx = $aEnd === $bStart ? $pair['i'] : $pair['j'];
            $returnIdx = $outboundIdx === $pair['i'] ? $pair['j'] : $pair['i'];
            $out = $runs[$outboundIdx];
            $ret = $runs[$returnIdx];
            $need = max((int) $out['parcels'], (int) $ret['parcels']);
            $needW = max((float) $out['weight_kg'], (float) $ret['weight_kg']);
            $vehicle = $this->chooseVehicle($need, $needW) ?? ['type' => 'truck', 'max_parcels' => 800, 'max_kg' => 8000.0];
            $tourCode = sprintf('TOUR-%03d', $tourSeq++);
            $whyTour = sprintf(
                'Round trip / backhaul on the same vehicle: %s then %s. Avoids %.1f empty km of a deadhead return. Vehicle sized for the heavier leg (%s, %d parcels).',
                $this->runLabel($out),
                $this->runLabel($ret),
                $pair['km'],
                $vehicle['type'],
                $need
            );

            foreach ([$outboundIdx => 'outbound', $returnIdx => 'return'] as $idx => $role) {
                $runs[$idx]['tour_code'] = $tourCode;
                $runs[$idx]['tour_role'] = $role;
                $runs[$idx]['leg_role'] = $runs[$idx]['leg_role'] ?? $role;
                $runs[$idx]['empty_km_avoided'] = $role === 'return' ? $pair['km'] : 0;
                $runs[$idx]['vehicle_type'] = $vehicle['type'];
                $runs[$idx]['max_parcels'] = $vehicle['max_parcels'];
                $runs[$idx]['max_kg'] = $vehicle['max_kg'];
                $runs[$idx]['fill_pct'] = round(($runs[$idx]['parcels'] / max(1, $vehicle['max_parcels'])) * 100, 1);
                $runs[$idx]['why'] = $whyTour.' '.$runs[$idx]['why'];
                $runs[$idx]['why_points'] = array_merge([
                    'Paired '.$role.' on '.$tourCode,
                    sprintf('Empty-km avoided: %.1f km', $pair['km']),
                ], $runs[$idx]['why_points'] ?? []);
            }

            $used[$pair['i']] = true;
            $used[$pair['j']] = true;
            $tours[] = [
                'tour_code' => $tourCode,
                'kind' => 'round_trip',
                'vehicle_type' => $vehicle['type'],
                'max_parcels' => $vehicle['max_parcels'],
                'empty_km_avoided' => $pair['km'],
                'outbound_key' => $out['key'] ?? null,
                'return_key' => $ret['key'] ?? null,
                'outbound' => $this->runLabel($out),
                'return' => $this->runLabel($ret),
                'outbound_parcels' => $out['parcels'],
                'return_parcels' => $ret['parcels'],
                'outbound_fill_pct' => $runs[$outboundIdx]['fill_pct'],
                'return_fill_pct' => $runs[$returnIdx]['fill_pct'],
                'why' => $whyTour,
                'run_keys' => [$out['key'] ?? null, $ret['key'] ?? null],
            ];
        }

        foreach ($runs as $i => $run) {
            if (isset($used[$i])) {
                continue;
            }
            $tourCode = sprintf('TOUR-%03d', $tourSeq++);
            $runs[$i]['tour_code'] = $tourCode;
            $runs[$i]['tour_role'] = 'one_way';
            $runs[$i]['leg_role'] = $runs[$i]['leg_role'] ?? 'one_way';
            $runs[$i]['empty_km_avoided'] = 0;
            $tours[] = [
                'tour_code' => $tourCode,
                'kind' => 'one_way',
                'vehicle_type' => $run['vehicle_type'],
                'max_parcels' => $run['max_parcels'],
                'empty_km_avoided' => 0,
                'outbound_key' => $run['key'] ?? null,
                'return_key' => null,
                'outbound' => $this->runLabel($run),
                'return' => null,
                'outbound_parcels' => $run['parcels'],
                'return_parcels' => 0,
                'outbound_fill_pct' => $run['fill_pct'],
                'return_fill_pct' => null,
                'why' => 'No opposite-direction load was available to pair as a backhaul. '.$run['why'],
                'run_keys' => [$run['key'] ?? null],
            ];
        }

        return $tours;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function backhaulOverlapKm(array $a, array $b): float
    {
        // Do not pair two legs that share shipment ids or the same origin and direction.
        $idsA = $a['shipment_ids'] ?? [];
        $idsB = $b['shipment_ids'] ?? [];
        if (array_intersect($idsA, $idsB)) {
            return 0;
        }
        $aOrigin = (int) $a['origin_branch_id'];
        $bOrigin = (int) $b['origin_branch_id'];
        $aEnd = $this->runEndId($a);
        $bEnd = $this->runEndId($b);
        $aPath = $this->runPathIds($a);
        $bPath = $this->runPathIds($b);

        $compatible = false;
        // Perfect swap: A ends where B starts and B ends where A starts (or passes A's origin).
        if ($aEnd === $bOrigin && ($bEnd === $aOrigin || in_array($aOrigin, $bPath, true))) {
            $compatible = true;
        } elseif ($bEnd === $aOrigin && ($aEnd === $bOrigin || in_array($bOrigin, $aPath, true))) {
            $compatible = true;
        }
        if (! $compatible) {
            return 0;
        }
        // Opposite directions on the same corridor are preferred; reject same-direction pairs.
        if (($a['direction'] ?? '') !== '' && ($a['direction'] ?? '') === ($b['direction'] ?? '') && ($a['corridor_code'] ?? '') === ($b['corridor_code'] ?? '') && ($a['direction'] ?? '') !== 'hub') {
            return 0;
        }

        $branches = $this->operationalBranches()->keyBy('id');
        // Empty km avoided = shared reverse overlap, at least the connecting hub-to-hub stretch.
        if ($aEnd === $bOrigin && in_array($aOrigin, $bPath, true)) {
            return $this->pathDistanceKm($this->subpathTo($bPath, $bOrigin, $aOrigin) ?? [$bOrigin, $aOrigin], $branches);
        }
        if ($bEnd === $aOrigin && in_array($bOrigin, $aPath, true)) {
            return $this->pathDistanceKm($this->subpathTo($aPath, $aOrigin, $bOrigin) ?? [$aOrigin, $bOrigin], $branches);
        }
        return $this->pathDistanceKm([$aOrigin, $bOrigin], $branches);
    }

    /**
     * @param  list<int>  $path
     * @return list<int>|null
     */
    private function subpathTo(array $path, int $from, int $to): ?array
    {
        $a = array_search($from, $path, true);
        $b = array_search($to, $path, true);
        if ($a === false || $b === false || $b <= $a) {
            return null;
        }
        return array_values(array_map('intval', array_slice($path, (int) $a, (int) $b - (int) $a + 1)));
    }

    /** @param  array<string, mixed>  $run */
    private function runEndId(array $run): int
    {
        $stops = $run['stops'] ?? [];
        if ($stops !== []) {
            return (int) $stops[count($stops) - 1]['branch_id'];
        }
        $ids = $run['stop_branch_ids'] ?? [];
        return $ids !== [] ? (int) $ids[count($ids) - 1] : 0;
    }

    /**
     * @param  array<string, mixed>  $run
     * @return list<int>
     */
    private function runPathIds(array $run): array
    {
        $stops = $run['stops'] ?? [];
        if ($stops !== []) {
            return array_map(fn ($s) => (int) $s['branch_id'], $stops);
        }
        return array_values(array_filter([
            (int) ($run['origin_branch_id'] ?? 0),
            ...array_map('intval', $run['stop_branch_ids'] ?? []),
        ]));
    }

    /** @param  array<string, mixed>  $run */
    private function runLabel(array $run): string
    {
        $stops = collect($run['stops'] ?? [])->pluck('name')->filter()->implode(' -> ');
        if ($stops === '') {
            $stops = ($run['origin_name'] ?? 'origin').' -> '.collect($run['by_stop'] ?? [])->pluck('name')->implode(', ');
        }
        return sprintf('%s ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â· %d pcs ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â· %s', $stops, (int) ($run['parcels'] ?? 0), $run['vehicle_type'] ?? 'vehicle');
    }

    /**
     * @param  list<array<string, mixed>>  $tours
     * @param  list<array<string, mixed>>  $runs
     * @param  array<string, mixed>  $demand
     * @param  list<array<string, mixed>>  $unassigned
     * @return array<string, mixed>
     */
    private function fleetSummary(array $tours, array $runs, array $demand, array $unassigned): array
    {
        $roundTrips = array_values(array_filter($tours, fn ($t) => ($t['kind'] ?? '') === 'round_trip'));
        $oneWays = array_values(array_filter($tours, fn ($t) => ($t['kind'] ?? '') !== 'round_trip'));
        $fills = array_column($runs, 'fill_pct');
        $vehicles = [];
        foreach ($tours as $t) {
            $vehicles[$t['vehicle_type'] ?? 'other'] = ($vehicles[$t['vehicle_type'] ?? 'other'] ?? 0) + 1;
        }
        $covered = array_sum(array_column($runs, 'parcels'));
        // Feeder + onward can double-count the same parcel (feeder then onward). Unique coverage:
        $unique = [];
        foreach ($runs as $run) {
            foreach ($run['shipment_ids'] ?? [] as $id) {
                $unique[(int) $id] = true;
            }
        }
        $demandTotal = (int) ($demand['totals']['parcels'] ?? 0);

        return [
            'vehicles_needed' => count($tours),
            'vehicles_by_type' => $vehicles,
            'round_trips' => count($roundTrips),
            'one_way_runs' => count($oneWays),
            'legs' => count($runs),
            'avg_fill_pct' => $fills !== [] ? round(array_sum($fills) / count($fills), 1) : 0,
            'min_fill_pct' => $fills !== [] ? min($fills) : 0,
            'max_fill_pct' => $fills !== [] ? max($fills) : 0,
            'empty_km_avoided' => round(array_sum(array_column($tours, 'empty_km_avoided')), 1),
            'parcels_covered' => count($unique),
            'parcels_left' => array_sum(array_column($unassigned, 'count')),
            'demand_parcels' => $demandTotal,
            'coverage_pct' => $demandTotal > 0 ? round(count($unique) / $demandTotal * 100, 1) : 0,
            'cross_dock_legs' => count(array_filter($runs, fn ($r) => in_array($r['leg_role'] ?? '', ['feeder', 'onward'], true))),
        ];
    }

    /**
     * Persist a planned onward/future leg without opening a TR container yet
     * (parcels are still upstream on a feeder).
     *
     * @param  Collection<int, Shipment>  $shipments
     * @param  Collection<int, Branch>  $branches
     * @param  array<int, int>  $dropCounts
     * @param  list<string>  $services
     * @param  list<int>  $stopIds
     * @param  list<int>  $pathIds
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $caps
     */
    private function createPlannedRun(
        array $payload,
        ?int $actorId,
        int $originId,
        array $stopIds,
        array $pathIds,
        Collection $shipments,
        string $vehicleType,
        array $caps,
        array $dropCounts,
        float $weight,
        array $services,
        float $km,
        float $hours,
        Collection $branches
    ): array {
        $run = TransferRun::query()->create([
            'run_number' => $this->nextRunNumber($originId),
            'status' => TransferRun::STATUS_PLANNED,
            'origin_branch_id' => $originId,
            'vehicle_type' => $vehicleType,
            'vehicle_number' => $payload['vehicle_number'] ?? null,
            'rider_user_id' => $payload['rider_user_id'] ?? null,
            'driver_name' => $payload['driver_name'] ?? null,
            'driver_phone' => $payload['driver_phone'] ?? null,
            'seal_number' => $payload['seal_number'] ?? null,
            'planned_departure_at' => $payload['planned_departure_at'] ?? null,
            'corridor_code' => $payload['corridor_code'] ?? null,
            'tour_code' => $payload['tour_code'] ?? null,
            'leg_role' => $payload['leg_role'] ?? 'onward',
            'recommendation_key' => $payload['recommendation_key'] ?? null,
            'why' => $payload['why'] ?? 'Planned onward leg ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â load after inbound feeders arrive.',
            'estimated_km' => $km,
            'estimated_hours' => $hours,
            'current_stop_sequence' => 0,
            'transport_cost' => $payload['transport_cost'] ?? null,
            'expected_count' => $shipments->count(),
            'expected_weight_kg' => $weight,
            'max_parcels' => $caps['max_parcels'],
            'max_weight_kg' => $caps['max_kg'],
            'service_types' => $services,
            'created_by' => $actorId,
            'notes' => $payload['notes'] ?? 'Planned ahead: parcels will be at this hub once feeder TRs are received.',
        ]);
        $seq = 0;
        TransferRunStop::query()->create([
            'transfer_run_id' => $run->id,
            'sequence' => $seq++,
            'branch_id' => $originId,
            'kind' => 'origin',
            'status' => 'pending',
            'expected_parcels' => 0,
        ]);
        foreach ($stopIds as $bid) {
            TransferRunStop::query()->create([
                'transfer_run_id' => $run->id,
                'sequence' => $seq++,
                'branch_id' => $bid,
                'kind' => ($dropCounts[$bid] ?? 0) > 0 ? 'drop' : 'hub',
                'status' => 'pending',
                'expected_parcels' => (int) ($dropCounts[$bid] ?? 0),
            ]);
        }
        foreach ($shipments as $s) {
            $dest = (int) ($this->progress->resolveOperationalBranchId((int) $s->destination_branch_id) ?? $s->destination_branch_id);
            $drop = in_array($dest, $stopIds, true) ? $dest : $stopIds[count($stopIds) - 1];
            TransferRunItem::query()->create([
                'transfer_run_id' => $run->id,
                'shipment_id' => $s->id,
                'drop_stop_branch_id' => $drop,
                'service_type' => strtolower((string) ($s->service_type ?? 'standard')) ?: 'standard',
                'status' => 'planned',
            ]);
        }

        return [
            'run' => $this->serializeRun($run->fresh(['originBranch', 'stops.branch', 'items', 'rider', 'manifests'])),
            'manifests' => [],
            'skipped' => [],
            'planned_ahead' => true,
        ];
    }
}
