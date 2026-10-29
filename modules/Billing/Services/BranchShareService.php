<?php

namespace Modules\Billing\Services;

use App\Support\BranchShareCalculator;
use App\Support\FinanceBranchScope;
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
    public const RULES_KEY = 'settlement.transfer_share_rules';

    public function __construct(
        private SettlementWorkflowService $settlements,
        private ManifestHopService $hops,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Dynamic transfer share rules (admin config)
    |--------------------------------------------------------------------------
    */

    /** @return array */
    public function rules(): array
    {
        $row = Setting::query()->where('key', self::RULES_KEY)->first();
        $decoded = $row ? json_decode((string) $row->value, true) : null;

        if (! is_array($decoded) || $decoded === []) {
            return BranchShareCalculator::DEFAULT_RULES;
        }

        return BranchShareCalculator::mergeRules($decoded);
    }

    public function rulesIsDefault(): bool
    {
        return ! Setting::query()->where('key', self::RULES_KEY)->exists();
    }

    public function saveRules(array $rules): array
    {
        $merged = BranchShareCalculator::mergeRules($rules);

        Setting::updateOrCreate(
            ['key' => self::RULES_KEY],
            ['value' => json_encode($merged), 'type' => 'json'],
        );

        return $merged;
    }

    /*
    |--------------------------------------------------------------------------
    | Percent table (admin config - legacy fallback)
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
                $vals = array_values(array_map('floatval', $percents));
                $sum = round(array_sum($vals), 4);
                if (abs($sum - 100) > 0.001) {
                    $vals = BranchShareCalculator::DEFAULT_TABLE[(int) $count] ?? self::normalizeRowTo100($vals);
                }
                $table[(int) $count] = $vals;
            }
        }
        if (! isset($table[1])) {
            $table[1] = [100.0];
        }
        ksort($table);

        return $table;
    }

    public static function normalizeRowTo100(array $row): array
    {
        $sum = array_sum($row);
        if ($sum <= 0) {
            $n = max(1, count($row));
            return array_fill(0, $n, round(100 / $n, 4));
        }
        $out = [];
        $alloc = 0;
        $last = count($row) - 1;
        foreach ($row as $i => $v) {
            if ($i === $last) {
                $out[] = round(100 - $alloc, 4);
            } else {
                $part = round(($v / $sum) * 100, 4);
                $out[] = $part;
                $alloc += $part;
            }
        }
        return $out;
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

    /** Stored extra last-mile distance charge, reconciled with the fare actually charged. */
    public function extraDistanceAmount(Shipment $shipment, ?float $fare = null): float
    {
        return $this->extraDistanceDetails($shipment, $fare)['amount'];
    }

    /**
     * Extra last-mile distance charge with how it was derived.
     *
     * The stored price breakdown can be re-written after the fare was charged
     * (e.g. a later re-price measured the distance from another coverage
     * point). The extra distance can never be more than the part of the fare
     * that is not route base + other add-ons, so when the stored extra is
     * larger than that residual we use the residual and flag it.
     *
     * @return array{amount:float, stored:float, residual:?float, reconciled:bool, source:string, breakdown_final:?float, km:?float, included_km:?float, rate_per_km:?float, delivery_distance_km:?float}
     */
    public function extraDistanceDetails(Shipment $shipment, ?float $fare = null): array
    {
        $breakdown = $shipment->delivery_charge_breakdown;
        if (is_string($breakdown)) {
            $breakdown = json_decode($breakdown, true);
        }

        $out = [
            'amount' => 0.0, 'stored' => 0.0, 'residual' => null, 'reconciled' => false, 'source' => 'none',
            'breakdown_final' => null, 'km' => null, 'included_km' => null, 'rate_per_km' => null, 'delivery_distance_km' => null,
        ];

        $stored = null;
        if (is_array($breakdown)) {
            $node = data_get($breakdown, 'breakdown.extra_delivery_distance') ?? data_get($breakdown, 'extra_delivery_distance');
            $val = is_array($node) ? ($node['total'] ?? null) : null;
            if (is_numeric($val)) {
                $stored = round(max(0, (float) $val), 2);
                $out['source'] = 'breakdown';
                $out['km'] = isset($node['extra_distance_km']) ? (float) $node['extra_distance_km'] : null;
                $out['included_km'] = isset($node['included_distance_km']) ? (float) $node['included_distance_km'] : null;
                $out['rate_per_km'] = isset($node['rate_per_km']) ? (float) $node['rate_per_km'] : null;
                $out['delivery_distance_km'] = isset($node['delivery_distance_km']) ? (float) $node['delivery_distance_km'] : null;
            }
            $inner = data_get($breakdown, 'breakdown.final_price');
            $out['breakdown_final'] = is_numeric($inner) ? round((float) $inner, 2) : null;
        }

        if ($stored === null && Schema::hasTable('shipment_price_breakdowns')) {
            $val = DB::table('shipment_price_breakdowns')
                ->where('shipment_id', $shipment->id)
                ->orderByDesc('id')
                ->value('delivery_extra_charge');
            if (is_numeric($val)) {
                $stored = round(max(0, (float) $val), 2);
                $out['source'] = 'price_breakdown_table';
            }
        }

        $stored = $stored ?? 0.0;
        $out['stored'] = $stored;
        $out['amount'] = $stored;

        // Reconcile with the fare that was actually charged.
        if ($fare !== null && $stored > 0 && $out['breakdown_final'] !== null) {
            $baseAndAddons = round($out['breakdown_final'] - $stored, 2);
            $residual = round($fare - $baseAndAddons, 2);
            $out['residual'] = $residual;
            if ($residual >= 0 && $stored > $residual + 0.01) {
                $out['amount'] = $residual;
                $out['reconciled'] = true;
            }
        }

        return $out;
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
        $extraInfo = $this->extraDistanceDetails($shipment, $fare);
        $extra = $extraInfo['amount'];

        $destBranchId = $this->deliveryBranchId($shipment);
        $destLocalBase = $this->resolveLocalBaseRate($destBranchId);

        $breakdownData = $shipment->delivery_charge_breakdown;
        if (is_string($breakdownData)) {
            $breakdownData = json_decode($breakdownData, true);
        }
        $breakdownData = is_array($breakdownData) ? $breakdownData : [];
        $inner = $breakdownData['breakdown'] ?? $breakdownData;

        $itemizedBreakdown = [
            'dest_local_base' => $destLocalBase,
            'extra_distance' => $extra,
            'extra_weight' => (float) (data_get($inner, 'additional_weight.total') ?? data_get($inner, 'extra_weight.total') ?? 0),
            'fragile' => (float) (data_get($inner, 'fragile.total') ?? data_get($inner, 'fragile_charge') ?? 0),
            'express' => (float) (data_get($inner, 'express.total') ?? data_get($inner, 'express_charge') ?? 0),
            'same_day' => (float) (data_get($inner, 'same_day.total') ?? data_get($inner, 'same_day_charge') ?? 0),
            'route_base' => (float) (data_get($inner, 'route_base_rate') ?? data_get($inner, 'base_rate') ?? $destLocalBase),
        ];

        $commissions = app(BranchCommissionService::class);
        $rates = [];
        foreach ($chain['branch_ids'] as $branchId) {
            $rates[$branchId] = $commissions->commissionRatePercent($branchId);
        }

        $result = BranchShareCalculator::splitItemized(
            $fare,
            $itemizedBreakdown,
            $chain['branch_ids'],
            $chain['transport_by_branch'],
            $this->rules(),
            $rates,
        );

        if ($fare <= 0) {
            $result['status'] = 'no_fare';
        }
        if ($extraInfo['reconciled']) {
            $result['flags'] = array_values(array_unique(array_merge($result['flags'] ?? [], ['extra_distance_reconciled'])));
        }

        $result['chain'] = $chain;
        $result['extra_info'] = $extraInfo;
        $result += $this->collection($shipment, $chain['branch_ids']);

        return $result;
    }

    public function resolveCoverageLocationId(int $branchId): ?int
    {
        if ($branchId <= 0) {
            return null;
        }

        $branch = DB::table('branches')->where('id', $branchId)->first();
        if (! $branch) {
            return null;
        }

        // 1. Direct coverage_location_id on branches table
        if (! empty($branch->coverage_location_id)) {
            return (int) $branch->coverage_location_id;
        }

        // 2. Coverage locations table pointing to this branch
        if (Schema::hasTable('coverage_locations') && Schema::hasColumn('coverage_locations', 'branch_id')) {
            $cov = DB::table('coverage_locations')
                ->where('branch_id', $branchId)
                ->value('id');
            if ($cov) {
                return (int) $cov;
            }
        }

        // 3. Parent branch coverage location
        if (! empty($branch->parent_id)) {
            $parent = DB::table('branches')->where('id', $branch->parent_id)->first();
            if ($parent && ! empty($parent->coverage_location_id)) {
                return (int) $parent->coverage_location_id;
            }
            if ($parent && Schema::hasTable('coverage_locations') && Schema::hasColumn('coverage_locations', 'branch_id')) {
                $parentCov = DB::table('coverage_locations')
                    ->where('branch_id', $parent->id)
                    ->value('id');
                if ($parentCov) {
                    return (int) $parentCov;
                }
            }
        }

        // 4. MainBranchResolverService if available
        if (class_exists(\Modules\Rate\Services\MainBranchResolverService::class)) {
            try {
                $resolver = app(\Modules\Rate\Services\MainBranchResolverService::class);
                $zone = $resolver->resolveZoneForBranch($branch);
                if ($zone && ! empty($zone->id)) {
                    return (int) $zone->id;
                }
            } catch (\Throwable $e) {
                // fall through
            }
        }

        return null;
    }

    public function resolveRouteBaseRate(int $originBranchId, int $destBranchId): float
    {
        if ($originBranchId <= 0 || $destBranchId <= 0) {
            return 299.0;
        }

        if ($originBranchId === $destBranchId) {
            return $this->resolveLocalBaseRate($destBranchId);
        }

        if (Schema::hasTable('branch_route_rates')) {
            // Modern schema: pickup_coverage_location_id & delivery_coverage_location_id
            if (Schema::hasColumn('branch_route_rates', 'pickup_coverage_location_id') &&
                Schema::hasColumn('branch_route_rates', 'delivery_coverage_location_id')) {
                $originCov = $this->resolveCoverageLocationId($originBranchId);
                $destCov = $this->resolveCoverageLocationId($destBranchId);

                if ($originCov && $destCov) {
                    $covRate = DB::table('branch_route_rates')
                        ->where('is_active', true)
                        ->where('pickup_coverage_location_id', $originCov)
                        ->where('delivery_coverage_location_id', $destCov)
                        ->orderByDesc('id')
                        ->value('base_rate');

                    if ($covRate !== null && (float) $covRate > 0) {
                        return (float) $covRate;
                    }

                    // Reverse direction fallback
                    $revRate = DB::table('branch_route_rates')
                        ->where('is_active', true)
                        ->where('pickup_coverage_location_id', $destCov)
                        ->where('delivery_coverage_location_id', $originCov)
                        ->orderByDesc('id')
                        ->value('base_rate');

                    if ($revRate !== null && (float) $revRate > 0) {
                        return (float) $revRate;
                    }
                }
            }

            // Legacy schema: only query pickup_branch_id if the column actually exists
            if (Schema::hasColumn('branch_route_rates', 'pickup_branch_id') &&
                Schema::hasColumn('branch_route_rates', 'delivery_branch_id')) {
                $rate = DB::table('branch_route_rates')
                    ->where('is_active', true)
                    ->where('pickup_branch_id', $originBranchId)
                    ->where('delivery_branch_id', $destBranchId)
                    ->orderByDesc('id')
                    ->value('base_rate');

                if ($rate !== null && (float) $rate > 0) {
                    return (float) $rate;
                }
            }
        }

        return 299.0;
    }

    public function resolveLocalBaseRate(?int $destinationBranchId): float
    {
        if (! $destinationBranchId) {
            return 99.0;
        }

        if (Schema::hasTable('branch_route_rates')) {
            // Modern schema: pickup_coverage_location_id & delivery_coverage_location_id
            if (Schema::hasColumn('branch_route_rates', 'pickup_coverage_location_id') &&
                Schema::hasColumn('branch_route_rates', 'delivery_coverage_location_id')) {
                $cov = $this->resolveCoverageLocationId($destinationBranchId);
                if ($cov) {
                    $covRate = DB::table('branch_route_rates')
                        ->where('is_active', true)
                        ->where('pickup_coverage_location_id', $cov)
                        ->where('delivery_coverage_location_id', $cov)
                        ->orderByDesc('id')
                        ->value('base_rate');

                    if ($covRate !== null && (float) $covRate > 0) {
                        return (float) $covRate;
                    }
                }
            }

            // Legacy schema: only query pickup_branch_id if the column actually exists
            if (Schema::hasColumn('branch_route_rates', 'pickup_branch_id') &&
                Schema::hasColumn('branch_route_rates', 'delivery_branch_id')) {
                $rate = DB::table('branch_route_rates')
                    ->where('is_active', true)
                    ->where('pickup_branch_id', $destinationBranchId)
                    ->where('delivery_branch_id', $destinationBranchId)
                    ->orderByDesc('id')
                    ->value('base_rate');

                if ($rate !== null && (float) $rate > 0) {
                    return (float) $rate;
                }
            }
        }

        // Check pricing_settings table for local base rate if any
        if (Schema::hasTable('pricing_settings')) {
            $pricing = DB::table('pricing_settings')->where('is_active', true)->latest('id')->first();
            if ($pricing && ! empty($pricing->local_base_rate) && (float) $pricing->local_base_rate > 0) {
                return (float) $pricing->local_base_rate;
            }
        }

        return 99.0;
    }

    /**
     * Run an end-to-end simulation using real database tables.
     */
    public function simulate(array $input): array
    {
        $originId = (int) ($input['origin_branch_id'] ?? 0);
        $destId = (int) ($input['destination_branch_id'] ?? 0);
        $transitIds = array_values(array_filter(array_map('intval', (array) ($input['transit_branch_ids'] ?? []))));

        $weightKg = max(0.1, (float) ($input['weight_kg'] ?? 1.0));
        $isFragile = filter_var($input['is_fragile'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $serviceType = strtolower(trim((string) ($input['service_type'] ?? 'standard')));
        $deliveryDistanceKm = max(0.0, (float) ($input['delivery_distance_km'] ?? 0.0));
        $productPricePod = max(0.0, (float) ($input['product_price_pod'] ?? 0.0));
        $transferHops = (array) ($input['transfer_hops'] ?? []);

        // Active pricing configuration from database
        $setting = Schema::hasTable('pricing_settings')
            ? DB::table('pricing_settings')->where('is_active', true)->latest('id')->first()
            : null;

        $baseDistanceKm = isset($input['base_distance_km']) && is_numeric($input['base_distance_km'])
            ? (float) $input['base_distance_km']
            : (float) ($setting->included_delivery_distance_km ?? $setting->base_distance_km ?? 5.0);

        $baseWeightKg = isset($input['base_weight_kg']) && is_numeric($input['base_weight_kg'])
            ? (float) $input['base_weight_kg']
            : (float) ($setting->included_weight_kg ?? $setting->base_weight_kg ?? 1.5);

        $isTransfer = $originId !== $destId;

        $weightRate = isset($input['weight_rate']) && is_numeric($input['weight_rate'])
            ? (float) $input['weight_rate']
            : ($isTransfer
                ? (float) ($setting->transfer_branch_excess_weight_rate ?? $setting->other_branch_weight_rate ?? $setting->transfer_extra_weight_rate ?? 30.0)
                : (float) ($setting->same_branch_excess_weight_rate ?? $setting->same_branch_weight_rate ?? $setting->local_extra_weight_rate ?? 20.0));

        $distRate = isset($input['extra_distance_rate']) && is_numeric($input['extra_distance_rate'])
            ? (float) $input['extra_distance_rate']
            : (float) ($setting->extra_distance_rate_per_km ?? $setting->extra_distance_rate ?? 6.0);
        $fragileMultiplier = (float) ($setting->fragile_multiplier ?? 1.05);
        $sameDayMultiplier = $isTransfer
            ? (float) ($setting->same_day_transfer_branch_multiplier ?? $setting->other_branch_sdd_multiplier ?? $setting->transfer_same_day_multiplier ?? 2.0)
            : (float) ($setting->same_day_same_branch_multiplier ?? $setting->same_branch_sdd_multiplier ?? $setting->local_same_day_multiplier ?? 1.5);
        $expressMultiplier = $isTransfer
            ? (float) ($setting->transfer_express_multiplier ?? 1.3)
            : (float) ($setting->local_express_multiplier ?? 1.2);

        $routeBaseRate = $this->resolveRouteBaseRate($originId, $destId);
        $destLocalBaseRate = $this->resolveLocalBaseRate($destId);

        $excessWeight = max(0.0, round($weightKg - $baseWeightKg, 3));
        $weightCharge = round($excessWeight * $weightRate, 2);

        $excessDistance = max(0.0, round($deliveryDistanceKm - $baseDistanceKm, 3));
        $distanceCharge = round($excessDistance * $distRate, 2);

        $subtotal = $routeBaseRate + $weightCharge + $distanceCharge;

        $fragileCharge = 0.0;
        if ($isFragile && ($setting->fragile_enabled ?? true)) {
            $fragileCharge = round($subtotal * max(0, $fragileMultiplier - 1), 2);
        }

        $serviceSurcharge = 0.0;
        if ($serviceType === 'same_day' && ($setting->same_day_enabled ?? true)) {
            $serviceSurcharge = round(($subtotal + $fragileCharge) * max(0, $sameDayMultiplier - 1), 2);
        } elseif ($serviceType === 'express' && ($setting->express_enabled ?? true)) {
            $serviceSurcharge = round(($subtotal + $fragileCharge) * max(0, $expressMultiplier - 1), 2);
        }

        $totalFare = round($subtotal + $fragileCharge + $serviceSurcharge, 2);
        $doorstepTotal = round($productPricePod + $totalFare, 2);

        // Process hops and transport costs
        $transportByBranch = [];
        $hopRows = [];

        $validTransferHops = array_values(array_filter($transferHops, function ($hop) {
            return ! empty($hop['from_branch_id']) && ! empty($hop['to_branch_id']) && (int) $hop['from_branch_id'] > 0 && (int) $hop['to_branch_id'] > 0;
        }));

        $allBranchIds = array_unique(array_filter(array_merge(
            [$originId, $destId],
            $transitIds,
            array_column($validTransferHops, 'from_branch_id'),
            array_column($validTransferHops, 'to_branch_id')
        )));

        $branchesMap = DB::table('branches')
            ->whereIn('id', $allBranchIds)
            ->pluck('name', 'id')
            ->map(fn ($n) => (string) $n)
            ->all();

        foreach ($validTransferHops as $hop) {
            $from = (int) ($hop['from_branch_id'] ?? 0);
            $to = (int) ($hop['to_branch_id'] ?? 0);
            $tripCost = max(0.0, (float) ($hop['trip_transport_cost'] ?? 0.0));
            $shipmentCount = max(1, (int) ($hop['total_shipments_on_tr'] ?? 1));
            $allocated = round($tripCost / $shipmentCount, 2);

            if ($from > 0) {
                $transportByBranch[$from] = round(($transportByBranch[$from] ?? 0) + $allocated, 2);
            }

            $hopRows[] = [
                'transfer_number' => (string) ($hop['transfer_number'] ?? 'TR-'.rand(100, 999)),
                'from_branch_id' => $from,
                'from_branch_name' => $branchesMap[$from] ?? 'Branch '.$from,
                'to_branch_id' => $to,
                'to_branch_name' => $branchesMap[$to] ?? 'Branch '.$to,
                'trip_transport_cost' => $tripCost,
                'total_shipments_on_tr' => $shipmentCount,
                'allocated_transport_cost' => $allocated,
            ];
        }

        // Ordered branch chain: origin -> transits in order -> destination
        $chain = [$originId];
        foreach ($transitIds as $tid) {
            if ($tid && ! in_array($tid, $chain, true) && $tid !== $destId) {
                $chain[] = $tid;
            }
        }
        if ($destId && ! in_array($destId, $chain, true)) {
            $chain[] = $destId;
        }

        $itemizedBreakdown = [
            'route_base' => $routeBaseRate,
            'dest_local_base' => $destLocalBaseRate,
            'extra_distance' => $distanceCharge,
            'extra_weight' => $weightCharge,
            'fragile' => $fragileCharge,
            'express' => $serviceType === 'express' ? $serviceSurcharge : 0.0,
            'same_day' => $serviceType === 'same_day' ? $serviceSurcharge : 0.0,
        ];

        $commissions = app(BranchCommissionService::class);
        $customComm = isset($input['hq_commission_percent']) && is_numeric($input['hq_commission_percent'])
            ? (float) $input['hq_commission_percent']
            : null;
        $hqRates = [];
        foreach ($chain as $bid) {
            $hqRates[$bid] = $customComm !== null ? $customComm : $commissions->commissionRatePercent($bid);
        }

        $activeRules = isset($input['rules']) && is_array($input['rules'])
            ? BranchShareCalculator::mergeRules($input['rules'])
            : $this->rules();

        $splitResult = BranchShareCalculator::splitItemized(
            $totalFare,
            $itemizedBreakdown,
            $chain,
            $transportByBranch,
            $activeRules,
            $hqRates
        );

        foreach ($splitResult['rows'] as &$row) {
            $row['branch_name'] = $branchesMap[$row['branch_id']] ?? 'Branch '.$row['branch_id'];
        }
        unset($row);

        $originName = $branchesMap[$originId] ?? 'Origin Branch';
        $destName = $branchesMap[$destId] ?? 'Destination Branch';

        $originRow = collect($splitResult['rows'])->firstWhere('branch_id', $originId);
        $destRow = collect($splitResult['rows'])->firstWhere('branch_id', $destId);
        $transitRows = collect($splitResult['rows'])->where('role', 'transit')->values()->all();

        $originNet = (float) ($originRow['net_amount'] ?? 0);
        $destNet = (float) ($destRow['net_amount'] ?? 0);

        // Ordered Cashflow Steps
        $cashFlowSteps = [];
        $cashFlowTransfers = [];

        // 1. Doorstep Collection
        $cashFlowSteps[] = [
            'step_number' => 1,
            'title' => 'Doorstep POD & Fare Collection',
            'from' => 'Customer / Consignee',
            'to' => $destName,
            'amount' => $doorstepTotal,
            'description' => "Delivering branch ({$destName}) collects full doorstep cash: Product COD (Rs {$productPricePod}) + Delivery Fare (Rs {$totalFare}).",
            'badge' => 'Doorstep Cash',
        ];

        // 2. Merchant Remittance
        $cashFlowSteps[] = [
            'step_number' => 2,
            'title' => 'Merchant Bank Account Remittance',
            'from' => $destName,
            'to' => 'Merchant Bank Account',
            'amount' => $doorstepTotal,
            'description' => "Delivering branch ({$destName}) deposits the full collected POD amount directly into the merchant's bank account.",
            'badge' => 'Merchant Direct Deposit',
        ];

        // 3. Delivery Fare Invoice
        $cashFlowSteps[] = [
            'step_number' => 3,
            'title' => 'Delivery Invoice Payment',
            'from' => 'Merchant',
            'to' => $originName,
            'amount' => $totalFare,
            'description' => "Origin branch ({$originName}) invoices the merchant for the delivery fare and collects payment, holding the full Rs {$totalFare}.",
            'badge' => 'Delivery Fare Invoice',
        ];

        // Multi-branch inter-settlement
        if ($originId !== $destId) {
            // 4. Origin pays Destination Delivery Share
            $cashFlowSteps[] = [
                'step_number' => 4,
                'title' => 'Destination Delivery Share Settlement',
                'from' => $originName,
                'to' => $destName,
                'amount' => $destNet,
                'description' => "Origin pays destination branch its delivery share: 70% local base (Rs {$destRow['base_share_amount']}) + distance (Rs {$destRow['extra_distance_amount']}) + weight (Rs {$destRow['weight_share_amount']}) minus 5% HQ commission.",
                'badge' => 'Delivery Share',
            ];
            $cashFlowTransfers[] = [
                'from_branch' => $originName,
                'to_branch' => $destName,
                'role' => 'delivery',
                'amount' => $destNet,
                'purpose' => "Destination delivery share (70% base Rs {$destRow['base_share_amount']} + distance Rs {$destRow['extra_distance_amount']} + weight Rs {$destRow['weight_share_amount']} net of 5% comm)",
            ];

            // 5. Origin remits Central Transfer Margin Pool to HQ
            // Origin holds $totalFare, pays $destNet, keeps its own $originNet. Remit the rest to HQ.
            $remitToHq = round(max(0, $totalFare - $destNet - $originNet), 2);
            if ($remitToHq > 0) {
                $cashFlowSteps[] = [
                    'step_number' => 5,
                    'title' => 'Transfer Margin & Surcharges Remittance to HQ Pool',
                    'from' => $originName,
                    'to' => 'HQ Central Settlement Pool',
                    'amount' => $remitToHq,
                    'description' => "Origin forwards the transfer margin pool, surcharges (fragile/express), HQ weight share & commissions to HQ. Origin retains its own net operational earnings of Rs {$originNet}.",
                    'badge' => 'Central Transfer Pool',
                ];
                $cashFlowTransfers[] = [
                    'from_branch' => $originName,
                    'to_branch' => 'SuperAdmin / HQ',
                    'role' => 'hq',
                    'amount' => $remitToHq,
                    'purpose' => 'Transfer margin pool, fragile/express surcharges, HQ weight share & branch commissions',
                ];
            }

            // 6. HQ centrally manages transport fleet & pays transit handling fees to each transit branch
            if (count($transitRows) > 0) {
                foreach ($transitRows as $idx => $tRow) {
                    $stepNum = 6 + $idx;
                    $transitFee = (float) ($tRow['transit_fee_amount'] ?? 10.0);
                    $netTransit = (float) $tRow['net_amount'];

                    $cashFlowSteps[] = [
                        'step_number' => $stepNum,
                        'title' => "HQ Pays Transit Handling Fee ({$tRow['branch_name']})",
                        'from' => 'HQ Central Settlement Pool',
                        'to' => $tRow['branch_name'],
                        'amount' => $netTransit,
                        'description' => "HQ pays transit handling fee of Rs {$transitFee} (net Rs {$netTransit} after 5% HQ commission) to {$tRow['branch_name']}. Line-haul transportation fleet is managed directly by HQ.",
                        'badge' => 'HQ Transit Fee',
                    ];
                    $cashFlowTransfers[] = [
                        'from_branch' => 'SuperAdmin / HQ',
                        'to_branch' => $tRow['branch_name'],
                        'role' => 'transit',
                        'amount' => $netTransit,
                        'purpose' => "HQ pays transit handling fee (Rs {$transitFee} net of 5% comm = Rs {$netTransit})",
                    ];
                }
            }
        }

        // Mermaid Sequence Diagram
        $cleanName = fn ($name) => preg_replace('/[^a-zA-Z0-9_]/', '', (string) $name);
        $destAlias = 'Dest_'.$cleanName($destName);
        $originAlias = 'Origin_'.$cleanName($originName);

        $mermaid = "sequenceDiagram\n";
        $mermaid .= "    autonumber\n";
        $mermaid .= "    actor Customer\n";
        $mermaid .= "    participant {$destAlias} as {$destName} (Delivery)\n";
        $mermaid .= "    participant Merchant\n";
        $mermaid .= "    participant {$originAlias} as {$originName} (Origin)\n";
        $mermaid .= "    participant HQ as SuperAdmin / HQ Pool\n";
        foreach ($transitRows as $t) {
            $tAlias = 'Transit_'.$cleanName($t['branch_name']);
            $mermaid .= "    participant {$tAlias} as {$t['branch_name']} (Transit Hub)\n";
        }
        $mermaid .= "    Customer->>{$destAlias}: Doorstep COD Cash (Rs {$doorstepTotal})\n";
        $mermaid .= "    {$destAlias}->>Merchant: Deposit Full POD to Merchant Bank (Rs {$doorstepTotal})\n";
        $mermaid .= "    Merchant->>{$originAlias}: Pay Delivery Fare Bill (Rs {$totalFare})\n";
        if ($originId !== $destId) {
            $mermaid .= "    {$originAlias}->>{$destAlias}: Pay Delivery Share (Rs {$destNet})\n";
            if (isset($remitToHq) && $remitToHq > 0) {
                $mermaid .= "    {$originAlias}->>HQ: Remit Transfer Margin Pool (Rs {$remitToHq})\n";
            }
            foreach ($transitRows as $t) {
                $tAlias = 'Transit_'.$cleanName($t['branch_name']);
                $mermaid .= "    HQ->>{$tAlias}: HQ Pays Transit Fee (Rs {$t['net_amount']})\n";
            }
        }

        $cashFlow = [
            'doorstep_collected_by' => $destName,
            'doorstep_collected_amount' => $doorstepTotal,
            'merchant_remittance_by' => $destName,
            'merchant_remittance_amount' => $doorstepTotal,
            'delivery_invoice_by' => $originName,
            'delivery_invoice_amount' => $totalFare,
            'has_transit' => count($transitRows) > 0,
            'transit_count' => count($transitRows),
            'steps' => $cashFlowSteps,
            'inter_branch_transfers' => $cashFlowTransfers,
            'mermaid_diagram' => $mermaid,
        ];

        return [
            'status' => 'success',
            'pricing' => [
                'route_base_rate' => $routeBaseRate,
                'dest_local_base_rate' => $destLocalBaseRate,
                'chargeable_weight_kg' => $weightKg,
                'included_weight_kg' => $baseWeightKg,
                'excess_weight_kg' => $excessWeight,
                'weight_rate_per_kg' => $weightRate,
                'weight_charge' => $weightCharge,
                'delivery_distance_km' => $deliveryDistanceKm,
                'included_distance_km' => $baseDistanceKm,
                'excess_distance_km' => $excessDistance,
                'distance_rate_per_km' => $distRate,
                'distance_charge' => $distanceCharge,
                'fragile_charge' => $fragileCharge,
                'service_type' => $serviceType,
                'service_surcharge' => $serviceSurcharge,
                'total_delivery_fare' => $totalFare,
                'product_price_pod' => $productPricePod,
                'total_doorstep_collection' => $doorstepTotal,
            ],
            'transfer_hops' => $hopRows,
            'transport_total_reimbursed' => $splitResult['transport_total'],
            'settlement' => $splitResult,
            'cash_flow' => $cashFlow,
            'rules_used' => $activeRules,
        ];
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
                    $shareAmount = isset($row['share_amount'])
                        ? (float) $row['share_amount']
                        : round((float) (($row['base_share_amount'] ?? 0) + ($row['weight_share_amount'] ?? 0) + ($row['transit_fee_amount'] ?? 0)), 2);
                    $pct = isset($row['percent'])
                        ? (float) $row['percent']
                        : ((float) $result['fare'] > 0 ? round(((float) $row['allocation_amount'] / (float) $result['fare']) * 100, 2) : 0.0);

                    $rows->push(ShipmentBranchShare::create([
                        'shipment_id' => $shipment->id,
                        'branch_id' => $row['branch_id'],
                        'role' => $row['role'],
                        'position' => $row['position'],
                        'branch_count' => $result['branch_count'],
                        'percent' => $pct,
                        'fare_amount' => $result['fare'],
                        'share_amount' => $shareAmount,
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

    /**
     * Branch-wise settlement and shares history report.
     */
    public function branchWiseReports(?string $from = null, ?string $to = null, ?int $filterBranchId = null, $user = null): array
    {
        $query = ShipmentBranchShare::query()
            ->join('branches', 'branches.id', '=', 'shipment_branch_shares.branch_id')
            ->join('shipments', 'shipments.id', '=', 'shipment_branch_shares.shipment_id');

        if ($from) {
            $query->whereDate('shipments.delivered_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('shipments.delivered_at', '<=', $to);
        }
        if ($filterBranchId) {
            $query->where('shipment_branch_shares.branch_id', $filterBranchId);
        }

        FinanceBranchScope::scopeShares($query, $user);

        $rows = (clone $query)
            ->selectRaw('
                shipment_branch_shares.branch_id,
                branches.name as branch_name,
                branches.code as branch_code,
                COUNT(DISTINCT shipment_branch_shares.shipment_id) as total_shipments,
                COUNT(CASE WHEN shipment_branch_shares.role = "origin" THEN 1 END) as origin_shipments,
                COUNT(CASE WHEN shipment_branch_shares.role = "transit" THEN 1 END) as transit_shipments,
                COUNT(CASE WHEN shipment_branch_shares.role = "delivery" THEN 1 END) as delivery_shipments,
                COALESCE(SUM(shipment_branch_shares.share_amount), 0) as total_share_amount,
                COALESCE(SUM(shipment_branch_shares.extra_distance_amount), 0) as total_extra_distance,
                COALESCE(SUM(shipment_branch_shares.allocation_amount), 0) as total_gross_allocation,
                COALESCE(SUM(shipment_branch_shares.hq_commission_amount), 0) as total_hq_commission,
                COALESCE(SUM(shipment_branch_shares.net_amount), 0) as total_net_payout,
                COALESCE(SUM(CASE WHEN shipment_branch_shares.status = "pending" THEN shipment_branch_shares.net_amount ELSE 0 END), 0) as pending_net,
                COALESCE(SUM(CASE WHEN shipment_branch_shares.status = "settled" THEN shipment_branch_shares.net_amount ELSE 0 END), 0) as settled_net
            ')
            ->groupBy('shipment_branch_shares.branch_id', 'branches.name', 'branches.code')
            ->orderByDesc('total_net_payout')
            ->get();

        $summary = [
            'total_branches' => $rows->count(),
            'total_shipments' => (int) $rows->sum('total_shipments'),
            'total_gross_allocation' => round((float) $rows->sum('total_gross_allocation'), 2),
            'total_hq_commission' => round((float) $rows->sum('total_hq_commission'), 2),
            'total_net_payout' => round((float) $rows->sum('total_net_payout'), 2),
            'pending_net' => round((float) $rows->sum('pending_net'), 2),
            'settled_net' => round((float) $rows->sum('settled_net'), 2),
        ];

        return [
            'summary' => $summary,
            'branches' => $rows,
            'from' => $from,
            'to' => $to,
        ];
    }
}
