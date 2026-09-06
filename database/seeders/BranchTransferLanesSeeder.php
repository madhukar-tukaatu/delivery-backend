<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Rate\Models\BranchTransferLane;
use Modules\Rate\Models\BranchTransferRoute;
use Modules\Rate\Models\BranchTransferRouteLane;

class BranchTransferLanesSeeder extends Seeder
{
    public function run(): void
    {
        // Main branches (IDs from your list)
        $ktm = 1;   // Kathmandu main (TUK-KTM-MAIN)
        $pkr = 46;  // Pokhara Main Branch (TUK-PKR-MAIN)
        $ita = 2;   // Itahari Main Branch (TUK-ITA-MAIN)
        $dha = 7;   // Dharan Main Branch (TUK-DHA-MAIN)
        $hld = 16;  // Hetauda Main Branch (TUK-HET-MAIN)
        $btn = 3;   // Biratnagar Main Branch (TUK-BTN-MAIN)
        $jan = 9;   // Janakpur Main Branch (TUK-JAN-MAIN)

        // Define lanes with real branch IDs
        $lanes = [
            // KTM ↔ Pokhara (200km, 8 hours)
            ['from' => $ktm, 'to' => $pkr, 'service' => 'standard', 'distance' => 200, 'hours' => 8],
            ['from' => $ktm, 'to' => $pkr, 'service' => 'express', 'distance' => 200, 'hours' => 5],
            ['from' => $pkr, 'to' => $ktm, 'service' => 'standard', 'distance' => 200, 'hours' => 8],
            ['from' => $pkr, 'to' => $ktm, 'service' => 'express', 'distance' => 200, 'hours' => 5],

            // KTM → Itahari (550km, 16 hours express direct)
            ['from' => $ktm, 'to' => $ita, 'service' => 'express', 'distance' => 550, 'hours' => 16],

            // KTM → Itahari (via Pokhara for standard)
            ['from' => $pkr, 'to' => $ita, 'service' => 'standard', 'distance' => 350, 'hours' => 14],

            // Itahari ↔ KTM
            ['from' => $ita, 'to' => $ktm, 'service' => 'express', 'distance' => 550, 'hours' => 16],
            ['from' => $ita, 'to' => $pkr, 'service' => 'standard', 'distance' => 350, 'hours' => 14],

            // KTM → Dharan (450km)
            ['from' => $ktm, 'to' => $dha, 'service' => 'express', 'distance' => 450, 'hours' => 14],
            ['from' => $dha, 'to' => $ktm, 'service' => 'express', 'distance' => 450, 'hours' => 14],

            // Dharan → Itahari
            ['from' => $dha, 'to' => $ita, 'service' => 'standard', 'distance' => 100, 'hours' => 4],
            ['from' => $ita, 'to' => $dha, 'service' => 'standard', 'distance' => 100, 'hours' => 4],

            // KTM → Janakpur (300km)
            ['from' => $ktm, 'to' => $jan, 'service' => 'standard', 'distance' => 300, 'hours' => 10],
            ['from' => $jan, 'to' => $ktm, 'service' => 'standard', 'distance' => 300, 'hours' => 10],

            // Janakpur → Itahari (250km)
            ['from' => $jan, 'to' => $ita, 'service' => 'standard', 'distance' => 250, 'hours' => 10],
            ['from' => $ita, 'to' => $jan, 'service' => 'standard', 'distance' => 250, 'hours' => 10],

            // KTM → Hetauda (100km)
            ['from' => $ktm, 'to' => $hld, 'service' => 'standard', 'distance' => 100, 'hours' => 3],
            ['from' => $hld, 'to' => $ktm, 'service' => 'standard', 'distance' => 100, 'hours' => 3],

            // Hetauda → Janakpur (150km)
            ['from' => $hld, 'to' => $jan, 'service' => 'standard', 'distance' => 150, 'hours' => 6],
            ['from' => $jan, 'to' => $hld, 'service' => 'standard', 'distance' => 150, 'hours' => 6],
        ];

        // Seed lanes
        $count = 0;
        foreach ($lanes as $lane) {
            $created = BranchTransferLane::updateOrCreate(
                [
                    'from_branch_id'  => $lane['from'],
                    'to_branch_id'    => $lane['to'],
                    'service_type'    => $lane['service'],
                ],
                [
                    'distance_km'     => $lane['distance'],
                    'estimated_hours' => $lane['hours'],
                    'transport_mode'  => 'road',
                    'priority'        => 100,
                    'is_active'       => true,
                ]
            );
            $count++;
        }

        $this->command->info("✓ Seeded $count transfer lanes");

        // Now seed routes and wire lanes
        $this->seedTransferRoutes($ktm, $pkr, $ita, $dha, $hld, $jan);
    }

