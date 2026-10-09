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
        2 => [60, 40],
        3 => [45, 20, 35],
        4 => [35, 20, 20, 25],
        5 => [31, 16, 16, 16, 21],
    ];

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
