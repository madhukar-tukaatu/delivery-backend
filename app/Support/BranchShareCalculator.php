<?php

namespace App\Support;

/**
 * Pure money maths for transfer delivery-charge settlement.
 * No database or framework calls, so it can be unit tested directly.
 *
 * All arithmetic is done in integer paisa so rows always add up exactly.
 */
final class BranchShareCalculator
{
    public const DEFAULT_TABLE = [
        1 => [100],
        2 => [30, 70],
        3 => [18, 40, 42],
        4 => [18, 20, 20, 42],
        5 => [18, 13.3333, 13.3334, 13.3333, 42],
    ];

    public const DEFAULT_RULES = [
        'local_delivery' => [
            'branch_percent' => 100.0,
            'hq_commission_percent' => 5.0,
        ],
        'transfer_delivery' => [
            'base_rate_split' => [
                'origin_percent' => 30.0,
                'destination_percent' => 70.0,
            ],
            'extra_weight_split' => [
                'hq_percent' => 50.0,
                'branch_origin_percent' => 30.0,
                'branch_destination_percent' => 70.0,
            ],
            'extra_distance_split' => [
                'destination_percent' => 100.0,
            ],
            'fragile_split' => [
                'hq_percent' => 100.0,
            ],
            'express_split' => [
                'hq_percent' => 100.0,
            ],
            'transit_handling_fee' => [
                'mode' => 'fixed', // 'fixed' (Rs 10/hub) or 'percent'
                'fixed_amount_per_branch' => 10.0,
                'transit_percent' => 40.0,
                'hq_percent' => 60.0,
            ],
            'transfer_margin_split' => [
                'mode' => 'fixed',
                'fixed_amount_per_branch' => 10.0,
                'transit_percent' => 40.0,
                'hq_percent' => 60.0,
                'by_hop_count' => [
                    1 => [40.0],
                    2 => [20.0, 20.0],
                    3 => [13.3333, 13.3334, 13.3333],
                ],
            ],
            'hq_commission_percent' => 5.0,
        ],
    ];

