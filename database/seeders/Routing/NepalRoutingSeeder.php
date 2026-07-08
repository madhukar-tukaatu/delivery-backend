<?php

namespace Database\Seeders\Routing;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\Routing\Models\DeliveryRouteSegment;

class NepalRoutingSeeder extends Seeder
{
    public function run(): void
    {
        $ho = $this->branch('HO', 'Head Office', 'main_branch', null, 'Kathmandu', 'Central', 27.7172, 85.3240, 30);

        $ktm = $this->branch('KTM', 'Kathmandu Branch', 'branch', $ho->id, 'Kathmandu', 'Kathmandu', 27.7172, 85.3240, 25);
        $pkr = $this->branch('PKR', 'Pokhara Branch', 'branch', $ho->id, 'Pokhara', 'Pokhara', 28.2096, 83.9856, 25);
        $bkt = $this->branch('BKT', 'Bhaktapur Branch', 'branch', $ho->id, 'Bhaktapur', 'Bhaktapur', 27.6710, 85.4298, 18);
        $ltp = $this->branch('LTP', 'Lalitpur Branch', 'branch', $ho->id, 'Lalitpur', 'Patan', 27.6588, 85.3247, 18);
        $bwt = $this->branch('BWT', 'Butwal Branch', 'branch', $ho->id, 'Butwal', 'Butwal', 27.7006, 83.4484, 25);
        $brt = $this->branch('BRT', 'Biratnagar Branch', 'branch', $ho->id, 'Biratnagar', 'Biratnagar', 26.4525, 87.2718, 25);
        $npq = $this->branch('NPQ', 'Nepalgunj Branch', 'branch', $ho->id, 'Nepalgunj', 'Nepalgunj', 28.0500, 81.6167, 25);
        $dhn = $this->branch('DHN', 'Dhangadhi Branch', 'branch', $ho->id, 'Dhangadhi', 'Dhangadhi', 28.7056, 80.5790, 25);

        $subBranches = [
            [$ktm, 'BNW', 'Baneshwor Sub-Branch', 'Kathmandu', 'New Baneshwor', 27.6903, 85.3420],
            [$ktm, 'KOT', 'Koteshwor Sub-Branch', 'Kathmandu', 'Koteshwor', 27.6787, 85.3493],
            [$ktm, 'KAL', 'Kalanki Sub-Branch', 'Kathmandu', 'Kalanki', 27.6934, 85.2816],
            [$ktm, 'GON', 'Gongabu Sub-Branch', 'Kathmandu', 'Gongabu', 27.7352, 85.3137],
            [$pkr, 'LKS', 'Lakeside Sub-Branch', 'Pokhara', 'Lakeside', 28.2099, 83.9593],
            [$pkr, 'MHP', 'Mahendrapool Sub-Branch', 'Pokhara', 'Mahendrapool', 28.2290, 83.9874],
            [$bkt, 'BKD', 'Bhaktapur Durbar Sub-Branch', 'Bhaktapur', 'Durbar Square', 27.6720, 85.4280],
            [$bkt, 'SUR', 'Suryabinayak Sub-Branch', 'Bhaktapur', 'Suryabinayak', 27.6617, 85.4263],
            [$ltp, 'SAT', 'Satdobato Sub-Branch', 'Lalitpur', 'Satdobato', 27.6502, 85.3250],
            [$ltp, 'GWA', 'Gwarko Sub-Branch', 'Lalitpur', 'Gwarko', 27.6667, 85.3382],
            [$bwt, 'BTB', 'Butwal Buspark Sub-Branch', 'Butwal', 'Buspark', 27.7000, 83.4480],
            [$brt, 'BZM', 'Biratnagar Main Sub-Branch', 'Biratnagar', 'Main Road', 26.4525, 87.2718],
            [$npq, 'NPJ', 'Nepalgunj Main Sub-Branch', 'Nepalgunj', 'Tribhuvan Chowk', 28.0500, 81.6167],
            [$dhn, 'DHG', 'Dhangadhi Main Sub-Branch', 'Dhangadhi', 'Main Bazaar', 28.7056, 80.5790],
        ];

        foreach ($subBranches as [$parent, $code, $name, $city, $area, $lat, $lng]) {
            $sub = $this->branch($code, $name, 'sub_branch', $parent->id, $city, $area, $lat, $lng, 6);
            $this->serviceArea($parent, $sub, $city, $area, $lat, $lng, 6);
        }

        $this->segment($pkr, $ktm, 205, 260, 35, 8);
        $this->segment($ktm, $pkr, 205, 260, 35, 8);
        $this->segment($ktm, $bkt, 16, 80, 15, 2);
        $this->segment($bkt, $ktm, 16, 80, 15, 2);
        $this->segment($ktm, $ltp, 8, 60, 10, 1);
        $this->segment($ltp, $ktm, 8, 60, 10, 1);
        $this->segment($ktm, $bwt, 265, 320, 40, 10);
        $this->segment($bwt, $ktm, 265, 320, 40, 10);
        $this->segment($ktm, $brt, 375, 420, 45, 12);
        $this->segment($brt, $ktm, 375, 420, 45, 12);
        $this->segment($ktm, $npq, 520, 520, 60, 16);
        $this->segment($npq, $ktm, 520, 520, 60, 16);
        $this->segment($npq, $dhn, 180, 240, 35, 7);
        $this->segment($dhn, $npq, 180, 240, 35, 7);
        $this->segment($bwt, $npq, 330, 360, 45, 11);
        $this->segment($npq, $bwt, 330, 360, 45, 11);
    }

    private function branch(string $code, string $name, string $type, ?int $parentId, string $city, string $area, float $lat, float $lng, float $radius): Branch
    {
        return Branch::updateOrCreate(['code' => $code], [
            'parent_id' => $parentId,
            'name' => $name,
            'type' => $type,
            'city' => $city,
            'area' => $area,
            'address' => $area . ', ' . $city . ', Nepal',
            'latitude' => $lat,
            'longitude' => $lng,
            'coverage_radius_km' => $radius,
            'status' => 'active',
        ]);
    }

    private function serviceArea(Branch $branch, Branch $subBranch, string $city, string $area, float $lat, float $lng, float $radius): void
    {
        DB::table('branch_service_areas')->updateOrInsert([
            'branch_id' => $branch->id,
            'sub_branch_id' => $subBranch->id,
            'city' => $city,
            'area' => $area,
        ], [
            'postal_code' => null,
            'latitude' => $lat,
            'longitude' => $lng,
            'radius_km' => $radius,
            'service_type' => 'both',
            'priority' => 10,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function segment(Branch $from, Branch $to, float $distance, float $baseFee, float $perKgFee, int $hours): void
    {
        DeliveryRouteSegment::updateOrCreate([
            'from_branch_id' => $from->id,
            'to_branch_id' => $to->id,
        ], [
            'route_name' => $from->name . ' to ' . $to->name,
            'distance_km' => $distance,
            'base_fee' => $baseFee,
            'per_kg_fee' => $perKgFee,
            'estimated_hours' => $hours,
            'status' => 'active',
        ]);
    }
}
