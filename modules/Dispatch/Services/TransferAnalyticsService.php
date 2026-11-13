<?php

declare(strict_types=1);

namespace Modules\Dispatch\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\Dispatch\Models\DispatchManifest;

final class TransferAnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function report(?string $from, ?string $to, int $scopeBranchId): array
    {
        $fromAt = $from ? Carbon::parse($from)->startOfDay() : now()->subDays(14)->startOfDay();
        $toAt = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        $manifestQ = DispatchManifest::query()
            ->where(function ($q) use ($fromAt, $toAt) {
                $q->whereBetween('dispatched_at', [$fromAt, $toAt])
                    ->orWhere(function ($q2) use ($fromAt, $toAt) {
                        $q2->whereNull('dispatched_at')->whereBetween('created_at', [$fromAt, $toAt]);
                    });
            });
        if ($scopeBranchId !== 0) {
            $manifestQ->where(function ($q) use ($scopeBranchId) {
                $q->where('from_branch_id', $scopeBranchId)->orWhere('to_branch_id', $scopeBranchId);
            });
        }
        $manifests = $manifestQ->with(['fromBranch:id,name,code', 'toBranch:id,name,code'])->get();

        $dispatched = $manifests->filter(fn ($m) => $m->dispatched_at !== null);
        $onTime = $dispatched->filter(function ($m) {
            if (! $m->planned_departure_at || ! $m->dispatched_at) {
                return false;
            }
            return $m->dispatched_at->lte($m->planned_departure_at->copy()->addMinutes(15));
        })->count();
        $plannedWithDepart = $dispatched->filter(fn ($m) => $m->planned_departure_at)->count();

        $fillRates = [];
        $costTotal = 0.0;
        $costParcels = 0;
        $dwellHours = [];
        $transitHours = [];
        foreach ($dispatched as $m) {
            $cap = (int) ($m->max_parcels ?: (TransferContainerService::VEHICLE_CAPACITIES[$m->vehicle_type ?? 'pickup']['max_parcels'] ?? 150));
            $cnt = (int) ($m->expected_count ?: 0);
            if ($cap > 0 && $cnt > 0) {
                $fillRates[] = $cnt / $cap * 100;
            }
            if ($m->transport_cost && $cnt) {
                $costTotal += (float) $m->transport_cost;
                $costParcels += $cnt;
            }
            if ($m->received_at && $m->dispatched_at) {
                $transitHours[] = $m->dispatched_at->diffInMinutes($m->received_at) / 60;
            }
        }

        $lane = [];
        $byService = [];
        $byBranchOut = [];
        $byBranchIn = [];
        $runsPerDay = [];
        foreach ($dispatched as $m) {
            $fromName = $m->fromBranch?->name ?? ('#'.$m->from_branch_id);
            $toName = $m->toBranch?->name ?? ('#'.$m->to_branch_id);
            $lk = $fromName.' -> '.$toName;
            $lane[$lk] = ($lane[$lk] ?? 0) + (int) ($m->expected_count ?: 0);
            $day = optional($m->dispatched_at)->toDateString() ?: optional($m->created_at)->toDateString();
            if ($day) {
                $runsPerDay[$day] = ($runsPerDay[$day] ?? 0) + 1;
            }
            $byBranchOut[$fromName] = ($byBranchOut[$fromName] ?? 0) + (int) ($m->expected_count ?: 0);
            $byBranchIn[$toName] = ($byBranchIn[$toName] ?? 0) + (int) ($m->expected_count ?: 0);
        }

        if (Schema::hasTable('dispatch_manifest_items') && $dispatched->isNotEmpty()) {
            $svcRows = DB::table('dispatch_manifest_items as dmi')
                ->join('shipments as s', 's.id', '=', 'dmi.shipment_id')
                ->whereIn('dmi.dispatch_manifest_id', $dispatched->pluck('id')->all())
                ->selectRaw("COALESCE(s.service_type, 'standard') as service_type, COUNT(*) as c")
                ->groupBy('service_type')
                ->get();
            foreach ($svcRows as $row) {
                $byService[$row->service_type] = (int) $row->c;
            }

            if (Schema::hasColumn('shipments', 'sorted_at')) {
                $dwellRows = DB::table('dispatch_manifest_items as dmi')
                    ->join('shipments as s', 's.id', '=', 'dmi.shipment_id')
                    ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
                    ->whereIn('dm.id', $dispatched->pluck('id')->all())
                    ->whereNotNull('dm.dispatched_at')
                    ->selectRaw('TIMESTAMPDIFF(MINUTE, COALESCE(s.sorted_at, s.created_at), dm.dispatched_at) as mins')
                    ->pluck('mins');
                foreach ($dwellRows as $mins) {
                    if ($mins !== null && $mins >= 0 && $mins < 60 * 24 * 21) {
                        $dwellHours[] = $mins / 60;
                    }
                }
            }
        }

        $slaBreaches = 0;
        if (Schema::hasColumn('shipments', 'estimated_delivery_time')) {
            $slaQ = DB::table('shipments')
                ->whereNotNull('estimated_delivery_time')
                ->where('estimated_delivery_time', '<', now())
                ->whereBetween('created_at', [$fromAt, $toAt])
                ->whereNotIn('status', [
                    'delivered',
                    'cancelled',
                    'rto_delivered',
                ]);
            if ($scopeBranchId !== 0) {
                $slaQ->where(function ($q) use ($scopeBranchId) {
                    $q->where('origin_branch_id', $scopeBranchId)
                        ->orWhere('current_branch_id', $scopeBranchId)
                        ->orWhere('destination_branch_id', $scopeBranchId);
                });
            }
            $slaBreaches = (int) $slaQ->count();
        }

        $ksort = function (array $map) {
            arsort($map);
            return collect($map)->map(fn ($v, $k) => ['name' => $k, 'count' => $v])->values()->all();
        };
        ksort($runsPerDay);

        return [
            'from' => $fromAt->toDateString(),
            'to' => $toAt->toDateString(),
            'kpis' => [
                'runs' => $dispatched->count(),
                'parcels_moved' => (int) $dispatched->sum(fn ($m) => (int) ($m->expected_count ?: 0)),
                'fill_rate_pct' => $fillRates !== [] ? round(array_sum($fillRates) / count($fillRates), 1) : null,
                'on_time_dispatch_pct' => $plannedWithDepart > 0 ? round($onTime / $plannedWithDepart * 100, 1) : null,
                'on_time_dispatch_n' => $onTime,
                'planned_departures' => $plannedWithDepart,
                'avg_dwell_hours' => $dwellHours !== [] ? round(array_sum($dwellHours) / count($dwellHours), 1) : null,
                'avg_transit_hours' => $transitHours !== [] ? round(array_sum($transitHours) / count($transitHours), 1) : null,
                'sla_breaches' => $slaBreaches,
                'cost_per_parcel' => $costParcels > 0 ? round($costTotal / $costParcels, 2) : null,
                'transport_cost_total' => $costParcels > 0 ? round($costTotal, 2) : null,
            ],
            'volume_by_lane' => $ksort($lane),
            'volume_by_service' => $ksort($byService),
            'volume_by_branch_outbound' => $ksort($byBranchOut),
            'volume_by_branch_inbound' => $ksort($byBranchIn),
            'runs_per_day' => collect($runsPerDay)->map(fn ($v, $k) => ['date' => $k, 'runs' => $v])->values()->all(),
            'transit_time_by_lane' => $this->transitByLane($dispatched),
        ];
    }

    /**
     * @return list<array{name: string, avg_hours: float, runs: int}>
     */
    private function transitByLane($dispatched): array
    {
        $acc = [];
        foreach ($dispatched as $m) {
            if (! $m->dispatched_at || ! $m->received_at) {
                continue;
            }
            $name = ($m->fromBranch?->name ?? '?').' -> '.($m->toBranch?->name ?? '?');
            $hours = $m->dispatched_at->diffInMinutes($m->received_at) / 60;
            $acc[$name]['sum'] = ($acc[$name]['sum'] ?? 0) + $hours;
            $acc[$name]['n'] = ($acc[$name]['n'] ?? 0) + 1;
        }
        $out = [];
        foreach ($acc as $name => $row) {
            $out[] = ['name' => $name, 'avg_hours' => round($row['sum'] / $row['n'], 1), 'runs' => $row['n']];
        }
        usort($out, fn ($a, $b) => $b['runs'] <=> $a['runs']);
        return $out;
    }

    public function toCsv(array $report): string
    {
        $lines = [];
        $lines[] = 'KPI,Value';
        foreach ($report['kpis'] as $k => $v) {
            $lines[] = $k.','.($v ?? '');
        }
        $lines[] = '';
        $lines[] = 'Lane,Parcels';
        foreach ($report['volume_by_lane'] as $row) {
            $lines[] = '"'.str_replace('"', "'", $row['name']).'",'.$row['count'];
        }
        $lines[] = '';
        $lines[] = 'Service type,Parcels';
        foreach ($report['volume_by_service'] as $row) {
            $lines[] = $row['name'].','.$row['count'];
        }
        $lines[] = '';
        $lines[] = 'Date,Runs';
        foreach ($report['runs_per_day'] as $row) {
            $lines[] = $row['date'].','.$row['runs'];
        }
        return implode("\n", $lines)."\n";
    }
}
