<?php

namespace Database\Seeders\Pricing;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class MarketplaceCoreNetworkSeeder extends Seeder
{
    /*
    |--------------------------------------------------------------------------
    | Starter customer-facing rates
    |--------------------------------------------------------------------------
    |
    | These are test/starter rates.
    | They can later be changed from the Branch Pricing admin page.
    |
    */

    private const SAME_BRANCH_RATE = 79.00;

    private const KTM_PKR_RATE = 149.00;
    private const KTM_BRT_RATE = 149.00;
    private const PKR_BRT_RATE = 249.00;

    private const KTM_MUSTANG_RATE = 349.00;
    private const PKR_MUSTANG_RATE = 249.00;

    public function run(): void
    {
        $this->validateTables();

        /*
        |--------------------------------------------------------------------------
        | Resolve actual operational branches from coverage locations
        |--------------------------------------------------------------------------
        |
        | This avoids old/legacy branch IDs such as Kathmandu branch ID 1.
        |
        */

        $branches = [
            'ktm' => $this->branchFromCoverage(
                coverageCodes: [
                    'KTM-MAIN-ZONE',
                ],
                label: 'Kathmandu'
            ),

            'pkr' => $this->branchFromCoverage(
                coverageCodes: [
                    'PKR-MAIN-ZONE',
                ],
                label: 'Pokhara'
            ),

            'brt' => $this->branchFromCoverage(
                coverageCodes: [
                    'BRT-MAIN-ZONE',
                ],
                label: 'Biratnagar'
            ),

            'mustang' => $this->branchFromCoverage(
                coverageCodes: [
                    /*
                     * Your current Mustang allocation code.
                     */
                    'MUS-MAIN-ZONE',

                    /*
                     * Keep fallback support for an older code.
                     */
                    'MST-MAIN-ZONE',
                ],
                label: 'Mustang'
            ),
        ];

        $this->ensureUniqueBranches($branches);

        DB::transaction(function () use (
            $branches
        ): void {
            /*
            |--------------------------------------------------------------------------
            | Remove only the old core-network data
            |--------------------------------------------------------------------------
            |
            | This does not delete rates or transfer lanes for other branches.
            |
            */

            $this->clearCoreNetwork($branches);

            /*
            |--------------------------------------------------------------------------
            | Same-branch customer rates
            |--------------------------------------------------------------------------
            |
            | No transfer lane is required when pickup and delivery resolve
            | to the same responsible main branch.
            |
            */

            $this->saveRate(
                pickupBranchId: $branches['ktm']['id'],
                deliveryBranchId: $branches['ktm']['id'],
                baseRate: self::SAME_BRANCH_RATE
            );

            $this->saveRate(
                pickupBranchId: $branches['pkr']['id'],
                deliveryBranchId: $branches['pkr']['id'],
                baseRate: self::SAME_BRANCH_RATE
            );

            $this->saveRate(
                pickupBranchId: $branches['brt']['id'],
                deliveryBranchId: $branches['brt']['id'],
                baseRate: self::SAME_BRANCH_RATE
            );

            $this->saveRate(
                pickupBranchId: $branches['mustang']['id'],
                deliveryBranchId: $branches['mustang']['id'],
                baseRate: self::SAME_BRANCH_RATE
            );

            /*
            |--------------------------------------------------------------------------
            | Customer-facing branch route rates
            |--------------------------------------------------------------------------
            |
            | These determine the delivery charge.
            | They do not determine how the parcel physically moves.
            |
            */

            $this->saveBidirectionalRate(
                firstBranchId: $branches['ktm']['id'],
                secondBranchId: $branches['pkr']['id'],
                baseRate: self::KTM_PKR_RATE
            );

            $this->saveBidirectionalRate(
                firstBranchId: $branches['ktm']['id'],
                secondBranchId: $branches['brt']['id'],
                baseRate: self::KTM_BRT_RATE
            );

            $this->saveBidirectionalRate(
                firstBranchId: $branches['pkr']['id'],
                secondBranchId: $branches['brt']['id'],
                baseRate: self::PKR_BRT_RATE
            );

            $this->saveBidirectionalRate(
                firstBranchId: $branches['ktm']['id'],
                secondBranchId: $branches['mustang']['id'],
                baseRate: self::KTM_MUSTANG_RATE
            );

            $this->saveBidirectionalRate(
                firstBranchId: $branches['pkr']['id'],
                secondBranchId: $branches['mustang']['id'],
                baseRate: self::PKR_MUSTANG_RATE
            );

            /*
            |--------------------------------------------------------------------------
            | Physical transfer lanes
            |--------------------------------------------------------------------------
            |
            | Reverse directions are stored explicitly.
            |
            | No direct Kathmandu → Mustang lane is created.
            | The resolver must use:
            |
            | Kathmandu → Pokhara → Mustang
            |
            | No direct Pokhara → Biratnagar lane is created.
            | The resolver must use:
            |
            | Pokhara → Kathmandu → Biratnagar
            |
            */

            /*
             * Kathmandu ↔ Pokhara
             */
            $this->saveTransferLane(
                fromBranchId: $branches['ktm']['id'],
                toBranchId: $branches['pkr']['id'],
                distanceKm: 200,
                estimatedHours: 8
            );

            $this->saveTransferLane(
                fromBranchId: $branches['pkr']['id'],
                toBranchId: $branches['ktm']['id'],
                distanceKm: 200,
                estimatedHours: 8
            );

            /*
             * Kathmandu ↔ Biratnagar
             */
            $this->saveTransferLane(
                fromBranchId: $branches['ktm']['id'],
                toBranchId: $branches['brt']['id'],
                distanceKm: 390,
                estimatedHours: 12
            );

            $this->saveTransferLane(
                fromBranchId: $branches['brt']['id'],
                toBranchId: $branches['ktm']['id'],
                distanceKm: 390,
                estimatedHours: 12
            );

            /*
             * Pokhara ↔ Mustang
             */
            $this->saveTransferLane(
                fromBranchId: $branches['pkr']['id'],
                toBranchId: $branches['mustang']['id'],
                distanceKm: 170,
                estimatedHours: 8
            );

            $this->saveTransferLane(
                fromBranchId: $branches['mustang']['id'],
                toBranchId: $branches['pkr']['id'],
                distanceKm: 170,
                estimatedHours: 8
            );
        }, 3);

        $this->showSummary($branches);
    }