    private function seedTransferRoutes($ktm, $pkr, $ita, $dha, $hld, $jan): void
    {
        $routes = [
            // KTM ↔ Pokhara
            ['code' => 'KTM-PKR-STD', 'name' => 'Kathmandu to Pokhara (Standard)', 'origin' => $ktm, 'dest' => $pkr, 'service' => 'standard', 'lanes' => [[$ktm, $pkr, 'standard']]],
            ['code' => 'PKR-KTM-STD', 'name' => 'Pokhara to Kathmandu (Standard)', 'origin' => $pkr, 'dest' => $ktm, 'service' => 'standard', 'lanes' => [[$pkr, $ktm, 'standard']]],
            ['code' => 'KTM-PKR-EXP', 'name' => 'Kathmandu to Pokhara (Express)', 'origin' => $ktm, 'dest' => $pkr, 'service' => 'express', 'lanes' => [[$ktm, $pkr, 'express']]],
            ['code' => 'PKR-KTM-EXP', 'name' => 'Pokhara to Kathmandu (Express)', 'origin' => $pkr, 'dest' => $ktm, 'service' => 'express', 'lanes' => [[$pkr, $ktm, 'express']]],

            // KTM ↔ Itahari
            ['code' => 'KTM-ITA-STD', 'name' => 'Kathmandu to Itahari (Standard via Pokhara)', 'origin' => $ktm, 'dest' => $ita, 'service' => 'standard', 'lanes' => [[$ktm, $pkr, 'standard'], [$pkr, $ita, 'standard']]],
            ['code' => 'KTM-ITA-EXP', 'name' => 'Kathmandu to Itahari (Express Direct)', 'origin' => $ktm, 'dest' => $ita, 'service' => 'express', 'lanes' => [[$ktm, $ita, 'express']]],
            ['code' => 'ITA-KTM-STD', 'name' => 'Itahari to Kathmandu (Standard via Pokhara)', 'origin' => $ita, 'dest' => $ktm, 'service' => 'standard', 'lanes' => [[$ita, $pkr, 'standard'], [$pkr, $ktm, 'standard']]],
            ['code' => 'ITA-KTM-EXP', 'name' => 'Itahari to Kathmandu (Express Direct)', 'origin' => $ita, 'dest' => $ktm, 'service' => 'express', 'lanes' => [[$ita, $ktm, 'express']]],

            // KTM ↔ Dharan
            ['code' => 'KTM-DHA-EXP', 'name' => 'Kathmandu to Dharan (Express)', 'origin' => $ktm, 'dest' => $dha, 'service' => 'express', 'lanes' => [[$ktm, $dha, 'express']]],
            ['code' => 'DHA-KTM-EXP', 'name' => 'Dharan to Kathmandu (Express)', 'origin' => $dha, 'dest' => $ktm, 'service' => 'express', 'lanes' => [[$dha, $ktm, 'express']]],

            // Dharan ↔ Itahari
            ['code' => 'DHA-ITA-STD', 'name' => 'Dharan to Itahari (Standard)', 'origin' => $dha, 'dest' => $ita, 'service' => 'standard', 'lanes' => [[$dha, $ita, 'standard']]],
            ['code' => 'ITA-DHA-STD', 'name' => 'Itahari to Dharan (Standard)', 'origin' => $ita, 'dest' => $dha, 'service' => 'standard', 'lanes' => [[$ita, $dha, 'standard']]],
        ];

        $routeCount = 0;
        foreach ($routes as $r) {
            $totalDistance = 0;
            $totalHours = 0;
            $transitIds = [];

            foreach ($r['lanes'] as $i => $spec) {
                [$from, $to, $svc] = $spec;
                $lane = BranchTransferLane::where('from_branch_id', $from)
                    ->where('to_branch_id', $to)
                    ->where('service_type', $svc)
                    ->first();

                if ($lane) {
                    $totalDistance += $lane->distance_km;
                    $totalHours += $lane->estimated_hours;

                    // Transit = intermediate branches (not first, not last)
                    if ($i > 0 && $i < count($r['lanes']) - 1) {
                        $transitIds[] = $to;
                    }
                }
            }

            BranchTransferRoute::updateOrCreate(
                ['route_code' => $r['code']],
                [
                    'name'                   => $r['name'],
                    'origin_branch_id'       => $r['origin'],
                    'destination_branch_id'  => $r['dest'],
                    'service_type'           => $r['service'],
                    'transfer_count'         => count($r['lanes']),
                    'transit_count'          => max(0, count($r['lanes']) - 1),
                    'transit_branch_ids'     => empty($transitIds) ? null : $transitIds,
                    'total_distance_km'      => $totalDistance,
                    'total_estimated_hours'  => $totalHours,
                    'priority'               => 100,
                    'is_default'             => count($r['lanes']) === 1,
                    'is_active'              => true,
                ]
            );

            foreach ($r['lanes'] as $seq => $spec) {
                [$from, $to, $svc] = $spec;
                $lane = BranchTransferLane::where('from_branch_id', $from)
                    ->where('to_branch_id', $to)
                    ->where('service_type', $svc)
                    ->first();

                if ($lane) {
                    $route = BranchTransferRoute::where('route_code', $r['code'])->first();
                    if ($route) {
                        BranchTransferRouteLane::updateOrCreate(
                            [
                                'branch_transfer_route_id' => $route->id,
                                'sequence_number'          => $seq + 1,
                            ],
                            ['branch_transfer_lane_id' => $lane->id]
                        );
                    }
                }
            }
            $routeCount++;
        }

        $this->command->info("✓ Seeded $routeCount transfer routes");
    }
}
