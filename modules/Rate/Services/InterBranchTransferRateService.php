<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InterBranchTransferRateService
{
    public function calculate(
        int $fromBranchId,
        int $toBranchId,
        int $serviceTypeId,
        ?int $merchantId
    ): array {
        $route = $this->findRoute(
            $fromBranchId,
            $toBranchId
        );

        if (
            $fromBranchId !== $toBranchId &&
            !$route
        ) {
            throw ValidationException::withMessages([
                'delivery_address' => [
                    'No transfer-count configuration exists for this branch pair.',
                ],
            ]);
        }

        $transferCount = $route
            ? (int) $route->transfer_count
            : 0;

        $rate = $this->resolveRate(
            $transferCount,
            $serviceTypeId,
            $merchantId
        );

        if (!$rate) {
            throw ValidationException::withMessages([
                'delivery_address' => [
                    "No rate is configured for {$transferCount} transfer(s).",
                ],
            ]);
        }

        return [
            'from_branch_id' => $fromBranchId,
            'to_branch_id' => $toBranchId,
            'transfer_count' => $transferCount,
            'is_reverse_match' =>
                (bool) ($route->is_reverse_match ?? false),
            'rate_id' => (int) $rate->id,
            'rate_scope' => $this->scope($rate),
            'rate' => round((float) $rate->rate, 2),
        ];
    }

    private function findRoute(
        int $fromBranchId,
        int $toBranchId
    ): ?object {
        if ($fromBranchId === $toBranchId) {
            return null;
        }

        $direct = DB::table(
            'inter_branch_transfer_counts'
        )
            ->where('from_branch_id', $fromBranchId)
            ->where('to_branch_id', $toBranchId)
            ->where('is_active', true)
            ->first();

        if ($direct) {
            $direct->is_reverse_match = false;

            return $direct;
        }

        $reverse = DB::table(
            'inter_branch_transfer_counts'
        )
            ->where('from_branch_id', $toBranchId)
            ->where('to_branch_id', $fromBranchId)
            ->where('is_bidirectional', true)
            ->where('is_active', true)
            ->first();

        if ($reverse) {
            $reverse->is_reverse_match = true;
        }

        return $reverse;
    }

    private function resolveRate(
        int $transferCount,
        int $serviceTypeId,
        ?int $merchantId
    ): ?object {
        return $this->resolveByPriority(
            'transfer_count_rates',
            [
                'transfer_count' => $transferCount,
            ],
            $serviceTypeId,
            $merchantId
        );
    }

    private function resolveByPriority(
        string $table,
        array $requiredConditions,
        int $serviceTypeId,
        ?int $merchantId
    ): ?object {
        $priorities = [];

        if ($merchantId !== null) {
            $priorities[] = [
                'merchant_id' => $merchantId,
                'service_type_id' => $serviceTypeId,
            ];

            $priorities[] = [
                'merchant_id' => $merchantId,
                'service_type_id' => null,
            ];
        }

        $priorities[] = [
            'merchant_id' => null,
            'service_type_id' => $serviceTypeId,
        ];

        $priorities[] = [
            'merchant_id' => null,
            'service_type_id' => null,
        ];

        foreach ($priorities as $priority) {
            $query = DB::table($table)
                ->where($requiredConditions)
                ->where('is_active', true);

            $priority['merchant_id'] === null
                ? $query->whereNull('merchant_id')
                : $query->where(
                    'merchant_id',
                    $priority['merchant_id']
                );

            $priority['service_type_id'] === null
                ? $query->whereNull('service_type_id')
                : $query->where(
                    'service_type_id',
                    $priority['service_type_id']
                );

            $result = $query
                ->orderByDesc('id')
                ->first();

            if ($result) {
                return $result;
            }
        }

        return null;
    }

    private function scope(object $rate): string
    {
        if (
            $rate->merchant_id !== null &&
            $rate->service_type_id !== null
        ) {
            return 'merchant_service';
        }

        if ($rate->merchant_id !== null) {
            return 'merchant';
        }

        if ($rate->service_type_id !== null) {
            return 'service';
        }

        return 'global';
    }
}