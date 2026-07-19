<?php

namespace Database\Seeders\Production;

use Illuminate\Database\Seeder;
use Modules\Branch\Models\CoverageLocation;

class CoverageLocationSeeder extends Seeder
{
    public function run(): void
    {
        $mainZones = [
            [
                'name' => 'Kathmandu Main Coverage Zone',
                'code' => 'KTM-MAIN-ZONE',
                'country' => 'Nepal',
                'province' => 'Bagmati',
                'district' => 'Kathmandu',
                'city' => 'Kathmandu',
                'area' => 'Kathmandu',
                'address' => 'Kathmandu, Nepal',
                'latitude' => 27.7172,
                'longitude' => 85.3240,
                'coverage_radius_km' => 5,
            ],
            [
                'name' => 'Pokhara Main Coverage Zone',
                'code' => 'PKR-MAIN-ZONE',
                'country' => 'Nepal',
                'province' => 'Gandaki',
                'district' => 'Kaski',
                'city' => 'Pokhara',
                'area' => 'Pokhara',
                'address' => 'Pokhara, Nepal',
                'latitude' => 28.2096,
                'longitude' => 83.9856,
                'coverage_radius_km' => 5,
            ],
            [
                'name' => 'Biratnagar Main Coverage Zone',
                'code' => 'BRT-MAIN-ZONE',
                'country' => 'Nepal',
                'province' => 'Koshi',
                'district' => 'Morang',
                'city' => 'Biratnagar',
                'area' => 'Biratnagar',
                'address' => 'Biratnagar, Nepal',
                'latitude' => 26.4525,
                'longitude' => 87.2718,
                'coverage_radius_km' => 5,
            ],
        ];

        foreach ($mainZones as $zone) {
            CoverageLocation::updateOrCreate(
                ['code' => $zone['code']],
                array_merge($zone, [
                    'type' => CoverageLocation::TYPE_MAIN_BRANCH_ZONE,
                    'parent_id' => null,
                    'is_hq_managed' => true,
                    'status' => CoverageLocation::STATUS_ACTIVE,
                ])
            );
        }

        $kathmandu = CoverageLocation::where('code', 'KTM-MAIN-ZONE')->first();
        $pokhara = CoverageLocation::where('code', 'PKR-MAIN-ZONE')->first();
        $biratnagar = CoverageLocation::where('code', 'BRT-MAIN-ZONE')->first();

        $subZones = [];

        if ($kathmandu) {
            $subZones[] = [
                'parent_id' => $kathmandu->id,
                'name' => 'Thamel Sub-Branch Coverage Zone',
                'code' => 'KTM-THAMEL-SUB-ZONE',
                'country' => 'Nepal',
                'province' => 'Bagmati',
                'district' => 'Kathmandu',
                'city' => 'Kathmandu',
                'area' => 'Thamel',
                'address' => 'Thamel, Kathmandu',
                'latitude' => 27.7154,
                'longitude' => 85.3123,
                'coverage_radius_km' => 3,
            ];

            $subZones[] = [
                'parent_id' => $kathmandu->id,
                'name' => 'Koteshwor Sub-Branch Coverage Zone',
                'code' => 'KTM-KOTESHWOR-SUB-ZONE',
                'country' => 'Nepal',
                'province' => 'Bagmati',
                'district' => 'Kathmandu',
                'city' => 'Kathmandu',
                'area' => 'Koteshwor',
                'address' => 'Koteshwor, Kathmandu',
                'latitude' => 27.6788,
                'longitude' => 85.3492,
                'coverage_radius_km' => 3,
            ];
        }

        if ($pokhara) {
            $subZones[] = [
                'parent_id' => $pokhara->id,
                'name' => 'Lakeside Sub-Branch Coverage Zone',
                'code' => 'PKR-LAKESIDE-SUB-ZONE',
                'country' => 'Nepal',
                'province' => 'Gandaki',
                'district' => 'Kaski',
                'city' => 'Pokhara',
                'area' => 'Lakeside',
                'address' => 'Lakeside, Pokhara',
                'latitude' => 28.2090,
                'longitude' => 83.9595,
                'coverage_radius_km' => 3,
            ];
        }

        if ($biratnagar) {
            $subZones[] = [
                'parent_id' => $biratnagar->id,
                'name' => 'Traffic Chowk Sub-Branch Coverage Zone',
                'code' => 'BRT-TRAFFIC-SUB-ZONE',
                'country' => 'Nepal',
                'province' => 'Koshi',
                'district' => 'Morang',
                'city' => 'Biratnagar',
                'area' => 'Traffic Chowk',
                'address' => 'Traffic Chowk, Biratnagar',
                'latitude' => 26.4520,
                'longitude' => 87.2700,
                'coverage_radius_km' => 3,
            ];
        }

        foreach ($subZones as $zone) {
            CoverageLocation::updateOrCreate(
                ['code' => $zone['code']],
                array_merge($zone, [
                    'type' => CoverageLocation::TYPE_SUB_BRANCH_ZONE,
                    'is_hq_managed' => true,
                    'status' => CoverageLocation::STATUS_ACTIVE,
                ])
            );
        }
    }
}