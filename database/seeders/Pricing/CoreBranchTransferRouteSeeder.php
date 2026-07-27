<?php

namespace Database\Seeders\Pricing;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Rate\Models\BranchTransferRoute;
use Modules\Rate\Services\BranchTransferRouteService;
use RuntimeException;

final class CoreBranchTransferRouteSeeder extends Seeder
{
    public function run(): void
    {
        $this->assertBranchesAndLanesExist();

        $routes = [
            [
                'route_code' => 'KTM-PKR-STANDARD',
                'name' => 'Kathmandu to Pokhara',
                'origin_branch_id' => 185,
                'destination_branch_id' => 182,
                'transit_branch_ids' => [],
            ],
            [
                'route_code' => 'PKR-KTM-STANDARD',
                'name' => 'Pokhara to Kathmandu',
                'origin_branch_id' => 182,
                'destination_branch_id' => 185,
                'transit_branch_ids' => [],
            ],
            [
                'route_code' => 'KTM-BRT-STANDARD',
                'name' => 'Kathmandu to Biratnagar',
                'origin_branch_id' => 185,
                'destination_branch_id' => 183,
                'transit_branch_ids' => [],
            ],
            [
                'route_code' => 'BRT-KTM-STANDARD',
                'name' => 'Biratnagar to Kathmandu',
                'origin_branch_id' => 183,
                'destination_branch_id' => 185,
                'transit_branch_ids' => [],
            ],
            [
                'route_code' => 'PKR-MUS-STANDARD',
                'name' => 'Pokhara to Mustang',
                'origin_branch_id' => 182,
                'destination_branch_id' => 188,
                'transit_branch_ids' => [],
            ],
            [
                'route_code' => 'MUS-PKR-STANDARD',
                'name' => 'Mustang to Pokhara',
                'origin_branch_id' => 188,
                'destination_branch_id' => 182,
                'transit_branch_ids' => [],
            ],
            [
                'route_code' => 'KTM-MUS-STANDARD',
                'name' => 'Kathmandu to Mustang via Pokhara',
                'origin_branch_id' => 185,
                'destination_branch_id' => 188,
                'transit_branch_ids' => [182],
            ],
            [
                'route_code' => 'MUS-KTM-STANDARD',
                'name' => 'Mustang to Kathmandu via Pokhara',
                'origin_branch_id' => 188,
                'destination_branch_id' => 185,
                'transit_branch_ids' => [182],
            ],
            [
                'route_code' => 'PKR-BRT-STANDARD',
                'name' => 'Pokhara to Biratnagar via Kathmandu',
                'origin_branch_id' => 182,
                'destination_branch_id' => 183,
                'transit_branch_ids' => [185],
            ],
            [
                'route_code' => 'BRT-PKR-STANDARD',
                'name' => 'Biratnagar to Pokhara via Kathmandu',
                'origin_branch_id' => 183,
                'destination_branch_id' => 182,
                'transit_branch_ids' => [185],
            ],
            [
                'route_code' => 'BRT-MUS-STANDARD',
                'name' => 'Biratnagar to Mustang via Kathmandu and Pokhara',
                'origin_branch_id' => 183,
                'destination_branch_id' => 188,
                'transit_branch_ids' => [185, 182],
            ],
            [
                'route_code' => 'MUS-BRT-STANDARD',
                'name' => 'Mustang to Biratnagar via Pokhara and Kathmandu',
                'origin_branch_id' => 188,
                'destination_branch_id' => 183,
                'transit_branch_ids' => [182, 185],
            ],
        ];

        /** @var BranchTransferRouteService $service */
        $service = app(BranchTransferRouteService::class);

        foreach ($routes as $routeData) {
            $transferCount = count($routeData['transit_branch_ids']) + 1;
            $existing = BranchTransferRoute::query()
                ->where('route_code', $routeData['route_code'])
                ->first();

            $payload = [
                ...$routeData,
                'service_type' => 'standard',
                'base_rate' => $existing
                    ? (float) $existing->base_rate
                    : $this->initialBaseRate(
                        (int) $routeData['origin_branch_id'],
                        (int) $routeData['destination_branch_id'],
                        $transferCount
                    ),
                'currency' => 'NPR',
                'priority' => 100,
                'is_default' => true,
                'is_active' => true,
            ];

            if ($existing) {
                $service->update($existing, $payload);
            } else {
                $service->create($payload);
            }
        }

        $this->command?->info(
            'Core branch transfer routes created/updated successfully.'
        );
    }

    private function initialBaseRate(
        int $originBranchId,
        int $destinationBranchId,
        int $transferCount
    ): float {
        if (
            Schema::hasTable('branch_transfer_rate_tiers') &&
            Schema::hasColumn('branch_transfer_rate_tiers', 'transfer_count') &&
            Schema::hasColumn('branch_transfer_rate_tiers', 'base_rate')
        ) {
            $tierQuery = DB::table('branch_transfer_rate_tiers')
                ->where('transfer_count', $transferCount);

            if (Schema::hasColumn('branch_transfer_rate_tiers', 'service_type')) {
                $tierQuery->where('service_type', 'standard');
            }

            if (Schema::hasColumn('branch_transfer_rate_tiers', 'is_active')) {
                $tierQuery->where('is_active', true);
            }

            $tierRate = $tierQuery->orderByDesc('id')->value('base_rate');

            if ($tierRate !== null) {
                return max(0, (float) $tierRate);
            }
        }

        if (Schema::hasTable('branch_route_rates')) {
            $routeRate = DB::table('branch_route_rates')
                ->where('pickup_branch_id', $originBranchId)
                ->where('delivery_branch_id', $destinationBranchId)
                ->where('is_active', true)
                ->orderByDesc('id')
                ->value('base_rate');

            if ($routeRate !== null) {
                return max(0, (float) $routeRate);
            }
        }

        return match ($transferCount) {
            1 => 149.00,
            2 => 249.00,
            3 => 349.00,
            4 => 449.00,
            default => 149.00,
        };
    }

    private function assertBranchesAndLanesExist(): void
    {
        $requiredBranchIds = [182, 183, 185, 188];
        $existingBranchIds = DB::table('branches')
            ->whereIn('id', $requiredBranchIds)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $missingBranches = array_values(array_diff(
            $requiredBranchIds,
            $existingBranchIds
        ));

        if ($missingBranches !== []) {
            throw new RuntimeException(
                'Missing required branches: ' . implode(', ', $missingBranches)
            );
        }

        $requiredLanes = [
            [185, 182],
            [182, 185],
            [185, 183],
            [183, 185],
            [182, 188],
            [188, 182],
        ];

        foreach ($requiredLanes as [$fromBranchId, $toBranchId]) {
            $exists = DB::table('branch_transfer_lanes')
                ->where('from_branch_id', $fromBranchId)
                ->where('to_branch_id', $toBranchId)
                ->where('service_type', 'standard')
                ->where('is_active', true)
                ->exists();

            if (!$exists) {
                throw new RuntimeException(sprintf(
                    'Missing active standard branch_transfer_lanes row: %d -> %d.',
                    $fromBranchId,
                    $toBranchId
                ));
            }
        }
    }
}