    /**
     * Itemized delivery charge settlement based on dynamic transfer share rules.
     *
     * @param  float  $fare  Total collected delivery charge F
     * @param  array{
     *     route_base?: float,
     *     dest_local_base?: float,
     *     extra_distance?: float,
     *     extra_weight?: float,
     *     fragile?: float,
     *     express?: float,
     *     same_day?: float,
     * }  $breakdown  Itemized charge breakdown
     * @param  list<int>  $branchIds  Ordered: origin, transit hops in sequence, delivery branch last
     * @param  array<int,float>  $transportByBranch  branch_id => transport cost paid on dispatch legs
     * @param  array  $rules  Dynamic transfer share rules configuration
     * @param  array<int,float>|float  $hqRates  HQ commission rate(s)
     * @return array
     */
    public static function splitItemized(
        float $fare,
        array $breakdown,
        array $branchIds,
        array $transportByBranch,
        array $rules = [],
        array|float $hqRates = 5.0,
    ): array {
        $branchIds = array_values(array_map('intval', $branchIds));
        $n = count($branchIds);
        $flags = [];

        $rules = self::mergeRules($rules);
        $transferRules = $rules['transfer_delivery'];
        $localRules = $rules['local_delivery'];

        $fareP = max(0, self::p($fare));
        $extraDistanceP = max(0, self::p((float) ($breakdown['extra_distance'] ?? 0)));
        $extraWeightP = max(0, self::p((float) ($breakdown['extra_weight'] ?? 0)));
        $fragileP = max(0, self::p((float) ($breakdown['fragile'] ?? 0)));
        $expressP = max(0, self::p((float) (($breakdown['express'] ?? 0) + ($breakdown['same_day'] ?? 0))));
        
        $destLocalBase = isset($breakdown['dest_local_base']) && (float) $breakdown['dest_local_base'] > 0
            ? (float) $breakdown['dest_local_base']
            : ($breakdown['route_base'] ?? $fare);
        $destLocalBaseP = max(0, self::p((float) $destLocalBase));

        if ($destLocalBaseP > $fareP) {
            $destLocalBaseP = $fareP;
            $flags[] = 'dest_local_base_capped';
        }

        $base = [
            'status' => 'computed',
            'flags' => $flags,
            'fare' => self::m($fareP),
            'dest_local_base' => self::m($destLocalBaseP),
            'extra_distance' => self::m($extraDistanceP),
            'extra_weight' => self::m($extraWeightP),
            'fragile' => self::m($fragileP),
            'express' => self::m($expressP),
            'transport_total' => 0.0,
            'transfer_margin_gross' => 0.0,
            'transfer_margin_residual' => 0.0,
            'branch_count' => $n,
            'rows' => [],
            'hq_breakdown' => [
                'weight_amount' => 0.0,
                'fragile_amount' => 0.0,
                'express_amount' => 0.0,
                'margin_amount' => 0.0,
                'commissions_amount' => 0.0,
                'total_amount' => 0.0,
            ],
            'hq_total' => 0.0,
        ];

        if ($n === 0) {
            $base['status'] = 'no_branches';
            return $base;
        }

        // Single local branch delivery
        if ($n === 1) {
            $hqRate = is_array($hqRates)
                ? (float) ($hqRates[$branchIds[0]] ?? ($hqRates['default'] ?? $localRules['hq_commission_percent']))
                : (float) $hqRates;
            $hqP = (int) round($fareP * $hqRate / 100);
            $netP = $fareP - $hqP;

            $base['rows'] = [[
                'branch_id' => $branchIds[0],
                'position' => 1,
                'role' => 'delivery',
                'base_share_amount' => self::m($destLocalBaseP),
                'extra_distance_amount' => self::m($extraDistanceP),
                'weight_share_amount' => self::m($extraWeightP),
                'fragile_share_amount' => self::m($fragileP),
                'express_share_amount' => self::m($expressP),
                'transport_amount' => 0.0,
                'transit_fee_amount' => 0.0,
                'allocation_amount' => self::m($fareP),
                'hq_rate' => $hqRate,
                'hq_commission_amount' => self::m($hqP),
                'net_amount' => self::m($netP),
            ]];
            $base['hq_breakdown']['commissions_amount'] = self::m($hqP);
            $base['hq_breakdown']['total_amount'] = self::m($hqP);
            $base['hq_total'] = self::m($hqP);
            return $base;
        }

        // --- Multi-Branch Transfer Delivery ---
        // 1. Destination Local Base Rate Split (30% Origin / 70% Destination)
        $originBasePct = (float) (
            $transferRules['base_rate_split']['origin_percent']
            ?? $transferRules['dest_local_base_share']['origin_percent']
            ?? 30.0
        );
        $originBaseP = (int) round($destLocalBaseP * $originBasePct / 100);
        $destBaseP = $destLocalBaseP - $originBaseP;

        // 2. Extra Last-Mile Distance (100% to Delivery Branch)
        $destExtraDistP = $extraDistanceP;

        // 3. Extra Weight Split (50% HQ, remaining 30% Origin / 70% Destination)
        $hqWeightPct = (float) ($transferRules['extra_weight_split']['hq_percent'] ?? 50.0);
        $hqWeightP = (int) round($extraWeightP * $hqWeightPct / 100);
        $branchesWeightP = $extraWeightP - $hqWeightP;

        $originWeightPct = (float) ($transferRules['extra_weight_split']['branch_origin_percent'] ?? 30.0);
        $originWeightP = (int) round($branchesWeightP * $originWeightPct / 100);
        $destWeightP = $branchesWeightP - $originWeightP;

        // 4. Fragile & Express (100% HQ by default)
        $hqFragileP = $fragileP;
        $hqExpressP = $expressP;

        // 5. Transfer Margin Pool (HQ manages transportation fleet directly; no branch transport refunds)
        // Margin Pool = Fare - LocalBase - ExtraDistance - ExtraWeight - Fragile - Express
        $marginPoolP = max(0, $fareP - $destLocalBaseP - $destExtraDistP - $extraWeightP - $fragileP - $expressP);
        $base['transfer_margin_gross'] = self::m($marginPoolP);
        $base['transport_total'] = 0.0;
        $base['transfer_margin_residual'] = self::m($marginPoolP);

        // 6. Transit Branch Handling Fee (HQ pays transit branch Rs 10 per shipment handled from transfer margin)
        $transitCount = max(0, $n - 2);
        $transitTotalP = 0;
        $transitPerBranchP = array_fill(0, $n, 0);

        if ($transitCount > 0) {
            $feeConfig = $transferRules['transit_handling_fee'] ?? $transferRules['transfer_margin_split'] ?? [];
            $mode = $feeConfig['mode'] ?? 'fixed';

            if ($mode === 'fixed') {
                $fixedFee = max(0.0, (float) ($feeConfig['fixed_amount_per_branch'] ?? 10.0));
                $fixedFeeP = self::p($fixedFee);
                for ($i = 1; $i <= $transitCount; $i++) {
                    $transitPerBranchP[$i] = $fixedFeeP;
                    $transitTotalP += $fixedFeeP;
                }
            } else {
                $customHops = $transferRules['transfer_margin_split']['by_hop_count'][$transitCount] ?? null;
                if (is_array($customHops) && count($customHops) === $transitCount) {
                    $allocatedTransit = 0;
                    foreach ($customHops as $idx => $pct) {
                        $pos = $idx + 1; // transit indices 1 to n-2
                        $part = (int) round($marginPoolP * (float) $pct / 100);
                        $transitPerBranchP[$pos] = $part;
                        $allocatedTransit += $part;
                    }
                    $transitTotalP = $allocatedTransit;
                } else {
                    $transitPct = (float) ($transferRules['transfer_margin_split']['transit_percent'] ?? 40.0);
                    $transitTotalP = (int) round($marginPoolP * $transitPct / 100);

                    // Distribute across transit branches (indices 1 to n - 2)
                    $allocatedTransit = 0;
                    for ($i = 1; $i <= $transitCount; $i++) {
                        if ($i === $transitCount) {
                            $transitPerBranchP[$i] = $transitTotalP - $allocatedTransit;
                        } else {
                            $part = intdiv($transitTotalP, $transitCount);
                            $transitPerBranchP[$i] = $part;
                            $allocatedTransit += $part;
                        }
                    }
                }
            }
        }
        $hqMarginP = max(0, $marginPoolP - $transitTotalP);

        // 7. Branch Allocations & Roles (Transportation managed by HQ; branches receive operational revenue)
        $allocP = [];
        $operIncomeP = [];
        $rates = [];

        foreach ($branchIds as $i => $branchId) {
            $rates[$i] = is_array($hqRates)
                ? (float) ($hqRates[$branchId] ?? ($hqRates['default'] ?? $transferRules['hq_commission_percent']))
                : (float) $hqRates;

            if ($i === 0) {
                // Origin
                $operIncome = $originBaseP + $originWeightP;
            } elseif ($i === $n - 1) {
                // Destination
                $operIncome = $destBaseP + $destExtraDistP + $destWeightP;
            } else {
                // Transit
                $operIncome = $transitPerBranchP[$i];
            }

            $allocP[$i] = $operIncome;
            $operIncomeP[$i] = $operIncome;
        }

        // 8. HQ Commission on Branch Operational Income
        $hqCommP = [];
        foreach ($branchIds as $i => $_) {
            $hqCommP[$i] = (int) round($operIncomeP[$i] * $rates[$i] / 100);
        }
        $totalHqCommP = array_sum($hqCommP);

        // Build Rows
        $rows = [];
        foreach ($branchIds as $i => $branchId) {
            $role = ($i === 0) ? 'origin' : (($i === $n - 1) ? 'delivery' : 'transit');
            $rows[] = [
                'branch_id' => $branchId,
                'position' => $i + 1,
                'role' => $role,
                'base_share_amount' => self::m($i === 0 ? $originBaseP : ($i === $n - 1 ? $destBaseP : 0)),
                'extra_distance_amount' => self::m($i === $n - 1 ? $destExtraDistP : 0),
                'weight_share_amount' => self::m($i === 0 ? $originWeightP : ($i === $n - 1 ? $destWeightP : 0)),
                'fragile_share_amount' => 0.0,
                'express_share_amount' => 0.0,
                'transport_amount' => 0.0,
                'transit_fee_amount' => self::m($transitPerBranchP[$i]),
                'operational_income' => self::m($operIncomeP[$i]),
                'allocation_amount' => self::m($allocP[$i]),
                'hq_rate' => $rates[$i],
                'hq_commission_amount' => self::m($hqCommP[$i]),
                'net_amount' => self::m($allocP[$i] - $hqCommP[$i]),
            ];
        }

        $hqTotalP = $hqWeightP + $hqFragileP + $hqExpressP + $hqMarginP + $totalHqCommP;

        $base['flags'] = array_values(array_unique($flags));
        $base['rows'] = $rows;
        $base['hq_breakdown'] = [
            'weight_amount' => self::m($hqWeightP),
            'fragile_amount' => self::m($hqFragileP),
            'express_amount' => self::m($hqExpressP),
            'margin_amount' => self::m($hqMarginP),
            'commissions_amount' => self::m($totalHqCommP),
            'total_amount' => self::m($hqTotalP),
        ];
        $base['hq_total'] = self::m($hqTotalP);

        return $base;
    }

