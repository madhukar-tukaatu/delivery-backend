<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Rate\Models\BranchTransferLane;
use Modules\Rate\Models\BranchTransferRoute;
use Illuminate\Support\Facades\DB;

/**
 * Complete Branch Transfer Lanes & Routes Seeder
 *
 * Creates:
 * 1. branch_transfer_lanes (physical connections between branches)
 * 2. branch_transfer_routes (business routes using those lanes)
 *
 * Generates realistic data for Nepal delivery network
 */
class CompleteBranchTransferLanesSeeder extends Seeder
{
    private int $createdLanes = 0;
    private int $skipped = 0;
    private int $createdRoutes = 0;
    private array $branchMap = [];

    public function run(): void
    {
        $this->command->info("\n🚀 Starting Transfer Lanes & Routes Seeder\n");

        $this->loadBranchMap();
        $this->seedTransferLanes();
        $this->seedTransferRoutes();
        $this->printSummary();
    }

    private function loadBranchMap(): void
    {
        $this->command->line("📍 Loading branches from coverage_locations...");

        // Load all coverage_locations (operational franchises)
        $branches = DB::table('coverage_locations')
            ->select(['id', 'name'])
            ->get();

        foreach ($branches as $branch) {
            $key = strtolower(str_replace(' ', '', $branch->name));
            $this->branchMap[$key] = $branch->id;
        }

        $this->command->info("✓ Loaded {$branches->count()} branches\n");
    }

    private function getBranchId(string $city): ?int
    {
        $key = strtolower(str_replace(' ', '', $city));
        return $this->branchMap[$key] ?? null;
    }

