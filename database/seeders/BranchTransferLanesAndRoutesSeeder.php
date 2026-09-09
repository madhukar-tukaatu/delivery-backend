<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BranchTransferLanesAndRoutesSeeder extends Seeder
{
    /**
     * Seed Transfer Lanes and Routes based on actual branch_route_rates data
     * 
     * Schema (from migration):
     * - branch_transfer_lanes: from_branch_id, to_branch_id, service_type (enum), transport_mode
     * - branch_transfer_routes: branch_transfer_lane_id (FK to lanes), service_type, route_code, name
     */
    public function run(): void
    {
        $now = now();

        // Main transfer lanes: [from_id, to_id, distance_km, hours, mode, services_comma_separated]
        $lanes = [
            [1, 46, 200, 5.5, 'road', 'standard,express'],
            [46, 1, 200, 5.5, 'road', 'standard,express'],
            [1, 2, 350, 8, 'road', 'standard,express'],
            [2, 1, 350, 8, 'road', 'standard,express'],
            [1, 35, 90, 3.5, 'road', 'standard,express,same_day'],
            [35, 1, 90, 3.5, 'road', 'standard,express,same_day'],
            [46, 35, 150, 4.5, 'road', 'standard,express'],
            [35, 46, 150, 4.5, 'road', 'standard,express'],
            [46, 2, 500, 12, 'road', 'standard,express'],
            [2, 46, 500, 12, 'road', 'standard,express'],
            [46, 49, 75, 2.5, 'road', 'standard,express'],
            [49, 46, 75, 2.5, 'road', 'standard,express'],
            [49, 11, 80, 3, 'road', 'standard,express'],
            [11, 49, 80, 3, 'road', 'standard,express'],
            [35, 2, 280, 7, 'road', 'standard,express'],
            [2, 35, 280, 7, 'road', 'standard,express'],
            [2, 3, 40, 1.5, 'road', 'standard,express,same_day'],
            [3, 2, 40, 1.5, 'road', 'standard,express,same_day'],
            [2, 7, 35, 1.2, 'road', 'standard,express,same_day'],
            [7, 2, 35, 1.2, 'road', 'standard,express,same_day'],
            [3, 7, 75, 2.5, 'road', 'standard,express'],
            [7, 3, 75, 2.5, 'road', 'standard,express'],
            [1, 9, 275, 8, 'road', 'standard,express'],
            [9, 1, 275, 8, 'road', 'standard,express'],
            [1, 25, 220, 6, 'road', 'standard,express'],
            [25, 1, 220, 6, 'road', 'standard,express'],
            [46, 25, 220, 6, 'road', 'standard,express'],
            [25, 46, 220, 6, 'road', 'standard,express'],
            [25, 11, 100, 3, 'road', 'standard,express'],
            [11, 25, 100, 3, 'road', 'standard,express'],
            [25, 56, 115, 3.5, 'road', 'standard,express'],
            [56, 25, 115, 3.5, 'road', 'standard,express'],
            [25, 57, 145, 4, 'road', 'standard,express'],
            [57, 25, 145, 4, 'road', 'standard,express'],
            [56, 57, 60, 2, 'road', 'standard,express'],
            [57, 56, 60, 2, 'road', 'standard,express'],
            [57, 58, 75, 2.5, 'road', 'standard,express'],
            [58, 57, 75, 2.5, 'road', 'standard,express'],
            [1, 16, 66, 3, 'road', 'standard,express,same_day'],
            [16, 1, 66, 3, 'road', 'standard,express,same_day'],
            [1, 10, 27, 1.5, 'road', 'standard,express,same_day'],
            [10, 1, 27, 1.5, 'road', 'standard,express,same_day'],
            [10, 46, 180, 5, 'road', 'standard,express'],
            [46, 10, 180, 5, 'road', 'standard,express'],
            [35, 16, 80, 2.5, 'road', 'standard,express,same_day'],
            [16, 35, 80, 2.5, 'road', 'standard,express,same_day'],
            [16, 27, 100, 3, 'road', 'standard,express'],
            [27, 16, 100, 3, 'road', 'standard,express'],
            [9, 27, 105, 4.5, 'road', 'standard,express'],
            [27, 9, 105, 4.5, 'road', 'standard,express'],
            [1, 20, 250, 7, 'road', 'standard,express'],
            [20, 1, 250, 7, 'road', 'standard,express'],
            [7, 20, 45, 1.8, 'road', 'standard,express'],
            [20, 7, 45, 1.8, 'road', 'standard,express'],
            [35, 25, 190, 5, 'road', 'standard,express'],
            [25, 35, 190, 5, 'road', 'standard,express'],
            [35, 9, 220, 5, 'road', 'standard,express'],
            [9, 35, 220, 5, 'road', 'standard,express'],
            [1, 49, 220, 6, 'road', 'standard,express'],
            [49, 1, 220, 6, 'road', 'standard,express'],
            [35, 49, 160, 4.5, 'road', 'standard,express'],
            [49, 35, 160, 4.5, 'road', 'standard,express'],
            [11, 46, 200, 5, 'road', 'standard,express'],
            [46, 11, 200, 5, 'road', 'standard,express'],
            [11, 1, 220, 6, 'road', 'standard,express'],
            [1, 11, 220, 6, 'road', 'standard,express'],
            [11, 35, 150, 4, 'road', 'standard,express'],
            [35, 11, 150, 4, 'road', 'standard,express'],
            [46, 12, 45, 1.5, 'road', 'standard,express,same_day'],
            [12, 46, 45, 1.5, 'road', 'standard,express,same_day'],
            [46, 50, 35, 1, 'road', 'standard,express,same_day'],
            [50, 46, 35, 1, 'road', 'standard,express,same_day'],
            [50, 49, 55, 1.5, 'road', 'standard,express'],
            [49, 50, 55, 1.5, 'road', 'standard,express'],
            [35, 12, 130, 3.5, 'road', 'standard,express'],
            [12, 35, 130, 3.5, 'road', 'standard,express'],
        ];

        // ========== CREATE TRANSFER LANES ==========
        // One lane per (from, to, service_type) combination
        $lanesData = [];
        
        foreach ($lanes as $lane) {
            [$from, $to, $dist, $hours, $mode, $services] = $lane;
            $serviceArray = array_map('trim', explode(',', $services));

            foreach ($serviceArray as $service) {
                $lanesData[] = [
                    'from_branch_id' => $from,
                    'to_branch_id' => $to,
                    'service_type' => $service,
                    'transport_mode' => $mode,
                    'distance_km' => $dist,
                    'estimated_hours' => $hours,
                    'priority' => 100,
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Insert lanes in chunks
        $chunks = array_chunk($lanesData, 50);
        foreach ($chunks as $chunk) {
            DB::table('branch_transfer_lanes')->insert($chunk);
        }

        // ========== CREATE TRANSFER ROUTES ==========
        // Routes link to lanes and have route_code + name
        // Some routes can have transits (intermediate branches)
        $laneRecords = DB::table('branch_transfer_lanes')
            ->select('id', 'from_branch_id', 'to_branch_id', 'service_type')
            ->get();

        $routesData = [];
        $priority = 1000; // Start high to avoid going negative

        // 1. CREATE DIRECT ROUTES (no transits)
        foreach ($laneRecords as $laneRecord) {
            $fromBranch = DB::table('coverage_locations')
                ->where('id', $laneRecord->from_branch_id)
                ->select('code', 'name')
                ->first();
                
            $toBranch = DB::table('coverage_locations')
                ->where('id', $laneRecord->to_branch_id)
                ->select('code', 'name')
                ->first();

            if ($fromBranch && $toBranch) {
                // Extract 3-letter codes from "TUK-XXX-MAIN"
                $fromCode = explode('-', $fromBranch->code)[1] ?? 'UNK';
                $toCode = explode('-', $toBranch->code)[1] ?? 'UNK';
                
                $routeCode = "{$fromCode}-{$toCode}-" . strtoupper($laneRecord->service_type);
                $routeName = "{$fromBranch->name} to {$toBranch->name}";

                $routesData[] = [
                    'route_code' => $routeCode,
                    'name' => $routeName,
                    'branch_transfer_lane_id' => $laneRecord->id,
                    'service_type' => $laneRecord->service_type,
                    'transit_branch_ids' => json_encode([]), // Direct route - no transits
                    'priority' => $priority,
                    'is_active' => 1,
                    'is_default' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                
                $priority = max(1, $priority - 1); // Decrement but never go below 1
            }
        }

        // 2. CREATE ROUTES WITH TRANSITS (multi-hop)
        // Example: KTM -> PKR -> Butwal (via Pokhara)
        $transitRoutes = [
            // Format: [from_id, to_id, [transit_ids], service_type]
            [1, 25, [46], 'standard'],    // KTM -> Butwal via Pokhara
            [1, 25, [46], 'express'],
            [25, 1, [46], 'standard'],    // Butwal -> KTM via Pokhara
            [25, 1, [46], 'express'],
            
            [1, 56, [25], 'standard'],    // KTM -> Ghorahi via Butwal
            [1, 56, [25], 'express'],
            [56, 1, [25], 'standard'],
            [56, 1, [25], 'express'],
            
            [46, 56, [25], 'standard'],   // Pokhara -> Ghorahi via Butwal
            [46, 56, [25], 'express'],
            [56, 46, [25], 'standard'],
            [56, 46, [25], 'express'],
            
            [1, 57, [25, 56], 'standard'], // KTM -> Nepalgunj via Butwal -> Ghorahi
            [1, 57, [25, 56], 'express'],
            [57, 1, [56, 25], 'standard'],
            [57, 1, [56, 25], 'express'],
            
            [1, 27, [9], 'standard'],      // KTM -> Birgunj via Janakpur
            [1, 27, [9], 'express'],
            [27, 1, [9], 'standard'],
            [27, 1, [9], 'express'],
            
            [1, 3, [2], 'standard'],       // KTM -> Biratnagar via Itahari
            [1, 3, [2], 'express'],
            [3, 1, [2], 'standard'],
            [3, 1, [2], 'express'],
        ];

        foreach ($transitRoutes as $transitRoute) {
            [$from, $to, $transitIds, $service] = $transitRoute;
            
            // Get all branch information
            $fromBranch = DB::table('coverage_locations')
                ->where('id', $from)
                ->select('code', 'name')
                ->first();
                
            $toBranch = DB::table('coverage_locations')
                ->where('id', $to)
                ->select('code', 'name')
                ->first();

            if ($fromBranch && $toBranch) {
                $fromCode = explode('-', $fromBranch->code)[1] ?? 'UNK';
                $toCode = explode('-', $toBranch->code)[1] ?? 'UNK';
                
                // Build route code with transits: KTM-PKR-BTW-STANDARD
                $transitCodes = [];
                foreach ($transitIds as $transitId) {
                    $transitBranch = DB::table('coverage_locations')
                        ->where('id', $transitId)
                        ->select('code')
                        ->first();
                    if ($transitBranch) {
                        $transitCodes[] = explode('-', $transitBranch->code)[1] ?? 'UNK';
                    }
                }
                
                $transitPart = !empty($transitCodes) ? '-' . implode('-', $transitCodes) : '';
                $routeCode = "{$fromCode}{$transitPart}-{$toCode}-" . strtoupper($service);
                
                // Build route name with transits: "KTM to Butwal via Pokhara"
                $transitNames = [];
                foreach ($transitIds as $transitId) {
                    $transitBranch = DB::table('coverage_locations')
                        ->where('id', $transitId)
                        ->select('name')
                        ->first();
                    if ($transitBranch) {
                        $transitNames[] = $transitBranch->name;
                    }
                }
                
                $transitPart = !empty($transitNames) ? ' via ' . implode(', ', $transitNames) : '';
                $routeName = "{$fromBranch->name} to {$toBranch->name}{$transitPart}";
                
                // Find or create a dummy lane for transit routes
                // (Since routes MUST link to a lane, we use the direct lane if it exists)
                $lane = DB::table('branch_transfer_lanes')
                    ->where('from_branch_id', $from)
                    ->where('to_branch_id', $to)
                    ->where('service_type', $service)
                    ->first();
                
                if ($lane) {
                    $routesData[] = [
                        'route_code' => $routeCode,
                        'name' => $routeName,
                        'branch_transfer_lane_id' => $lane->id,
                        'service_type' => $service,
                        'transit_branch_ids' => json_encode($transitIds),
                        'priority' => max(1, $priority - 500), // Lower priority for transit routes, but stay >= 1
                        'is_active' => 1,
                        'is_default' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        // Insert routes in chunks
        $chunks = array_chunk($routesData, 50);
        foreach ($chunks as $chunk) {
            DB::table('branch_transfer_routes')->insert($chunk);
        }

        $this->command->info('✅ Transfer lanes created: ' . count($lanesData));
        $this->command->info('✅ Transfer routes created: ' . count($routesData));
        $this->command->info('✅ - Direct routes: ' . ($priority > 50 ? count($laneRecords) : 'calculated'));
        $this->command->info('✅ - Transit routes: ' . count($transitRoutes));
        $this->command->info('✅ Service types seeded: standard, express, same_day');
    }
}
