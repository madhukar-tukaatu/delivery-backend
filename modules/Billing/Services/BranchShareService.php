<?php

namespace Modules\Billing\Services;

use App\Support\BranchShareCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\ShipmentBranchShare;
use Modules\Dispatch\Services\ManifestHopService;
use Modules\Setting\Models\Setting;
use Modules\Settlement\Services\SettlementWorkflowService;
use Modules\Shipment\Models\Shipment;

/**
 * Splits a delivered shipment's delivery fare across the branches that
 * actually handled it (origin, transit hops, delivery).
 *
 *   F        = SettlementWorkflowService::checkoutDeliveryCharge()
 *   extra    = stored extra_delivery_distance charge, 100% to the delivery branch
 *   transport= hop transport cost, credited to each dispatching branch
 *   shared   = F - extra - transport, split by the admin percent table
 *
 * The collecting branch (origin when the merchant / marketplace is invoiced,
 * delivery branch when the customer paid the fare at the door) owes every
 * other branch its allocation through inter-branch statements.
 */
class BranchShareService
{
    public const TABLE_KEY = 'settlement.branch_share_table';

    public function __construct(
        private SettlementWorkflowService $settlements,
        private ManifestHopService $hops,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Percent table (admin config)
    |--------------------------------------------------------------------------
    */

    /** @return array<int, list<float>> */
    public function table(): array
    {
        $row = Setting::query()->where('key', self::TABLE_KEY)->first();
        $decoded = $row ? json_decode((string) $row->value, true) : null;

        if (! is_array($decoded) || $decoded === []) {
            return BranchShareCalculator::DEFAULT_TABLE;
        }

        $table = [];
        foreach ($decoded as $count => $percents) {
            if (is_array($percents)) {
                $table[(int) $count] = array_values(array_map('floatval', $percents));
            }
        }
        ksort($table);

        return $table;
    }

    public function tableIsDefault(): bool
    {
        return ! Setting::query()->where('key', self::TABLE_KEY)->exists();
    }

    /** @param array<int|string, list<float|int>> $table */
    public function saveTable(array $table): array
    {
        $normalized = [];
        foreach ($table as $count => $percents) {
            $normalized[(int) $count] = array_values(array_map(fn ($v) => round((float) $v, 4), (array) $percents));
        }
        ksort($normalized);

        $errors = BranchShareCalculator::validateTable($normalized);
        if ($errors) {
            throw ValidationException::withMessages(['table' => $errors]);
        }

        Setting::updateOrCreate(
            ['key' => self::TABLE_KEY],
            ['value' => json_encode($normalized), 'type' => 'json'],
        );

        return $normalized;
    }

    /*
    |--------------------------------------------------------------------------
    | Inputs from the shipment
    |--------------------------------------------------------------------------
    */

    /** Branch that bills the merchant / marketplace: origin first. */
    public function billingBranchId(Shipment $shipment): ?int
    {
        $merchantBranch = null;
        if (! $shipment->origin_branch_id && $shipment->merchant_id) {
            $merchantBranch = DB::table('merchants')->where('id', $shipment->merchant_id)->value('default_branch_id');
        }

        $id = $shipment->origin_branch_id
            ?? $merchantBranch
            ?? $shipment->current_branch_id
            ?? $shipment->destination_branch_id
            ?? null;

        return $id ? (int) $id : null;
    }

    public function deliveryBranchId(Shipment $shipment): ?int
    {
        $id = $shipment->destination_branch_id;
        if (! $id && Schema::hasTable('delivery_assignments')) {
            $id = DB::table('delivery_assignments')
                ->where('shipment_id', $shipment->id)
                ->where('status', 'delivered')
                ->latest('id')
                ->value('branch_id');
        }

        return $id ? (int) $id : null;
    }

    /**
     * Ordered distinct branches: origin, every hop destination, delivery branch last.
     *
     * @return array{branch_ids: list<int>, transport_by_branch: array<int,float>, hops: list<array>}
     */
    public function chainForShipment(Shipment $shipment): array
    {
        $origin = $this->billingBranchId($shipment);
        $hops = $this->hops->hopsForShipment((int) $shipment->id);

        $chain = $origin ? [$origin] : [];
        foreach ($hops as $hop) {
            $to = $hop['to_branch_id'];
            if ($to && ! in_array($to, $chain, true)) {
                $chain[] = $to;
            }
        }

        $delivery = $this->deliveryBranchId($shipment);
        if ($delivery) {
            $chain = array_values(array_filter($chain, fn ($id) => $id !== $delivery));
            $chain[] = $delivery;
        }

        $transport = [];
        foreach ($hops as $hop) {
            $from = $hop['from_branch_id'] ?: $origin;
            if (! $from || $hop['transport_cost'] <= 0) {
                continue;
            }
            $transport[$from] = round(($transport[$from] ?? 0) + $hop['transport_cost'], 2);
        }

        return ['branch_ids' => $chain, 'transport_by_branch' => $transport, 'hops' => $hops];
    }

    /** Stored extra last-mile distance charge (not recomputed). */
    public function extraDistanceAmount(Shipment $shipment): float
    {
        $breakdown = $shipment->delivery_charge_breakdown;
        if (is_string($breakdown)) {
            $breakdown = json_decode($breakdown, true);
        }
        if (is_array($breakdown)) {
            $val = data_get($breakdown, 'breakdown.extra_delivery_distance.total')
                ?? data_get($breakdown, 'extra_delivery_distance.total');
            if (is_numeric($val)) {
                return round(max(0, (float) $val), 2);
            }
        }

        if (Schema::hasTable('shipment_price_breakdowns')) {
            $val = DB::table('shipment_price_breakdowns')
                ->where('shipment_id', $shipment->id)
                ->orderByDesc('id')
                ->value('delivery_extra_charge');
            if (is_numeric($val)) {
                return round(max(0, (float) $val), 2);
            }
        }

        return 0.0;
    }

    /**
     * Who collected the fare and how.
     *
     * @return array{collecting_branch_id:?int, collection_mode:string}
     */
    public function collection(Shipment $shipment, array $chain): array
    {
        $split = $this->settlements->deliveryChargeSplit($shipment);

        if ((float) $split['marketplace_share'] > 0) {
            return ['collecting_branch_id' => $this->billingBranchId($shipment), 'collection_mode' => 'company_invoice'];
        }
        if ((float) $split['store_share'] > 0) {
            return ['collecting_branch_id' => $this->billingBranchId($shipment), 'collection_mode' => 'merchant_invoice'];
        }

        // Customer paid the fare at the door: the delivering branch holds it.
        $last = $chain ? $chain[count($chain) - 1] : null;

        return ['collecting_branch_id' => $this->deliveryBranchId($shipment) ?? $last, 'collection_mode' => 'customer'];
    }

    /*
    |--------------------------------------------------------------------------
    | Compute / persist
    |--------------------------------------------------------------------------
    */

    /** Compute without writing. */
    public function preview(Shipment $shipment): array
    {
        $fare = round($this->settlements->checkoutDeliveryCharge($shipment), 2);
        $chain = $this->chainForShipment($shipment);
        $extra = $this->extraDistanceAmount($shipment);

        $commissions = app(BranchCommissionService::class);
        $rates = [];
        foreach ($chain['branch_ids'] as $branchId) {
            $rates[$branchId] = $commissions->commissionRatePercent($branchId);
        }

        $result = BranchShareCalculator::split(
            $fare,
            $extra,
            $chain['branch_ids'],
            $chain['transport_by_branch'],
            $this->table(),
            $rates,
        );

        if ($fare <= 0) {
            $result['status'] = 'no_fare';
        }

        $result['chain'] = $chain;
        $result += $this->collection($shipment, $chain['branch_ids']);

        return $result;
    }

    /**
     * Create (or replace) the share rows for a delivered shipment.
     * Rows that are already on a statement are never touched.
     *
     * @return array{status:string, rows:\Illuminate\Support\Collection, result?:array}
     */
    public function ensureForShipment(Shipment $shipment): array
    {
        if (! Schema::hasTable('shipment_branch_shares')) {
            return ['status' => 'not_installed', 'rows' => collect()];
        }

        $shipment->refresh();
        if (strtolower((string) $shipment->status) !== 'delivered') {
            return ['status' => 'not_delivered', 'rows' => collect()];
        }

        $existing = ShipmentBranchShare::query()->where('shipment_id', $shipment->id)->get();
        $locked = $existing->contains(fn ($r) => $r->statement_id !== null || $r->status !== 'pending');
        if ($locked) {
            return ['status' => 'locked', 'rows' => $existing];
        }

        $result = $this->preview($shipment);
        $status = $result['status'];

        return DB::transaction(function () use ($shipment, $result, $status) {
            ShipmentBranchShare::query()
                ->where('shipment_id', $shipment->id)
                ->whereNull('statement_id')
                ->where('status', 'pending')
                ->delete();

            $note = null;
            $rows = collect();

            if ($status === 'computed') {
                foreach ($result['rows'] as $row) {
                    $rows->push(ShipmentBranchShare::create([
                        'shipment_id' => $shipment->id,
                        'branch_id' => $row['branch_id'],
                        'role' => $row['role'],
                        'position' => $row['position'],
                        'branch_count' => $result['branch_count'],
                        'percent' => $row['percent'],
                        'fare_amount' => $result['fare'],
                        'share_amount' => $row['share_amount'],
                        'transport_amount' => $row['transport_amount'],
                        'extra_distance_amount' => $row['extra_distance_amount'],
                        'allocation_amount' => $row['allocation_amount'],
                        'hq_rate' => $row['hq_rate'],
                        'hq_commission_amount' => $row['hq_commission_amount'],
                        'net_amount' => $row['net_amount'],
                        'collecting_branch_id' => $result['collecting_branch_id'],
                        'collection_mode' => $result['collection_mode'],
                        'status' => 'pending',
                        'flags' => $result['flags'] ?: null,
                        'computed_at' => now(),
                    ]));
                }
                $note = $result['flags'] ? implode(', ', $result['flags']) : null;
            } elseif ($status === 'pending_config') {
                $note = "No branch share percent row for {$result['branch_count']} branches. Add it under Finance -> Branch shares.";
            } elseif ($status === 'no_fare') {
                $note = 'Delivery fare is zero.';
            } else {
                $note = 'Could not resolve the branches for this shipment.';
            }

            if (Schema::hasColumn('shipments', 'branch_share_status')) {
                DB::table('shipments')->where('id', $shipment->id)->update([
                    'branch_share_status' => $status === 'no_branches' ? 'failed' : $status,
                    'branch_share_note' => $note ? mb_substr($note, 0, 255) : null,
                ]);
            }

            return ['status' => $status, 'rows' => $rows, 'result' => $result];
        });
    }

    /**
     * Shares whose collecting branch has actually been paid, so they can go
     * on an inter-branch statement.
     */
    public function eligibleQuery()
    {
        return ShipmentBranchShare::query()
            ->from('shipment_branch_shares')
            ->select('shipment_branch_shares.*')
            ->join('shipments', 'shipments.id', '=', 'shipment_branch_shares.shipment_id')
            ->where('shipment_branch_shares.status', 'pending')
            ->whereNull('shipment_branch_shares.statement_id')
            ->whereNotNull('shipment_branch_shares.collecting_branch_id')
            ->where('shipments.status', 'delivered')
            ->where(function ($q) {
                $q->where('shipment_branch_shares.collection_mode', 'customer')
                    ->orWhere(function ($inv) {
                        $inv->where('shipment_branch_shares.collection_mode', 'merchant_invoice')
                            ->whereExists(fn ($s) => $this->paidInvoice($s, 'merchant'));
                    })
                    ->orWhere(function ($inv) {
                        $inv->where('shipment_branch_shares.collection_mode', 'company_invoice')
                            ->whereExists(fn ($s) => $this->paidInvoice($s, 'company'));
                    });
            });
    }

    private function paidInvoice($sub, string $payer): void
    {
        $sub->selectRaw('1')
            ->from('invoices as si')
            ->whereColumn('si.shipment_id', 'shipment_branch_shares.shipment_id')
            ->where('si.type', 'delivery_charges')
            ->where('si.status', 'paid');

        if (Schema::hasColumn('invoices', 'payer_type')) {
            if ($payer === 'company') {
                $sub->where('si.payer_type', 'company');
            } else {
                $sub->where(fn ($q) => $q->where('si.payer_type', 'merchant')->orWhereNull('si.payer_type'));
            }
        }
    }
}