    private function validateTables(): void
    {
        $requiredTables = [
            'branches',
            'coverage_locations',
            'branch_route_rates',
            'branch_transfer_lanes',
        ];

        foreach ($requiredTables as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException(
                    "Required table '{$table}' does not exist."
                );
            }
        }
    }

    /**
     * Resolve the active operational branch allocated to a
     * main coverage location.
     */
    private function branchFromCoverage(
        array $coverageCodes,
        string $label
    ): array {
        foreach ($coverageCodes as $coverageCode) {
            $record = DB::table(
                'coverage_locations as coverage'
            )
                ->join(
                    'branches as branch',
                    'branch.id',
                    '=',
                    'coverage.branch_id'
                )
                ->where(
                    'coverage.code',
                    $coverageCode
                )
                ->select([
                    'branch.id',
                    'branch.name',
                    'branch.code',
                    'coverage.id as coverage_id',
                    'coverage.name as coverage_name',
                    'coverage.code as coverage_code',
                ])
                ->first();

            if ($record) {
                return [
                    'id' =>
                        (int) $record->id,

                    'name' =>
                        (string) $record->name,

                    'branch_code' =>
                        (string) (
                            $record->code ?? ''
                        ),

                    'coverage_id' =>
                        (int) $record->coverage_id,

                    'coverage_name' =>
                        (string) $record->coverage_name,

                    'coverage_code' =>
                        (string) $record->coverage_code,
                ];
            }
        }

        throw new RuntimeException(
            "{$label} main coverage allocation was not found. Checked codes: " .
            implode(', ', $coverageCodes)
        );
    }

    private function ensureUniqueBranches(
        array $branches
    ): void {
        $branchIds = array_map(
            static fn(array $branch): int =>
                $branch['id'],
            $branches
        );

        if (
            count($branchIds) !==
            count(array_unique($branchIds))
        ) {
            throw new RuntimeException(
                'Two or more main coverage zones are allocated to the same branch.'
            );
        }
    }

    /**
     * Delete only the data between the four current
     * operational branches.
     */
    private function clearCoreNetwork(
        array $branches
    ): void {
        $branchIds = array_map(
            static fn(array $branch): int =>
                $branch['id'],
            $branches
        );

        DB::table('branch_route_rates')
            ->whereIn(
                'pickup_branch_id',
                $branchIds
            )
            ->whereIn(
                'delivery_branch_id',
                $branchIds
            )
            ->delete();

        DB::table('branch_transfer_lanes')
            ->whereIn(
                'from_branch_id',
                $branchIds
            )
            ->whereIn(
                'to_branch_id',
                $branchIds
            )
            ->where(
                'service_type',
                'standard'
            )
            ->delete();
    }

    private function saveBidirectionalRate(
        int $firstBranchId,
        int $secondBranchId,
        float $baseRate
    ): void {
        $this->saveRate(
            pickupBranchId: $firstBranchId,
            deliveryBranchId: $secondBranchId,
            baseRate: $baseRate
        );

        $this->saveRate(
            pickupBranchId: $secondBranchId,
            deliveryBranchId: $firstBranchId,
            baseRate: $baseRate
        );
    }

    private function saveRate(
        int $pickupBranchId,
        int $deliveryBranchId,
        float $baseRate
    ): void {
        DB::table('branch_route_rates')
            ->insert([
                'pickup_branch_id' =>
                    $pickupBranchId,

                'delivery_branch_id' =>
                    $deliveryBranchId,

                'base_rate' =>
                    round($baseRate, 2),

                'is_active' =>
                    true,

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);
    }

    private function saveTransferLane(
        int $fromBranchId,
        int $toBranchId,
        float $distanceKm,
        int $estimatedHours
    ): void {
        DB::table('branch_transfer_lanes')
            ->insert([
                'from_branch_id' =>
                    $fromBranchId,

                'to_branch_id' =>
                    $toBranchId,

                'service_type' =>
                    'standard',

                'transport_mode' =>
                    'road',

                'distance_km' =>
                    round($distanceKm, 2),

                'estimated_hours' =>
                    $estimatedHours,

                'priority' =>
                    10,

                /*
                 * Reverse direction is stored separately.
                 */
                'is_bidirectional' =>
                    false,

                'is_active' =>
                    true,

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);
    }

    private function showSummary(
        array $branches
    ): void {
        $this->command?->newLine();

        $this->command?->info(
            'Marketplace core network seeded successfully.'
        );

        $this->command?->table(
            [
                'Area',
                'Branch ID',
                'Branch',
                'Coverage Code',
            ],
            [
                [
                    'Kathmandu',
                    $branches['ktm']['id'],
                    $branches['ktm']['name'],
                    $branches['ktm']['coverage_code'],
                ],
                [
                    'Pokhara',
                    $branches['pkr']['id'],
                    $branches['pkr']['name'],
                    $branches['pkr']['coverage_code'],
                ],
                [
                    'Biratnagar',
                    $branches['brt']['id'],
                    $branches['brt']['name'],
                    $branches['brt']['coverage_code'],
                ],
                [
                    'Mustang',
                    $branches['mustang']['id'],
                    $branches['mustang']['name'],
                    $branches['mustang']['coverage_code'],
                ],
            ]
        );

        $this->command?->table(
            [
                'Customer Route',
                'Base Rate',
                'Physical Route',
                'Lanes',
            ],
            [
                [
                    'Kathmandu → Pokhara',
                    'Rs 149',
                    'Kathmandu → Pokhara',
                    1,
                ],
                [
                    'Pokhara → Kathmandu',
                    'Rs 149',
                    'Pokhara → Kathmandu',
                    1,
                ],
                [
                    'Kathmandu → Mustang',
                    'Rs 349',
                    'Kathmandu → Pokhara → Mustang',
                    2,
                ],
                [
                    'Pokhara → Biratnagar',
                    'Rs 249',
                    'Pokhara → Kathmandu → Biratnagar',
                    2,
                ],
                [
                    'Biratnagar → Pokhara',
                    'Rs 249',
                    'Biratnagar → Kathmandu → Pokhara',
                    2,
                ],
            ]
        );
    }
}