    private function seedTransferLanes(): void
    {
        $this->command->line("🛣️  Seeding transfer lanes...");

        $lanes = [
            // Kathmandu connections
            ['from' => 'kathmandu', 'to' => 'pokhara', 'service' => 'standard', 'distance' => 200, 'hours' => 5, 'mode' => 'road'],
            ['from' => 'kathmandu', 'to' => 'pokhara', 'service' => 'express', 'distance' => 200, 'hours' => 4, 'mode' => 'road'],
            ['from' => 'kathmandu', 'to' => 'pokhara', 'service' => 'same_day', 'distance' => 200, 'hours' => 3, 'mode' => 'road'],
            ['from' => 'kathmandu', 'to' => 'pokhara', 'service' => 'flight', 'distance' => 200, 'hours' => 1, 'mode' => 'flight'],

            ['from' => 'kathmandu', 'to' => 'biratnagar', 'service' => 'standard', 'distance' => 350, 'hours' => 8, 'mode' => 'road'],
            ['from' => 'kathmandu', 'to' => 'biratnagar', 'service' => 'express', 'distance' => 350, 'hours' => 6, 'mode' => 'road'],
            ['from' => 'kathmandu', 'to' => 'biratnagar', 'service' => 'flight', 'distance' => 350, 'hours' => 1, 'mode' => 'flight'],

            ['from' => 'kathmandu', 'to' => 'nepalgunj', 'service' => 'standard', 'distance' => 480, 'hours' => 12, 'mode' => 'road'],
            ['from' => 'kathmandu', 'to' => 'nepalgunj', 'service' => 'express', 'distance' => 480, 'hours' => 10, 'mode' => 'road'],
            ['from' => 'kathmandu', 'to' => 'nepalgunj', 'service' => 'flight', 'distance' => 480, 'hours' => 1.5, 'mode' => 'flight'],

            ['from' => 'kathmandu', 'to' => 'dharan', 'service' => 'standard', 'distance' => 380, 'hours' => 9, 'mode' => 'road'],
            ['from' => 'kathmandu', 'to' => 'dharan', 'service' => 'express', 'distance' => 380, 'hours' => 7, 'mode' => 'road'],

            ['from' => 'kathmandu', 'to' => 'janakpur', 'service' => 'standard', 'distance' => 280, 'hours' => 7, 'mode' => 'road'],
            ['from' => 'kathmandu', 'to' => 'janakpur', 'service' => 'express', 'distance' => 280, 'hours' => 5, 'mode' => 'road'],

            // Pokhara connections
            ['from' => 'pokhara', 'to' => 'kathmandu', 'service' => 'standard', 'distance' => 200, 'hours' => 5, 'mode' => 'road'],
            ['from' => 'pokhara', 'to' => 'kathmandu', 'service' => 'express', 'distance' => 200, 'hours' => 4, 'mode' => 'road'],
            ['from' => 'pokhara', 'to' => 'kathmandu', 'service' => 'flight', 'distance' => 200, 'hours' => 1, 'mode' => 'flight'],

            ['from' => 'pokhara', 'to' => 'biratnagar', 'service' => 'standard', 'distance' => 550, 'hours' => 14, 'mode' => 'road'],
            ['from' => 'pokhara', 'to' => 'biratnagar', 'service' => 'express', 'distance' => 550, 'hours' => 12, 'mode' => 'road'],

            ['from' => 'pokhara', 'to' => 'nepalgunj', 'service' => 'standard', 'distance' => 300, 'hours' => 8, 'mode' => 'road'],
            ['from' => 'pokhara', 'to' => 'nepalgunj', 'service' => 'express', 'distance' => 300, 'hours' => 6, 'mode' => 'road'],

            // Biratnagar connections
            ['from' => 'biratnagar', 'to' => 'kathmandu', 'service' => 'standard', 'distance' => 350, 'hours' => 8, 'mode' => 'road'],
            ['from' => 'biratnagar', 'to' => 'kathmandu', 'service' => 'express', 'distance' => 350, 'hours' => 6, 'mode' => 'road'],
            ['from' => 'biratnagar', 'to' => 'kathmandu', 'service' => 'flight', 'distance' => 350, 'hours' => 1, 'mode' => 'flight'],

            ['from' => 'biratnagar', 'to' => 'dharan', 'service' => 'standard', 'distance' => 80, 'hours' => 2, 'mode' => 'road'],
            ['from' => 'biratnagar', 'to' => 'dharan', 'service' => 'express', 'distance' => 80, 'hours' => 1.5, 'mode' => 'road'],

            // Nepalgunj connections
            ['from' => 'nepalgunj', 'to' => 'kathmandu', 'service' => 'standard', 'distance' => 480, 'hours' => 12, 'mode' => 'road'],
            ['from' => 'nepalgunj', 'to' => 'kathmandu', 'service' => 'express', 'distance' => 480, 'hours' => 10, 'mode' => 'road'],
            ['from' => 'nepalgunj', 'to' => 'kathmandu', 'service' => 'flight', 'distance' => 480, 'hours' => 1.5, 'mode' => 'flight'],

            ['from' => 'nepalgunj', 'to' => 'pokhara', 'service' => 'standard', 'distance' => 300, 'hours' => 8, 'mode' => 'road'],
            ['from' => 'nepalgunj', 'to' => 'pokhara', 'service' => 'express', 'distance' => 300, 'hours' => 6, 'mode' => 'road'],

            // Dharan connections
            ['from' => 'dharan', 'to' => 'kathmandu', 'service' => 'standard', 'distance' => 380, 'hours' => 9, 'mode' => 'road'],
            ['from' => 'dharan', 'to' => 'kathmandu', 'service' => 'express', 'distance' => 380, 'hours' => 7, 'mode' => 'road'],

            ['from' => 'dharan', 'to' => 'biratnagar', 'service' => 'standard', 'distance' => 80, 'hours' => 2, 'mode' => 'road'],
            ['from' => 'dharan', 'to' => 'biratnagar', 'service' => 'express', 'distance' => 80, 'hours' => 1.5, 'mode' => 'road'],

            // Janakpur connections
            ['from' => 'janakpur', 'to' => 'kathmandu', 'service' => 'standard', 'distance' => 280, 'hours' => 7, 'mode' => 'road'],
            ['from' => 'janakpur', 'to' => 'kathmandu', 'service' => 'express', 'distance' => 280, 'hours' => 5, 'mode' => 'road'],

            ['from' => 'janakpur', 'to' => 'biratnagar', 'service' => 'standard', 'distance' => 170, 'hours' => 5, 'mode' => 'road'],
            ['from' => 'janakpur', 'to' => 'biratnagar', 'service' => 'express', 'distance' => 170, 'hours' => 4, 'mode' => 'road'],
        ];

        foreach ($lanes as $lane) {
            $fromId = $this->getBranchId($lane['from']);
            $toId = $this->getBranchId($lane['to']);

            if (!$fromId || !$toId) {
                $this->skipped++;
                continue;
            }

            try {
                BranchTransferLane::updateOrCreate(
                    [
                        'from_branch_id' => $fromId,
                        'to_branch_id' => $toId,
                        'service_type' => $lane['service'],
                    ],
                    [
                        'distance_km' => $lane['distance'],
                        'estimated_hours' => $lane['hours'],
                        'transport_mode' => $lane['mode'],
                        'priority' => 100,
                        'is_active' => true,
                    ]
                );
                $this->createdLanes++;
            } catch (\Exception $e) {
                $this->command->warn("⚠️  Failed to create lane: {$lane['from']} → {$lane['to']} ({$lane['service']}): {$e->getMessage()}");
                $this->skipped++;
            }
        }

        $this->command->info("✓ Created {$this->createdLanes} transfer lanes\n");
    }

