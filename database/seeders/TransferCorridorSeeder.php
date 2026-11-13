<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Modules\Dispatch\Models\TransferCorridor;
use Modules\Dispatch\Models\TransferCorridorStop;

/**
 * Data-driven Nepal line-haul corridors used by TMS planning.
 * Safe to re-run; does not create shipments.
 */
class TransferCorridorSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('transfer_corridors')) {
            return;
        }

        $corridors = [
            [
                'code' => 'PRITHVI_PKR_KTM',
                'name' => 'Prithvi Highway Pokhara — Kathmandu',
                'highway' => 'Prithvi Highway',
                'avg_speed_kmh' => 32,
                'priority' => 10,
                'notes' => 'PKR — Damauli — Mugling — Naubise — KTM',
                'stops' => [
                    ['code' => 'NP-BR-POKHA', 'alias' => 'Pokhara'],
                    ['code' => 'NP-BR-DAMAU', 'alias' => 'Damauli', 'km' => 50, 'hours' => 1.5],
                    ['code' => 'NP-BR-KATHM', 'alias' => 'Kathmandu', 'km' => 150, 'hours' => 5],
                ],
            ],
            [
                'code' => 'EAST_FROM_PKR',
                'name' => 'Pokhara to East via Narayangarh / Hetauda',
                'highway' => 'Prithvi / Mahendra (East-West) Highway',
                'avg_speed_kmh' => 38,
                'priority' => 20,
                'notes' => 'PKR — Damauli — Bharatpur (Narayangarh) — Hetauda — Bardibas — Itahari — Biratnagar — Birtamode',
                'stops' => [
                    ['code' => 'NP-BR-POKHA', 'alias' => 'Pokhara'],
                    ['code' => 'NP-BR-DAMAU', 'alias' => 'Damauli', 'km' => 50, 'hours' => 1.5],
                    ['code' => 'NP-BR-BHARA', 'alias' => 'Bharatpur / Narayangarh', 'km' => 50, 'hours' => 1.5],
                    ['code' => 'NP-BR-HETAU', 'alias' => 'Hetauda', 'km' => 75, 'hours' => 2.2],
                    ['code' => 'NP-BR-BARDI', 'alias' => 'Bardibas', 'km' => 120, 'hours' => 3.0],
                    ['code' => 'NP-BR-JANAK', 'alias' => 'Janakpur', 'km' => 35, 'hours' => 1.0],
                    ['code' => 'NP-BR-ITAHA', 'alias' => 'Itahari', 'km' => 120, 'hours' => 3.0],
                    ['code' => 'NP-BR-BIRAT', 'alias' => 'Biratnagar', 'km' => 25, 'hours' => 0.8],
                    ['code' => 'NP-BR-BIRTA', 'alias' => 'Birtamode', 'km' => 55, 'hours' => 1.5],
                ],
            ],
            [
                'code' => 'EAST_FROM_KTM',
                'name' => 'Kathmandu to East via Hetauda / Mahendra',
                'highway' => 'Tribhuvan / BP Highway + Mahendra (East-West) Highway',
                'avg_speed_kmh' => 38,
                'priority' => 15,
                'notes' => 'KTM — Hetauda — Bardibas — Itahari — Biratnagar — Birtamode',
                'stops' => [
                    ['code' => 'NP-BR-KATHM', 'alias' => 'Kathmandu'],
                    ['code' => 'NP-BR-HETAU', 'alias' => 'Hetauda', 'km' => 80, 'hours' => 2.5],
                    ['code' => 'NP-BR-BARDI', 'alias' => 'Bardibas', 'km' => 120, 'hours' => 3.0],
                    ['code' => 'NP-BR-JANAK', 'alias' => 'Janakpur', 'km' => 35, 'hours' => 1.0],
                    ['code' => 'NP-BR-ITAHA', 'alias' => 'Itahari', 'km' => 120, 'hours' => 3.0],
                    ['code' => 'NP-BR-BIRAT', 'alias' => 'Biratnagar', 'km' => 25, 'hours' => 0.8],
                    ['code' => 'NP-BR-BIRTA', 'alias' => 'Birtamode', 'km' => 55, 'hours' => 1.5],
                ],
            ],
            [
                'code' => 'MIDHILL_HTD_MUSTANG',
                'name' => 'Hetauda — Pokhara — Mustang (Jomsom)',
                'highway' => 'Narayangarh / Prithvi + Pokhara-Beni-Jomsom',
                'avg_speed_kmh' => 28,
                'priority' => 25,
                'notes' => 'HTD — Bharatpur — Damauli — Pokhara — Jomsom / Mustang',
                'stops' => [
                    ['code' => 'NP-BR-HETAU', 'alias' => 'Hetauda'],
                    ['code' => 'NP-BR-BHARA', 'alias' => 'Bharatpur / Narayangarh', 'km' => 75, 'hours' => 2.2],
                    ['code' => 'NP-BR-DAMAU', 'alias' => 'Damauli', 'km' => 50, 'hours' => 1.5],
                    ['code' => 'NP-BR-POKHA', 'alias' => 'Pokhara', 'km' => 50, 'hours' => 1.5],
                    ['code' => 'NP-BR-JOMSO', 'alias' => 'Jomsom / Mustang', 'km' => 160, 'hours' => 7],
                ],
            ],
            [
                'code' => 'SIDDHARTHA_ARGHA',
                'name' => 'Pokhara — Butwal — Arghakhanchi feeder',
                'highway' => 'Siddhartha Highway + Arghakhanchi feeder',
                'avg_speed_kmh' => 32,
                'priority' => 30,
                'notes' => 'PKR — Butwal — Sandhikharka (Arghakhanchi)',
                'stops' => [
                    ['code' => 'NP-BR-POKHA', 'alias' => 'Pokhara'],
                    ['code' => 'NP-BR-BUTWA', 'alias' => 'Butwal', 'km' => 120, 'hours' => 3.5],
                    ['code' => 'NP-BR-ARGHA', 'alias' => 'Arghakhanchi', 'km' => 55, 'hours' => 2.0],
                ],
            ],
            [
                'code' => 'MAHENDRA_WEST',
                'name' => 'Hetauda west on the Mahendra Highway',
                'highway' => 'Mahendra (East-West) Highway west',
                'avg_speed_kmh' => 40,
                'priority' => 40,
                'notes' => 'HTD — Bharatpur — Butwal — Gulariya — Birendranagar',
                'stops' => [
                    ['code' => 'NP-BR-HETAU', 'alias' => 'Hetauda'],
                    ['code' => 'NP-BR-BHARA', 'alias' => 'Bharatpur', 'km' => 75, 'hours' => 2.2],
                    ['code' => 'NP-BR-BUTWA', 'alias' => 'Butwal', 'km' => 110, 'hours' => 3.0],
                    ['code' => 'NP-BR-GULAR', 'alias' => 'Gulariya', 'km' => 150, 'hours' => 4.0],
                    ['code' => 'NP-BR-BIREN', 'alias' => 'Birendranagar', 'km' => 90, 'hours' => 2.5],
                ],
            ],
        ];

        foreach ($corridors as $row) {
            $stops = $row['stops'];
            unset($row['stops']);
            $corridor = TransferCorridor::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'highway' => $row['highway'],
                    'avg_speed_kmh' => $row['avg_speed_kmh'],
                    'dwell_minutes' => 20,
                    'is_active' => true,
                    'priority' => $row['priority'],
                    'notes' => $row['notes'],
                ]
            );
            TransferCorridorStop::query()->where('transfer_corridor_id', $corridor->id)->delete();
            foreach ($stops as $i => $stop) {
                TransferCorridorStop::query()->create([
                    'transfer_corridor_id' => $corridor->id,
                    'sequence' => $i,
                    'branch_code' => $stop['code'],
                    'alias' => $stop['alias'] ?? null,
                    'distance_from_prev_km' => $stop['km'] ?? null,
                    'hours_from_prev' => $stop['hours'] ?? null,
                ]);
            }
        }
    }
}