    /**
     * Merge given rules with defaults.
     */
    public static function mergeRules(array $rules): array
    {
        $defaults = self::DEFAULT_RULES;
        $transitHandling = array_merge(
            $defaults['transfer_delivery']['transit_handling_fee'],
            $rules['transfer_delivery']['transit_handling_fee'] ?? []
        );
        if (isset($rules['transfer_delivery']['transfer_margin_split']['mode'])) {
            $transitHandling['mode'] = $rules['transfer_delivery']['transfer_margin_split']['mode'];
        }
        if (isset($rules['transfer_delivery']['transfer_margin_split']['fixed_amount_per_branch'])) {
            $transitHandling['fixed_amount_per_branch'] = (float) $rules['transfer_delivery']['transfer_margin_split']['fixed_amount_per_branch'];
        }

        return [
            'local_delivery' => array_merge($defaults['local_delivery'], $rules['local_delivery'] ?? []),
            'transfer_delivery' => [
                'base_rate_split' => array_merge($defaults['transfer_delivery']['base_rate_split'], $rules['transfer_delivery']['base_rate_split'] ?? []),
                'extra_weight_split' => array_merge($defaults['transfer_delivery']['extra_weight_split'], $rules['transfer_delivery']['extra_weight_split'] ?? []),
                'extra_distance_split' => array_merge($defaults['transfer_delivery']['extra_distance_split'], $rules['transfer_delivery']['extra_distance_split'] ?? []),
                'fragile_split' => array_merge($defaults['transfer_delivery']['fragile_split'], $rules['transfer_delivery']['fragile_split'] ?? []),
                'express_split' => array_merge($defaults['transfer_delivery']['express_split'], $rules['transfer_delivery']['express_split'] ?? []),
                'transit_handling_fee' => $transitHandling,
                'transfer_margin_split' => array_merge($defaults['transfer_delivery']['transfer_margin_split'], $rules['transfer_delivery']['transfer_margin_split'] ?? [], [
                    'mode' => $transitHandling['mode'],
                    'fixed_amount_per_branch' => $transitHandling['fixed_amount_per_branch'],
                ]),
                'hq_commission_percent' => (float) ($rules['transfer_delivery']['hq_commission_percent'] ?? $defaults['transfer_delivery']['hq_commission_percent']),
            ],
        ];
    }

