<?php

namespace Modules\Billing\Services;

use App\Support\FinanceBranchScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Models\ShipmentBranchShare;
use Modules\Dispatch\Services\ManifestHopService;
use Modules\Shipment\Models\Shipment;

/**
 * Read-only views over transfer delivery-charge settlement:
 *  - branchPosition(): what a branch owes / is owed, per counterparty branch
 *  - shipmentList():   one row per shipment with its per-branch split
 *  - shipmentBreakdown(): the full calculation for one shipment
 *
 * An obligation is a share row whose branch is not the collecting branch:
 * the collecting branch (payer) owes that branch (payee) its allocation.
 */
class InterBranchInsightService
{
    /** Buckets an obligation moves through, in order. */
    public const BUCKETS = ['awaiting_bill', 'unsettled', 'on_statement', 'paid', 'received'];

    public function __construct(
        private BranchShareService $shares,
        private ManifestHopService $hops,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Shared helpers
    |--------------------------------------------------------------------------
    */

    /** @param list<int> $shareIds @return array<int,true> */
    public function eligibleIds(array $shareIds): array
    {
        if ($shareIds === []) {
            return [];
        }

        return $this->shares->eligibleQuery()
            ->whereIn('shipment_branch_shares.id', $shareIds)
            ->pluck('shipment_branch_shares.id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /**
     * Settlement state of one share row.
     * kept_* = the collecting branch's own allocation (it already holds it).
     */
    public function shareState(object $share, ?string $statementStatus, bool $eligible): string
    {
        $isCollector = (int) $share->branch_id === (int) ($share->collecting_branch_id ?? 0);

        if ($isCollector) {
            return $share->status === 'settled' ? 'kept' : ($eligible ? 'kept' : 'awaiting_bill');
        }
        if ($share->statement_id) {
            return match ($statementStatus) {
                'paid' => 'paid',
                'received' => 'received',
                default => 'on_statement',
            };
        }
        if ($share->status === 'settled') {
            return 'received';
        }

        return $eligible ? 'unsettled' : 'awaiting_bill';
    }

    /** Overall state of a shipment from its rows' states. */
    public function shipmentState(array $states, array $collectorFlags): string
    {
        $others = [];
        foreach ($states as $i => $st) {
            if (! ($collectorFlags[$i] ?? false)) {
                $others[] = $st;
            }
        }
        if ($others === []) {
            // Single-branch (or only the collector): kept once the bill is paid.
            return in_array('awaiting_bill', $states, true) ? 'awaiting_bill' : 'settled';
        }
        if (in_array('awaiting_bill', $others, true)) {
            return 'awaiting_bill';
        }
        $done = array_filter($others, fn ($s) => $s === 'received');
        if (count($done) === count($others)) {
            return 'settled';
        }
        $onOrAfter = array_filter($others, fn ($s) => in_array($s, ['on_statement', 'paid', 'received'], true));
        if (count($onOrAfter) === count($others)) {
            $paid = array_filter($others, fn ($s) => in_array($s, ['paid', 'received'], true));

            return count($paid) === count($others) ? 'paid' : 'on_statement';
        }
        if ($onOrAfter !== []) {
            return 'partly_on_statement';
        }

        return 'unsettled';
    }

    private function branchNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? [] : DB::table('branches')->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    private static function dt($v): ?string
    {
        if (! $v) {
            return null;
        }

        return $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i:s') : (string) $v;
    }

    private static function money($v): float
    {
        return round((float) $v, 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Branch position (to pay / to receive per counterparty)
    |--------------------------------------------------------------------------
    */

    /**
     * @param  int|null  $branchId  null = all branches (HQ)
     */
    public function branchPosition(?int $branchId, ?string $from = null, ?string $to = null): array
    {
        $q = DB::table('shipment_branch_shares as s')
            ->join('shipments as sh', 'sh.id', '=', 's.shipment_id')
            ->leftJoin('inter_branch_statements as st', 'st.id', '=', 's.statement_id')
            ->whereNotNull('s.collecting_branch_id')
            ->whereColumn('s.branch_id', '!=', 's.collecting_branch_id')
            ->select([
                's.id', 's.shipment_id', 's.branch_id', 's.collecting_branch_id', 's.allocation_amount',
                's.status', 's.statement_id', 'st.status as statement_status', 'st.statement_number',
            ]);
        if ($from) {
            $q->whereDate('sh.delivered_at', '>=', $from);
        }
        if ($to) {
            $q->whereDate('sh.delivered_at', '<=', $to);
        }
        if ($branchId) {
            $q->where(fn ($w) => $w->where('s.branch_id', $branchId)->orWhere('s.collecting_branch_id', $branchId));
        }

        $rows = $q->get();
        $eligible = $this->eligibleIds($rows->pluck('id')->map(fn ($v) => (int) $v)->all());

        $empty = fn () => array_fill_keys(self::BUCKETS, 0.0) + ['due' => 0.0, 'shipments' => 0];

        // pair[payer][payee][bucket]
        $pairs = [];
        $pairShipments = [];
        foreach ($rows as $r) {
            $state = $this->shareState($r, $r->statement_status, isset($eligible[(int) $r->id]));
            $payer = (int) $r->collecting_branch_id;
            $payee = (int) $r->branch_id;
            $pairs[$payer][$payee] ??= $empty();
            $pairs[$payer][$payee][$state] = round($pairs[$payer][$payee][$state] + (float) $r->allocation_amount, 2);
            $pairShipments[$payer][$payee][(int) $r->shipment_id] = true;
        }
        foreach ($pairs as $payer => $list) {
            foreach ($list as $payee => $b) {
                $pairs[$payer][$payee]['due'] = round($b['unsettled'] + $b['on_statement'], 2);
                $pairs[$payer][$payee]['shipments'] = count($pairShipments[$payer][$payee]);
            }
        }

        $allIds = [];
        foreach ($pairs as $payer => $list) {
            $allIds[] = $payer;
            foreach (array_keys($list) as $payee) {
                $allIds[] = $payee;
            }
        }
        $names = $this->branchNames(array_merge($allIds, $branchId ? [$branchId] : []));

        $sumBuckets = function (array $list) use ($empty) {
            $t = $empty();
            foreach ($list as $b) {
                foreach (self::BUCKETS as $k) {
                    $t[$k] = round($t[$k] + $b[$k], 2);
                }
                $t['due'] = round($t['due'] + $b['due'], 2);
                $t['shipments'] += $b['shipments'];
            }

            return $t;
        };

        $positionFor = function (int $b) use ($pairs, $names, $empty, $sumBuckets) {
            $toPay = [];
            foreach ($pairs[$b] ?? [] as $payee => $bk) {
                $toPay[$payee] = $bk;
            }
            $toReceive = [];
            foreach ($pairs as $payer => $list) {
                if (isset($list[$b])) {
                    $toReceive[$payer] = $list[$b];
                }
            }

            $cps = array_unique(array_merge(array_keys($toPay), array_keys($toReceive)));
            $counterparties = [];
            foreach ($cps as $cp) {
                $pay = $toPay[$cp] ?? $empty();
                $rec = $toReceive[$cp] ?? $empty();
                $counterparties[] = [
                    'branch_id' => (int) $cp,
                    'branch_name' => $names[$cp] ?? "#{$cp}",
                    'to_pay' => $pay,
                    'to_receive' => $rec,
                    'net_due' => round($rec['due'] - $pay['due'], 2),
                    'net_incl_pending' => round(($rec['due'] + $rec['awaiting_bill']) - ($pay['due'] + $pay['awaiting_bill']), 2),
                ];
            }
            usort($counterparties, fn ($a, $c) => abs($c['net_due']) <=> abs($a['net_due']) ?: strcmp($a['branch_name'], $c['branch_name']));

            $pay = $sumBuckets($toPay);
            $rec = $sumBuckets($toReceive);

            return [
                'branch_id' => $b,
                'branch_name' => $names[$b] ?? "#{$b}",
                'totals' => [
                    'to_pay' => $pay,
                    'to_receive' => $rec,
                    'net_due' => round($rec['due'] - $pay['due'], 2),
                    'pending_bill_pay' => $pay['awaiting_bill'],
                    'pending_bill_receive' => $rec['awaiting_bill'],
                ],
                'counterparties' => $counterparties,
            ];
        };

        if ($branchId) {
            return ['mode' => 'branch', 'from' => $from, 'to' => $to] + $positionFor($branchId);
        }

        // All branches: one summary row per branch plus pairwise list.
        $branches = [];
        foreach (array_unique($allIds) as $b) {
            $p = $positionFor((int) $b);
            $branches[] = [
                'branch_id' => (int) $b,
                'branch_name' => $p['branch_name'],
                'to_pay_due' => $p['totals']['to_pay']['due'],
                'to_receive_due' => $p['totals']['to_receive']['due'],
                'net_due' => $p['totals']['net_due'],
                'paid_out_awaiting' => $p['totals']['to_pay']['paid'],
                'paid_in_awaiting' => $p['totals']['to_receive']['paid'],
                'pending_bill_pay' => $p['totals']['pending_bill_pay'],
                'pending_bill_receive' => $p['totals']['pending_bill_receive'],
                'settled_paid' => $p['totals']['to_pay']['received'],
                'settled_received' => $p['totals']['to_receive']['received'],
                'counterparty_count' => count($p['counterparties']),
            ];
        }
        usort($branches, fn ($a, $c) => strcmp($a['branch_name'], $c['branch_name']));

        $pairList = [];
        $grand = $empty();
        foreach ($pairs as $payer => $list) {
            foreach ($list as $payee => $bk) {
                $pairList[] = [
                    'payer_branch_id' => (int) $payer,
                    'payer_branch_name' => $names[$payer] ?? "#{$payer}",
                    'payee_branch_id' => (int) $payee,
                    'payee_branch_name' => $names[$payee] ?? "#{$payee}",
                ] + $bk;
                foreach (self::BUCKETS as $k) {
                    $grand[$k] = round($grand[$k] + $bk[$k], 2);
                }
                $grand['due'] = round($grand['due'] + $bk['due'], 2);
                $grand['shipments'] += $bk['shipments'];
            }
        }
        usort($pairList, fn ($a, $c) => $c['due'] <=> $a['due']);

        return [
            'mode' => 'all',
            'from' => $from,
            'to' => $to,
            'totals' => $grand,
            'branches' => $branches,
            'pairs' => $pairList,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Shipment list (one row per shipment)
    |--------------------------------------------------------------------------
    */

    public function shipmentList(Request $request, ?Authenticatable $user): array
    {
        $scoped = FinanceBranchScope::applies($user);
        $viewerIds = $scoped ? FinanceBranchScope::branchIds($user) : [];
        if (! $scoped && $request->filled('branch_id')) {
            $viewerIds = [(int) $request->branch_id];
        }

        $q = Shipment::query()->whereExists(fn ($s) => $s->selectRaw('1')
            ->from('shipment_branch_shares as x')
            ->whereColumn('x.shipment_id', 'shipments.id'));

        if ($scoped && $viewerIds === []) {
            $q->whereRaw('1 = 0');
        }

        // Status / role / branch filters apply to the viewer's own share row(s).
        $status = $request->get('status');
        $role = $request->get('role');
        if ($viewerIds !== [] || $status || $role) {
            $q->whereExists(function ($s) use ($viewerIds, $status, $role) {
                $s->selectRaw('1')->from('shipment_branch_shares as v')->whereColumn('v.shipment_id', 'shipments.id');
                if ($viewerIds !== []) {
                    $s->whereIn('v.branch_id', $viewerIds);
                }
                if ($status) {
                    $s->where('v.status', $status);
                }
                if ($role) {
                    $s->where('v.role', $role);
                }
            });
        }
        if ($request->filled('search')) {
            $q->where('shipments.tracking_number', 'like', '%'.trim((string) $request->search).'%');
        }
        if ($request->filled('shipment_id')) {
            $q->where('shipments.id', (int) $request->shipment_id);
        }
        if ($request->filled('counterparty_id')) {
            $cp = (int) $request->counterparty_id;
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('shipment_branch_shares as c')
                ->whereColumn('c.shipment_id', 'shipments.id')
                ->where(fn ($w) => $w->where('c.branch_id', $cp)->orWhere('c.collecting_branch_id', $cp)));
        }
        if ($request->filled('collection_mode')) {
            $mode = (string) $request->collection_mode;
            $q->whereExists(fn ($s) => $s->selectRaw('1')->from('shipment_branch_shares as m')
                ->whereColumn('m.shipment_id', 'shipments.id')->where('m.collection_mode', $mode));
        }
        if ($request->filled('from')) {
            $q->whereDate('shipments.delivered_at', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $q->whereDate('shipments.delivered_at', '<=', $request->to);
        }

        // Totals over the whole filtered set (not just the page).
        $idSub = (clone $q)->select('shipments.id');
        $agg = DB::table('shipment_branch_shares')
            ->whereIn('shipment_id', $idSub)
            ->selectRaw('COUNT(DISTINCT shipment_id) as shipments,
                COALESCE(SUM(CASE WHEN position = 1 THEN fare_amount ELSE 0 END),0) as fare,
                COALESCE(SUM(transport_amount),0) as transport,
                COALESCE(SUM(extra_distance_amount),0) as extra,
                COALESCE(SUM(allocation_amount),0) as allocation,
                COALESCE(SUM(hq_commission_amount),0) as hq,
                COALESCE(SUM(net_amount),0) as net')
            ->first();
        $mine = null;
        if ($viewerIds !== []) {
            $mine = DB::table('shipment_branch_shares')
                ->whereIn('shipment_id', (clone $q)->select('shipments.id'))
                ->whereIn('branch_id', $viewerIds)
                ->selectRaw('COALESCE(SUM(allocation_amount),0) as allocation, COALESCE(SUM(hq_commission_amount),0) as hq, COALESCE(SUM(net_amount),0) as net')
                ->first();
        }

        $perPage = max(1, min(200, (int) $request->get('per_page', 25)));
        $page = $q->orderByDesc('shipments.delivered_at')->orderByDesc('shipments.id')
            ->select(['shipments.id', 'shipments.tracking_number', 'shipments.status', 'shipments.delivered_at', 'shipments.merchant_id',
                'shipments.payment_type', 'shipments.delivery_charge_paid_by', 'shipments.delivery_free_by', 'shipments.branch_share_status', 'shipments.branch_share_note'])
            ->paginate($perPage);

        $ids = collect($page->items())->pluck('id')->map(fn ($v) => (int) $v)->all();
        $shareRows = $ids === [] ? collect() : DB::table('shipment_branch_shares as s')
            ->leftJoin('inter_branch_statements as st', 'st.id', '=', 's.statement_id')
            ->whereIn('s.shipment_id', $ids)
            ->orderBy('s.shipment_id')->orderBy('s.position')
            ->select(['s.*', 'st.status as statement_status', 'st.statement_number'])
            ->get();
        $eligible = $this->eligibleIds($shareRows->pluck('id')->map(fn ($v) => (int) $v)->all());
        $names = $this->branchNames(array_merge($shareRows->pluck('branch_id')->all(), $shareRows->pluck('collecting_branch_id')->all()));
        $merchants = DB::table('merchants')->whereIn('id', collect($page->items())->pluck('merchant_id')->filter()->unique())->pluck('name', 'id');
        $invoices = $this->invoicesFor($ids)->groupBy('shipment_id');

        $byShipment = $shareRows->groupBy('shipment_id');
        $data = collect($page->items())->map(function ($sh) use ($byShipment, $eligible, $names, $merchants, $invoices, $viewerIds) {
            $rows = $byShipment->get($sh->id, collect());

            return $this->shipmentRow($sh, $rows, $eligible, $names, $merchants, $invoices->get($sh->id, collect()), $viewerIds);
        })->values();

        $pendingConfig = Shipment::query()->where('branch_share_status', 'pending_config');
        FinanceBranchScope::scopeShipments($pendingConfig, $user);

        return [
            'shipments' => [
                'data' => $data,
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'summary' => [
                'shipments' => (int) ($agg->shipments ?? 0),
                'fare' => self::money($agg->fare ?? 0),
                'transport' => self::money($agg->transport ?? 0),
                'extra_distance' => self::money($agg->extra ?? 0),
                'allocation' => self::money($agg->allocation ?? 0),
                'hq_commission' => self::money($agg->hq ?? 0),
                'net' => self::money($agg->net ?? 0),
                'your_allocation' => $mine ? self::money($mine->allocation) : null,
                'your_hq_commission' => $mine ? self::money($mine->hq) : null,
                'your_net' => $mine ? self::money($mine->net) : null,
                'pending_config_shipments' => $pendingConfig->count(),
            ],
            'viewer_branch_ids' => $viewerIds,
        ];
    }

    private function invoicesFor(array $shipmentIds): Collection
    {
        if ($shipmentIds === []) {
            return collect();
        }
        $cols = ['i.id', 'i.shipment_id', 'i.invoice_number', 'i.status', 'i.total_amount', 'i.branch_id', 'i.merchant_id', 'i.created_at', 'i.invoice_date'];
        foreach (['payer_type', 'paid_amount', 'outstanding_amount', 'marketplace_id', 'marketplace_bill_id', 'paid_at', 'payment_reference'] as $c) {
            if (Schema::hasColumn('invoices', $c)) {
                $cols[] = "i.{$c}";
            }
        }

        return DB::table('invoices as i')
            ->whereIn('i.shipment_id', $shipmentIds)
            ->where('i.type', 'delivery_charges')
            ->orderBy('i.id')
            ->get($cols)
            ->map(function ($i) {
                $total = (float) $i->total_amount;
                $paid = isset($i->paid_amount) && $i->paid_amount !== null ? (float) $i->paid_amount : ($i->status === 'paid' ? $total : 0.0);
                $i->paid_amount = round($paid, 2);
                $i->outstanding_amount = isset($i->outstanding_amount) && $i->outstanding_amount !== null
                    ? round((float) $i->outstanding_amount, 2)
                    : round(max(0, $total - $paid), 2);

                return $i;
            });
    }

    private function shipmentRow(object $sh, Collection $rows, array $eligible, array $names, $merchants, Collection $invoices, array $viewerIds): array
    {
        $states = [];
        $collector = [];
        $splits = [];
        foreach ($rows->values() as $i => $r) {
            $st = $this->shareState($r, $r->statement_status, isset($eligible[(int) $r->id]));
            $states[$i] = $st;
            $collector[$i] = (int) $r->branch_id === (int) $r->collecting_branch_id;
            $splits[] = [
                'id' => (int) $r->id,
                'branch_id' => (int) $r->branch_id,
                'branch_name' => $names[$r->branch_id] ?? "#{$r->branch_id}",
                'role' => $r->role,
                'position' => (int) $r->position,
                'percent' => (float) $r->percent,
                'share_amount' => self::money($r->share_amount),
                'transport_amount' => self::money($r->transport_amount),
                'extra_distance_amount' => self::money($r->extra_distance_amount),
                'allocation_amount' => self::money($r->allocation_amount),
                'hq_rate' => (float) $r->hq_rate,
                'hq_commission_amount' => self::money($r->hq_commission_amount),
                'net_amount' => self::money($r->net_amount),
                'status' => $r->status,
                'state' => $st,
                'is_collector' => $collector[$i],
                'is_viewer' => in_array((int) $r->branch_id, $viewerIds, true),
                'statement_id' => $r->statement_id ? (int) $r->statement_id : null,
                'statement_number' => $r->statement_number,
                'statement_status' => $r->statement_status,
                'flags' => $r->flags ? (json_decode($r->flags, true) ?: []) : [],
            ];
        }

        $first = $rows->first();
        $mineRows = array_values(array_filter($splits, fn ($s) => $s['is_viewer']));
        $invoice = $invoices->last();

        return [
            'shipment_id' => (int) $sh->id,
            'tracking_number' => $sh->tracking_number,
            'status' => $sh->status,
            'delivered_at' => self::dt($sh->delivered_at),
            'merchant_name' => $sh->merchant_id ? ($merchants[$sh->merchant_id] ?? null) : null,
            'payment_type' => $sh->payment_type,
            'delivery_charge_paid_by' => $sh->delivery_charge_paid_by,
            'delivery_free_by' => $sh->delivery_free_by,
            'branch_share_status' => $sh->branch_share_status,
            'route' => array_map(fn ($s) => ['branch_id' => $s['branch_id'], 'name' => $s['branch_name'], 'role' => $s['role']], $splits),
            'branch_count' => (int) ($first->branch_count ?? count($splits)),
            'fare' => self::money($first->fare_amount ?? 0),
            'transport_total' => self::money(array_sum(array_column($splits, 'transport_amount'))),
            'extra_distance_total' => self::money(array_sum(array_column($splits, 'extra_distance_amount'))),
            'hq_total' => self::money(array_sum(array_column($splits, 'hq_commission_amount'))),
            'collecting_branch_id' => $first?->collecting_branch_id ? (int) $first->collecting_branch_id : null,
            'collecting_branch_name' => $first?->collecting_branch_id ? ($names[$first->collecting_branch_id] ?? null) : null,
            'collection_mode' => $first->collection_mode ?? null,
            'state' => $this->shipmentState($states, $collector),
            'invoice' => $invoice ? [
                'invoice_number' => $invoice->invoice_number,
                'status' => $invoice->status,
                'total_amount' => self::money($invoice->total_amount),
                'paid_amount' => $invoice->paid_amount,
                'outstanding_amount' => $invoice->outstanding_amount,
            ] : null,
            'your_allocation' => $mineRows ? self::money(array_sum(array_column($mineRows, 'allocation_amount'))) : null,
            'your_net' => $mineRows ? self::money(array_sum(array_column($mineRows, 'net_amount'))) : null,
            'your_role' => $mineRows ? $mineRows[0]['role'] : null,
            'your_state' => $mineRows ? $mineRows[0]['state'] : null,
            'flags' => array_values(array_unique(array_merge(...array_map(fn ($s) => $s['flags'], $splits ?: [['flags' => []]])))),
            'splits' => $splits,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | One shipment, full breakdown
    |--------------------------------------------------------------------------
    */

    /** May this user open the breakdown? Branch users: share row, collector, or billing branch. */
    public function canView(?Authenticatable $user, Shipment $shipment): bool
    {
        if (! FinanceBranchScope::applies($user)) {
            return $user !== null && ! FinanceBranchScope::isMerchant($user);
        }
        $ids = FinanceBranchScope::branchIds($user);
        if ($ids === []) {
            return false;
        }
        $hasShare = DB::table('shipment_branch_shares')->where('shipment_id', $shipment->id)
            ->where(fn ($w) => $w->whereIn('branch_id', $ids)->orWhereIn('collecting_branch_id', $ids))
            ->exists();
        if ($hasShare) {
            return true;
        }
        $billing = $this->shares->billingBranchId($shipment);
        if ($billing && in_array($billing, $ids, true)) {
            return true;
        }

        return DB::table('invoices')->where('shipment_id', $shipment->id)->where('type', 'delivery_charges')->whereIn('branch_id', $ids)->exists();
    }

    public function shipmentBreakdown(Shipment $shipment, ?Authenticatable $user): array
    {
        $isHq = FinanceBranchScope::isHq($user);
        $viewerIds = FinanceBranchScope::applies($user) ? FinanceBranchScope::branchIds($user) : [];

        $rows = DB::table('shipment_branch_shares as s')
            ->leftJoin('inter_branch_statements as st', 'st.id', '=', 's.statement_id')
            ->where('s.shipment_id', $shipment->id)
            ->orderBy('s.position')
            ->select(['s.*', 'st.status as statement_status', 'st.statement_number', 'st.period_start', 'st.period_end'])
            ->get();
        $eligible = $this->eligibleIds($rows->pluck('id')->map(fn ($v) => (int) $v)->all());
        $hops = $this->hops->hopsForShipment((int) $shipment->id);

        $branchIds = array_merge(
            $rows->pluck('branch_id')->all(),
            $rows->pluck('collecting_branch_id')->all(),
            [$shipment->origin_branch_id, $shipment->destination_branch_id, $shipment->current_branch_id],
        );
        $names = $this->branchNames($branchIds);
        $merchant = $shipment->merchant_id ? DB::table('merchants')->where('id', $shipment->merchant_id)->first(['id', 'name', 'marketplace_id']) : null;
        $marketplaceId = $shipment->marketplace_id ?? $merchant?->marketplace_id;
        $marketplace = $marketplaceId && Schema::hasTable('marketplaces')
            ? DB::table('marketplaces')->where('id', $marketplaceId)->value('name')
            : null;

        $invoices = $this->invoicesFor([(int) $shipment->id]);
        $invBranchNames = $this->branchNames($invoices->pluck('branch_id')->all());
        $mpNames = Schema::hasTable('marketplaces')
            ? DB::table('marketplaces')->whereIn('id', $invoices->pluck('marketplace_id')->filter()->unique())->pluck('name', 'id')
            : collect();
        $invoiceList = $invoices->map(fn ($i) => [
            'id' => (int) $i->id,
            'invoice_number' => $i->invoice_number,
            'payer_type' => $i->payer_type ?? 'merchant',
            'payer_name' => ($i->payer_type ?? 'merchant') === 'company'
                ? ($mpNames[$i->marketplace_id ?? 0] ?? 'Marketplace')
                : ($merchant?->name ?? 'Store'),
            'branch_id' => $i->branch_id ? (int) $i->branch_id : null,
            'branch_name' => $i->branch_id ? ($invBranchNames[$i->branch_id] ?? null) : null,
            'status' => $i->status,
            'total_amount' => self::money($i->total_amount),
            'paid_amount' => $i->paid_amount,
            'outstanding_amount' => $i->outstanding_amount,
            'paid_at' => $i->paid_at ?? null,
            'payment_reference' => $i->payment_reference ?? null,
            'invoice_date' => $i->invoice_date,
        ])->values();

        // Fare breakdown from the stored price snapshot.
        $bd = $shipment->delivery_charge_breakdown;
        if (is_string($bd)) {
            $bd = json_decode($bd, true);
        }
        $bd = is_array($bd) ? $bd : [];
        $inner = (array) data_get($bd, 'breakdown', []);
        $fareCharged = round((float) app(\Modules\Settlement\Services\SettlementWorkflowService::class)->checkoutDeliveryCharge($shipment), 2);
        $extraInfo = $this->shares->extraDistanceDetails($shipment, $fareCharged);
        $component = fn ($k) => is_numeric(data_get($inner, "{$k}.total")) ? round((float) data_get($inner, "{$k}.total"), 2) : 0.0;
        $fare = [
            'route_base' => $component('route_base_rate'),
            'route_base_source' => data_get($inner, 'route_base_rate.source'),
            'extra_distance' => [
                'stored_total' => $extraInfo['stored'],
                'used_total' => $extraInfo['amount'],
                'reconciled' => $extraInfo['reconciled'],
                'residual' => $extraInfo['residual'],
                'extra_km' => $extraInfo['km'],
                'included_km' => $extraInfo['included_km'],
                'rate_per_km' => $extraInfo['rate_per_km'],
                'delivery_distance_km' => $extraInfo['delivery_distance_km'],
                'measured_from' => data_get($bd, 'delivery_branch.name'),
                'zone_distance_km' => data_get($bd, 'delivery_branch.zone_distance_km'),
                'operational_branch' => data_get($bd, 'delivery_branch.operational_branch_name'),
            ],
            'additional_weight' => $component('additional_weight'),
            'weight_detail' => [
                'chargeable_kg' => data_get($inner, 'additional_weight.total_chargeable_weight_kg'),
                'included_kg' => data_get($inner, 'additional_weight.included_weight_kg'),
                'excess_kg' => data_get($inner, 'additional_weight.excess_weight_kg'),
                'rate_per_kg' => data_get($inner, 'additional_weight.rate_per_kg'),
            ],
            'fragile' => $component('fragile'),
            'express' => $component('express'),
            'same_day' => $component('same_day'),
            'vat' => data_get($bd, 'vat'),
            'breakdown_final' => $extraInfo['breakdown_final'],
            'fare_charged' => $fareCharged,
            'difference' => $extraInfo['breakdown_final'] !== null ? round($fareCharged - $extraInfo['breakdown_final'], 2) : null,
            'service_type' => data_get($bd, 'service_type.name'),
        ];

        // Split as stored.
        $splits = [];
        $states = [];
        $collector = [];
        foreach ($rows->values() as $i => $r) {
            $st = $this->shareState($r, $r->statement_status, isset($eligible[(int) $r->id]));
            $states[$i] = $st;
            $collector[$i] = (int) $r->branch_id === (int) $r->collecting_branch_id;
            $splits[] = [
                'id' => (int) $r->id,
                'branch_id' => (int) $r->branch_id,
                'branch_name' => $names[$r->branch_id] ?? "#{$r->branch_id}",
                'role' => $r->role,
                'position' => (int) $r->position,
                'percent' => (float) $r->percent,
                'share_amount' => self::money($r->share_amount),
                'transport_amount' => self::money($r->transport_amount),
                'extra_distance_amount' => self::money($r->extra_distance_amount),
                'allocation_amount' => self::money($r->allocation_amount),
                'hq_rate' => (float) $r->hq_rate,
                'hq_commission_amount' => self::money($r->hq_commission_amount),
                'net_amount' => self::money($r->net_amount),
                'status' => $r->status,
                'state' => $st,
                'is_collector' => $collector[$i],
                'is_viewer' => in_array((int) $r->branch_id, $viewerIds, true),
                'statement' => $r->statement_id ? [
                    'id' => (int) $r->statement_id,
                    'statement_number' => $r->statement_number,
                    'status' => $r->statement_status,
                    'period_start' => $r->period_start ? substr((string) $r->period_start, 0, 10) : null,
                    'period_end' => $r->period_end ? substr((string) $r->period_end, 0, 10) : null,
                ] : null,
                'flags' => $r->flags ? (json_decode($r->flags, true) ?: []) : [],
                'computed_at' => $r->computed_at,
            ];
        }

        $first = $rows->first();
        $storedFare = self::money($first->fare_amount ?? 0);
        $extraTotal = self::money(array_sum(array_column($splits, 'extra_distance_amount')));
        $transportTotal = self::money(array_sum(array_column($splits, 'transport_amount')));
        $sharedTotal = self::money(array_sum(array_column($splits, 'share_amount')));
        $collectingId = $first?->collecting_branch_id ? (int) $first->collecting_branch_id : null;

        $payments = [];
        foreach ($splits as $s) {
            if ($s['is_collector'] || ! $collectingId) {
                continue;
            }
            $payments[] = [
                'from_branch_id' => $collectingId,
                'from_branch_name' => $names[$collectingId] ?? "#{$collectingId}",
                'to_branch_id' => $s['branch_id'],
                'to_branch_name' => $s['branch_name'],
                'amount' => $s['allocation_amount'],
                'state' => $s['state'],
                'statement' => $s['statement'],
            ];
        }

        $flags = [];
        foreach ($splits as $s) {
            foreach ($s['flags'] as $f) {
                $flags[$f] = true;
            }
        }
        $flags = array_keys($flags);
        $checks = [];
        if ($shipment->branch_share_status === 'pending_config') {
            $checks[] = ['code' => 'pending_config', 'level' => 'warning', 'message' => $shipment->branch_share_note ?: 'No share percent row for this many branches.'];
        }
        if ($splits && $extraInfo['reconciled'] && $extraTotal > $extraInfo['amount'] + 0.01) {
            $checks[] = [
                'code' => 'extra_distance_exceeds_fare',
                'level' => 'error',
                'message' => sprintf(
                    'The stored split gives Rs %.2f extra distance, but the fare charged (Rs %.2f) has only Rs %.2f above route base and add-ons. The price breakdown was re-written after the fare was charged (it now measures %s km from %s, %s km over the included %s km at Rs %s/km). Recompute would use Rs %.2f.',
                    $extraTotal, $fareCharged, $extraInfo['residual'],
                    $extraInfo['delivery_distance_km'] ?? '?', $fare['extra_distance']['measured_from'] ?? 'the coverage point',
                    $extraInfo['km'] ?? '?', $extraInfo['included_km'] ?? '?', $extraInfo['rate_per_km'] ?? '?', $extraInfo['amount'],
                ),
            ];
        }
        if ($fare['difference'] !== null && abs($fare['difference']) > 0.05) {
            $checks[] = [
                'code' => 'breakdown_mismatch',
                'level' => 'info',
                'message' => sprintf('Stored price breakdown totals Rs %.2f, but the fare charged is Rs %.2f (difference Rs %.2f). The split uses the fare charged.', $fare['breakdown_final'], $fareCharged, $fare['difference']),
            ];
        }
        if ($splits && abs($storedFare - $fareCharged) > 0.01) {
            $checks[] = ['code' => 'fare_changed', 'level' => 'warning', 'message' => sprintf('Split was computed on a fare of Rs %.2f; the shipment fare is now Rs %.2f.', $storedFare, $fareCharged)];
        }
        $collectionMode = $first->collection_mode ?? null;
        if ($collectionMode === 'customer' && $invoices->isNotEmpty()) {
            $checks[] = ['code' => 'customer_paid_but_invoiced', 'level' => 'info', 'message' => 'The customer pays the delivery charge at the door, yet a delivery-charge invoice to the merchant also exists. That is fine if the invoice is only the record deducted from the COD remittance; if the merchant is asked to pay it as well, the charge is billed twice.'];
        }
        if (in_array('transport_shortfall', $flags, true)) {
            $checks[] = ['code' => 'transport_shortfall', 'level' => 'warning', 'message' => 'Transport cost plus extra distance was more than the fare, so transport refunds were scaled down and nothing was left to share.'];
        }
        if (in_array('extra_distance_capped', $flags, true)) {
            $checks[] = ['code' => 'extra_distance_capped', 'level' => 'warning', 'message' => 'Extra distance was larger than the fare and was capped at the fare.'];
        }
        if (in_array('transport_branch_not_in_chain', $flags, true)) {
            $checks[] = ['code' => 'transport_branch_not_in_chain', 'level' => 'info', 'message' => 'A dispatching branch was not in the branch chain; its transport was credited to the origin branch.'];
        }
        if (in_array('extra_distance_reconciled', $flags, true)) {
            $checks[] = ['code' => 'extra_distance_reconciled', 'level' => 'info', 'message' => 'Extra distance was reduced to the part of the fare above route base and add-ons, because the stored breakdown was larger than the fare charged.'];
        }

        // Fresh calculation to compare (read only).
        $preview = null;
        $recomputeDiffers = false;
        if ($isHq || ! $splits) {
            try {
                $p = $this->shares->preview($shipment);
                $preview = [
                    'status' => $p['status'],
                    'fare' => $p['fare'],
                    'extra' => $p['extra'],
                    'transport_total' => $p['transport_total'],
                    'shared' => $p['shared'],
                    'branch_count' => $p['branch_count'],
                    'percents' => $p['percents'],
                    'flags' => $p['flags'],
                    'collecting_branch_id' => $p['collecting_branch_id'] ?? null,
                    'collection_mode' => $p['collection_mode'] ?? null,
                    'rows' => array_map(fn ($r) => $r + ['branch_name' => $this->branchNames([$r['branch_id']])[$r['branch_id']] ?? "#{$r['branch_id']}"], $p['rows']),
                ];
                if ($splits) {
                    $a = array_map(fn ($s) => [$s['branch_id'], $s['allocation_amount']], $splits);
                    $b = array_map(fn ($r) => [(int) $r['branch_id'], round((float) $r['allocation_amount'], 2)], $p['rows']);
                    $recomputeDiffers = $a != $b;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $locked = collect($splits)->contains(fn ($s) => $s['statement'] !== null || $s['status'] !== 'pending');

        $chainNames = array_map(fn ($s) => $s['branch_name'], $splits);
        if (! $chainNames && $preview) {
            $chainNames = array_map(fn ($r) => $r['branch_name'], $preview['rows']);
        }

        return [
            'shipment' => [
                'id' => (int) $shipment->id,
                'tracking_number' => $shipment->tracking_number,
                'status' => $shipment->status,
                'delivered_at' => self::dt($shipment->delivered_at),
                'merchant_id' => $shipment->merchant_id ? (int) $shipment->merchant_id : null,
                'merchant_name' => $merchant?->name,
                'marketplace_name' => $marketplace,
                'payment_type' => $shipment->payment_type,
                'pod_amount' => self::money($shipment->pod_amount ?? 0),
                'delivery_charge_paid_by' => $shipment->delivery_charge_paid_by,
                'delivery_free_by' => $shipment->delivery_free_by,
                'origin_branch_name' => $shipment->origin_branch_id ? ($names[$shipment->origin_branch_id] ?? null) : null,
                'destination_branch_name' => $shipment->destination_branch_id ? ($names[$shipment->destination_branch_id] ?? null) : null,
                'route' => $chainNames,
                'branch_share_status' => $shipment->branch_share_status,
                'branch_share_note' => $shipment->branch_share_note,
            ],
            'billing' => [
                'billing_branch_id' => $this->shares->billingBranchId($shipment),
                'billing_branch_name' => ($bb = $this->shares->billingBranchId($shipment)) ? ($this->branchNames([$bb])[$bb] ?? null) : null,
                'collection_mode' => $collectionMode,
                'collecting_branch_id' => $collectingId,
                'collecting_branch_name' => $collectingId ? ($names[$collectingId] ?? null) : null,
                'invoices' => $invoiceList,
            ],
            'fare' => $fare,
            'hops' => $hops,
            'split' => [
                'fare' => $storedFare,
                'extra_distance' => $extraTotal,
                'transport_total' => $transportTotal,
                'shared' => self::money($storedFare - $extraTotal - $transportTotal),
                'shared_distributed' => $sharedTotal,
                'branch_count' => (int) ($first->branch_count ?? 0),
                'percents' => array_map(fn ($s) => $s['percent'], $splits),
                'rows' => $splits,
                'totals' => [
                    'share_amount' => $sharedTotal,
                    'transport_amount' => $transportTotal,
                    'extra_distance_amount' => $extraTotal,
                    'allocation_amount' => self::money(array_sum(array_column($splits, 'allocation_amount'))),
                    'hq_commission_amount' => self::money(array_sum(array_column($splits, 'hq_commission_amount'))),
                    'net_amount' => self::money(array_sum(array_column($splits, 'net_amount'))),
                ],
                'reconciles' => $splits ? abs(array_sum(array_column($splits, 'allocation_amount')) - $storedFare) < 0.005 : null,
                'state' => $splits ? $this->shipmentState($states, $collector) : null,
                'locked' => $locked,
            ],
            'payments' => $payments,
            'flags' => $flags,
            'checks' => $checks,
            'preview' => $preview,
            'recompute_differs' => $recomputeDiffers,
            'viewer' => ['is_hq' => $isHq, 'branch_ids' => $viewerIds],
        ];
    }
}