    private function seedTransferRoutes(): void
    {
        $this->command->line("📦 Seeding transfer routes...");

        $routes = [
            ['code' => 'KTM-PKR-STD', 'name' => 'Kathmandu to Pokhara (Standard)', 'from' => 'kathmandu', 'to' => 'pokhara', 'service' => 'standard', 'rate' => 500],
            ['code' => 'KTM-PKR-EXP', 'name' => 'Kathmandu to Pokhara (Express)', 'from' => 'kathmandu', 'to' => 'pokhara', 'service' => 'express', 'rate' => 750],
            ['code' => 'KTM-PKR-FLT', 'name' => 'Kathmandu to Pokhara (Flight)', 'from' => 'kathmandu', 'to' => 'pokhara', 'service' => 'flight', 'rate' => 1500],

            ['code' => 'KTM-BIR-STD', 'name' => 'Kathmandu to Biratnagar (Standard)', 'from' => 'kathmandu', 'to' => 'biratnagar', 'service' => 'standard', 'rate' => 700],
            ['code' => 'KTM-BIR-EXP', 'name' => 'Kathmandu to Biratnagar (Express)', 'from' => 'kathmandu', 'to' => 'biratnagar', 'service' => 'express', 'rate' => 1000],
            ['code' => 'KTM-BIR-FLT', 'name' => 'Kathmandu to Biratnagar (Flight)', 'from' => 'kathmandu', 'to' => 'biratnagar', 'service' => 'flight', 'rate' => 2000],

            ['code' => 'KTM-NPG-STD', 'name' => 'Kathmandu to Nepalgunj (Standard)', 'from' => 'kathmandu', 'to' => 'nepalgunj', 'service' => 'standard', 'rate' => 1000],
            ['code' => 'KTM-NPG-EXP', 'name' => 'Kathmandu to Nepalgunj (Express)', 'from' => 'kathmandu', 'to' => 'nepalgunj', 'service' => 'express', 'rate' => 1500],
            ['code' => 'KTM-NPG-FLT', 'name' => 'Kathmandu to Nepalgunj (Flight)', 'from' => 'kathmandu', 'to' => 'nepalgunj', 'service' => 'flight', 'rate' => 2500],

            ['code' => 'KTM-DRN-STD', 'name' => 'Kathmandu to Dharan (Standard)', 'from' => 'kathmandu', 'to' => 'dharan', 'service' => 'standard', 'rate' => 800],
            ['code' => 'KTM-DRN-EXP', 'name' => 'Kathmandu to Dharan (Express)', 'from' => 'kathmandu', 'to' => 'dharan', 'service' => 'express', 'rate' => 1200],

            ['code' => 'KTM-JNK-STD', 'name' => 'Kathmandu to Janakpur (Standard)', 'from' => 'kathmandu', 'to' => 'janakpur', 'service' => 'standard', 'rate' => 600],
            ['code' => 'KTM-JNK-EXP', 'name' => 'Kathmandu to Janakpur (Express)', 'from' => 'kathmandu', 'to' => 'janakpur', 'service' => 'express', 'rate' => 900],

            ['code' => 'PKR-KTM-STD', 'name' => 'Pokhara to Kathmandu (Standard)', 'from' => 'pokhara', 'to' => 'kathmandu', 'service' => 'standard', 'rate' => 500],
            ['code' => 'PKR-KTM-EXP', 'name' => 'Pokhara to Kathmandu (Express)', 'from' => 'pokhara', 'to' => 'kathmandu', 'service' => 'express', 'rate' => 750],
            ['code' => 'PKR-KTM-FLT', 'name' => 'Pokhara to Kathmandu (Flight)', 'from' => 'pokhara', 'to' => 'kathmandu', 'service' => 'flight', 'rate' => 1500],

            ['code' => 'PKR-BIR-STD', 'name' => 'Pokhara to Biratnagar (Standard)', 'from' => 'pokhara', 'to' => 'biratnagar', 'service' => 'standard', 'rate' => 1200],
            ['code' => 'PKR-BIR-EXP', 'name' => 'Pokhara to Biratnagar (Express)', 'from' => 'pokhara', 'to' => 'biratnagar', 'service' => 'express', 'rate' => 1600],

            ['code' => 'PKR-NPG-STD', 'name' => 'Pokhara to Nepalgunj (Standard)', 'from' => 'pokhara', 'to' => 'nepalgunj', 'service' => 'standard', 'rate' => 600],
            ['code' => 'PKR-NPG-EXP', 'name' => 'Pokhara to Nepalgunj (Express)', 'from' => 'pokhara', 'to' => 'nepalgunj', 'service' => 'express', 'rate' => 900],

            ['code' => 'BIR-KTM-STD', 'name' => 'Biratnagar to Kathmandu (Standard)', 'from' => 'biratnagar', 'to' => 'kathmandu', 'service' => 'standard', 'rate' => 700],
            ['code' => 'BIR-KTM-EXP', 'name' => 'Biratnagar to Kathmandu (Express)', 'from' => 'biratnagar', 'to' => 'kathmandu', 'service' => 'express', 'rate' => 1000],
            ['code' => 'BIR-KTM-FLT', 'name' => 'Biratnagar to Kathmandu (Flight)', 'from' => 'biratnagar', 'to' => 'kathmandu', 'service' => 'flight', 'rate' => 2000],

            ['code' => 'BIR-DRN-STD', 'name' => 'Biratnagar to Dharan (Standard)', 'from' => 'biratnagar', 'to' => 'dharan', 'service' => 'standard', 'rate' => 150],
            ['code' => 'BIR-DRN-EXP', 'name' => 'Biratnagar to Dharan (Express)', 'from' => 'biratnagar', 'to' => 'dharan', 'service' => 'express', 'rate' => 250],

            ['code' => 'NPG-KTM-STD', 'name' => 'Nepalgunj to Kathmandu (Standard)', 'from' => 'nepalgunj', 'to' => 'kathmandu', 'service' => 'standard', 'rate' => 1000],
            ['code' => 'NPG-KTM-EXP', 'name' => 'Nepalgunj to Kathmandu (Express)', 'from' => 'nepalgunj', 'to' => 'kathmandu', 'service' => 'express', 'rate' => 1500],
            ['code' => 'NPG-KTM-FLT', 'name' => 'Nepalgunj to Kathmandu (Flight)', 'from' => 'nepalgunj', 'to' => 'kathmandu', 'service' => 'flight', 'rate' => 2500],

            ['code' => 'NPG-PKR-STD', 'name' => 'Nepalgunj to Pokhara (Standard)', 'from' => 'nepalgunj', 'to' => 'pokhara', 'service' => 'standard', 'rate' => 600],
            ['code' => 'NPG-PKR-EXP', 'name' => 'Nepalgunj to Pokhara (Express)', 'from' => 'nepalgunj', 'to' => 'pokhara', 'service' => 'express', 'rate' => 900],

            ['code' => 'DRN-KTM-STD', 'name' => 'Dharan to Kathmandu (Standard)', 'from' => 'dharan', 'to' => 'kathmandu', 'service' => 'standard', 'rate' => 800],
            ['code' => 'DRN-KTM-EXP', 'name' => 'Dharan to Kathmandu (Express)', 'from' => 'dharan', 'to' => 'kathmandu', 'service' => 'express', 'rate' => 1200],

            ['code' => 'DRN-BIR-STD', 'name' => 'Dharan to Biratnagar (Standard)', 'from' => 'dharan', 'to' => 'biratnagar', 'service' => 'standard', 'rate' => 150],
            ['code' => 'DRN-BIR-EXP', 'name' => 'Dharan to Biratnagar (Express)', 'from' => 'dharan', 'to' => 'biratnagar', 'service' => 'express', 'rate' => 250],

            ['code' => 'JNK-KTM-STD', 'name' => 'Janakpur to Kathmandu (Standard)', 'from' => 'janakpur', 'to' => 'kathmandu', 'service' => 'standard', 'rate' => 600],
            ['code' => 'JNK-KTM-EXP', 'name' => 'Janakpur to Kathmandu (Express)', 'from' => 'janakpur', 'to' => 'kathmandu', 'service' => 'express', 'rate' => 900],

            ['code' => 'JNK-BIR-STD', 'name' => 'Janakpur to Biratnagar (Standard)', 'from' => 'janakpur', 'to' => 'biratnagar', 'service' => 'standard', 'rate' => 400],
            ['code' => 'JNK-BIR-EXP', 'name' => 'Janakpur to Biratnagar (Express)', 'from' => 'janakpur', 'to' => 'biratnagar', 'service' => 'express', 'rate' => 600],
        ];

        foreach ($routes as $route) {
            $fromId = $this->getBranchId($route['from']);
            $toId = $this->getBranchId($route['to']);

            // Find the lane for this route
            $lane = BranchTransferLane::where('from_branch_id', $fromId)
                ->where('to_branch_id', $toId)
                ->where('service_type', $route['service'])
                ->first();

            if (!$lane) {
                $this->skipped++;
                continue;
            }

            try {
                BranchTransferRoute::updateOrCreate(
                    ['route_code' => $route['code']],
                    [
                        'name' => $route['name'],
                        'branch_transfer_lane_id' => $lane->id,
                        'service_type' => $route['service'],
                        'base_rate' => $route['rate'],
                        'currency' => 'NPR',
                        'distance_km' => $lane->distance_km,
                        'estimated_hours' => $lane->estimated_hours,
                        'priority' => 100,
                        'is_active' => true,
                    ]
                );
                $this->createdRoutes++;
            } catch (\Exception $e) {
                $this->command->warn("⚠️  Failed to create route {$route['code']}: {$e->getMessage()}");
                $this->skipped++;
            }
        }

        $this->command->info("✓ Created {$this->createdRoutes} transfer routes\n");
    }

    private function printSummary(): void
    {
        $this->command->info("════════════════════════════════════════════════════════");
        $this->command->info("✅ Seeding complete!");
        $this->command->info("════════════════════════════════════════════════════════");
        $this->command->line("Transfer Lanes: {$this->createdLanes}");
        $this->command->line("Transfer Routes: {$this->createdRoutes}");
        $this->command->line("Skipped: {$this->skipped}");
        $this->command->info("════════════════════════════════════════════════════════\n");
    }
}