    /**
     * Split one shipment's delivery fare across the branches that handled it.
     *
     * @param  float  $fare  full delivery charge F
     * @param  float  $extraDistance  stored extra last-mile distance charge (goes to the delivery branch)
     * @param  list<int>  $branchIds  ordered: origin/pickup first, transit in hop order, delivery last
     * @param  array<int,float>  $transportByBranch  branch_id => transport cost entered on that branch's dispatch legs
     * @param  array<int,list<float|int>>  $table  branch count => percents (position order)
     * @param  array<int,float>|float  $hqRates  one HQ percent for all branches, or branch_id => percent
     * @return array{status:string, flags:list<string>, fare:float, extra:float, transport_total:float, shared:float, branch_count:int, percents:list<float>, rows:list<array>, hq_total:float}
     */
    public static function split(
        float $fare,
        float $extraDistance,
        array $branchIds,
        array $transportByBranch,
        array $table,
        array|float $hqRates,
    ): array {
        $branchIds = array_values(array_map('intval', $branchIds));
        $n = count($branchIds);
        $flags = [];

        $fareP = max(0, self::p($fare));
        $extraP = max(0, self::p($extraDistance));
        if ($extraP > $fareP) {
            $extraP = $fareP;
            $flags[] = 'extra_distance_capped';
        }

        $base = [
            'status' => 'computed',
            'flags' => $flags,
            'fare' => self::m($fareP),
            'extra' => self::m($extraP),
            'transport_total' => 0.0,
            'shared' => 0.0,
            'branch_count' => $n,
            'percents' => [],
            'rows' => [],
            'hq_total' => 0.0,
        ];

        if ($n === 0) {
            $base['status'] = 'no_branches';

            return $base;
        }

        $percents = $table[$n] ?? null;
        if (! is_array($percents) || count($percents) !== $n) {
            $base['status'] = 'pending_config';

            return $base;
        }
        $percents = array_values(array_map('floatval', $percents));
        $base['percents'] = $percents;

        // Transport per position. Dispatching branches not in the chain fall back to origin.
        $transportP = array_fill(0, $n, 0);
        foreach ($transportByBranch as $branchId => $amount) {
            $amountP = max(0, self::p((float) $amount));
            if ($amountP === 0) {
                continue;
            }
            $pos = array_search((int) $branchId, $branchIds, true);
            if ($pos === false) {
                $pos = 0;
                $flags[] = 'transport_branch_not_in_chain';
            }
            $transportP[$pos] += $amountP;
        }

        $available = $fareP - $extraP;
        $transportTotalP = array_sum($transportP);
        if ($transportTotalP > $available) {
            $flags[] = 'transport_shortfall';
            $transportP = self::scaleDown($transportP, $available);
            $transportTotalP = array_sum($transportP);
        }

        $sharedP = $fareP - $extraP - $transportTotalP;

        $shareP = [];
        foreach ($percents as $i => $pct) {
            $shareP[$i] = (int) round($sharedP * $pct / 100);
        }
        // Rounding remainder goes to the delivery branch (last position).
        $shareP[$n - 1] += $sharedP - array_sum($shareP);

        $allocP = [];
        foreach ($branchIds as $i => $_) {
            $allocP[$i] = $shareP[$i] + $transportP[$i] + ($i === $n - 1 ? $extraP : 0);
        }

        // HQ commission on each branch's own allocation.
        $rates = [];
        foreach ($branchIds as $i => $branchId) {
            $rates[$i] = is_array($hqRates)
                ? (float) ($hqRates[$branchId] ?? ($hqRates['default'] ?? 0))
                : (float) $hqRates;
        }
        $hqP = [];
        foreach ($allocP as $i => $a) {
            $hqP[$i] = (int) round($a * $rates[$i] / 100);
        }
        if (count(array_unique(array_map('strval', $rates))) === 1) {
            $target = (int) round($fareP * $rates[0] / 100);
            $diff = $target - array_sum($hqP);
            if ($diff !== 0) {
                $hqP[$n - 1] = max(0, min($allocP[$n - 1], $hqP[$n - 1] + $diff));
            }
        }

        $rows = [];
        foreach ($branchIds as $i => $branchId) {
            $rows[] = [
                'branch_id' => $branchId,
                'position' => $i + 1,
                'role' => $n === 1 ? 'delivery' : ($i === 0 ? 'origin' : ($i === $n - 1 ? 'delivery' : 'transit')),
                'percent' => $percents[$i],
                'share_amount' => self::m($shareP[$i]),
                'transport_amount' => self::m($transportP[$i]),
                'extra_distance_amount' => self::m($i === $n - 1 ? $extraP : 0),
                'allocation_amount' => self::m($allocP[$i]),
                'hq_rate' => $rates[$i],
                'hq_commission_amount' => self::m($hqP[$i]),
                'net_amount' => self::m($allocP[$i] - $hqP[$i]),
            ];
        }

        $base['flags'] = array_values(array_unique($flags));
        $base['transport_total'] = self::m($transportTotalP);
        $base['shared'] = self::m($sharedP);
        $base['rows'] = $rows;
        $base['hq_total'] = self::m(array_sum($hqP));

        return $base;
    }

    /**
     * Allocate a manifest's total transport cost over its items.
     *
     * @param  array<int|string,float|null>  $weights  item key => weight (used for mode=weight)
     * @return array<int|string,float> item key => amount
     */
    public static function allocateTransport(float $total, array $weights, string $mode = 'equal'): array
    {
        $keys = array_keys($weights);
        $count = count($keys);
        if ($count === 0) {
            return [];
        }

        $totalP = max(0, self::p($total));
        $out = [];

        $w = array_map(static fn ($v) => max(0.0, (float) ($v ?? 0)), array_values($weights));
        $sumW = array_sum($w);
        $useWeight = $mode === 'weight' && $sumW > 0 && ! in_array(0.0, $w, true);

        $allocated = 0;
        foreach ($keys as $i => $key) {
            if ($i === $count - 1) {
                $out[$key] = self::m($totalP - $allocated);
                break;
            }
            $part = $useWeight
                ? intdiv((int) round($totalP * $w[$i] * 1000), (int) round($sumW * 1000))
                : intdiv($totalP, $count);
            $out[$key] = self::m($part);
            $allocated += $part;
        }

        return $out;
    }

    /**
     * Validate a percent table. Returns a list of error strings (empty = valid).
     *
     * @param  array<int|string,mixed>  $table
     * @return list<string>
     */
    public static function validateTable(array $table): array
    {
        $errors = [];
        if ($table === []) {
            return ['The table needs at least one row.'];
        }
        foreach ($table as $count => $row) {
            $count = (int) $count;
            if ($count < 1) {
                $errors[] = "Row key {$count} must be a branch count of 1 or more.";

                continue;
            }
            if (! is_array($row) || count($row) !== $count) {
                $errors[] = "Row for {$count} branch(es) must have exactly {$count} percent value(s).";

                continue;
            }
            foreach ($row as $v) {
                if (! is_numeric($v) || (float) $v < 0) {
                    $errors[] = "Row for {$count} branch(es) has a value that is not a positive number.";

                    continue 2;
                }
            }
            $sum = round(array_sum(array_map('floatval', $row)), 4);
            if (abs($sum - 100) > 0.0001) {
                $errors[] = "Row for {$count} branch(es) adds up to {$sum}, it must be 100.";
            }
        }

        return $errors;
    }

    /** @param list<int> $parts */
    private static function scaleDown(array $parts, int $available): array
    {
        $total = array_sum($parts);
        if ($total <= 0 || $available <= 0) {
            return array_fill(0, count($parts), 0);
        }
        $out = [];
        $lastPositive = 0;
        foreach ($parts as $i => $p) {
            $out[$i] = intdiv($p * $available, $total);
            if ($p > 0) {
                $lastPositive = $i;
            }
        }
        $out[$lastPositive] += $available - array_sum($out);

        return $out;
    }

    private static function p(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private static function m(int $paisa): float
    {
        return round($paisa / 100, 2);
    }
}
