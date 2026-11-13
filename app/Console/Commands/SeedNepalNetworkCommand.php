<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Branch\Enums\BranchType;
use Modules\Branch\Models\Branch;
use Modules\Branch\Models\CoverageLocation;
use Modules\Dispatch\Models\DispatchManifest;
use Modules\Dispatch\Models\DispatchManifestItem;
use Modules\Rate\Models\BranchTransferLane;
use Modules\Rate\Models\BranchTransferRoute;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Models\TransferRequest;

final class SeedNepalNetworkCommand extends Command
{
    protected $signature = 'transfer:seed-nepal-network {--force : Clear and re-seed transfer demo data}';

    protected $description = 'Seeds 50+ real Nepal branches with coordinates, corridors, transfer routes, requests, and active vehicles for Transport Manager flow.';

    public function handle(): int
    {
        $this->info('================================================================');
        $this->info('  Seeding 50+ Nepal Branches & Transfer Transport Network  ');
        $this->info('================================================================');

        $branchesData = $this->branchesList();
        $this->info(sprintf('1/5. Creating / updating %d branches across all 7 provinces...', count($branchesData)));

        $branchMap = [];
        $ho = null;

        foreach ($branchesData as $b) {
            $branch = Branch::firstOrNew(['code' => $b['code']]);
            $isMain = ($b['code'] === 'NP-KTM-HO');

            $branch->fill([
                'name' => $b['name'],
                'type' => $isMain ? BranchType::HeadBranch : BranchType::FranchiseBranch,
                'status' => 'active',
                'province' => $b['province'],
                'district' => $b['district'],
                'city' => $b['city'],
                'area' => $b['area'] ?? $b['city'],
                'address' => $b['city'] . ', ' . $b['district'] . ', Nepal',
                'phone' => $b['phone'] ?? '9801' . rand(100000, 999999),
                'latitude' => $b['latitude'],
                'longitude' => $b['longitude'],
                'coverage_radius_km' => $b['radius'] ?? 15.0,
                'is_active' => true,
                'pickup_enabled' => true,
                'delivery_enabled' => true,
            ]);

            if ($isMain) {
                $branch->parent_id = null;
                $branch->save();
                $ho = $branch;
            } else {
                $branch->parent_id = $ho?->id;
                $branch->save();
            }

            // Sync Coverage Location for routing engine
            $cov = CoverageLocation::firstOrNew(['code' => $b['code']]);
            $cov->fill([
                'name' => $b['name'],
                'branch_id' => $branch->id,
                'country' => 'Nepal',
                'province' => $b['province'],
                'district' => $b['district'],
                'city' => $b['city'],
                'latitude' => $b['latitude'],
                'longitude' => $b['longitude'],
                'status' => 'active',
            ]);
            $cov->save();

            $branch->coverage_location_id = $cov->id;
            $branch->save();

            $branchMap[$b['code']] = $branch;
        }

        $this->info(sprintf('   [OK] %d active branches verified with GPS coordinates.', count($branchMap)));

        // 2. Seed Core Corridors & Transfer Lanes
        $this->info('2/5. Configuring inter-branch highway lanes & transfer routes...');
        $this->seedCorridorRoutes($branchMap);

        // 3. Seed Demo Transfer Requests (Branch to Transport Manager)
        $this->info('3/5. Generating real-world branch Transfer Requests (TR Requests)...');
        $this->seedTransferRequests($branchMap);

        // 4. Seed Active In-Transit Vehicles (Moving Line-Haul TRs)
        $this->info('4/5. Spawning active line-haul trucks & vans on Nepal highways...');
        $this->seedActiveManifests($branchMap);

        // 5. Seed Outbound Parcels at Branches
        $this->info('5/5. Staging realistic outbound parcel counts across branches...');
        $this->seedBranchOutboundParcels($branchMap);

        $this->info('================================================================');
        $this->info('  NEPAL TRANSFER NETWORK SETUP COMPLETED SUCCESSFULLY!  ');
        $this->info('================================================================');
        $this->info('Branches: ' . Branch::count() . ' (All 7 Provinces)');
        $this->info('Transfer Requests: ' . TransferRequest::count());
        $this->info('Active Manifests: ' . DispatchManifest::whereIn('status', ['dispatched', 'in_transit'])->count());

        return Command::SUCCESS;
    }

    private function seedCorridorRoutes(array $branchMap): void
    {
        // Core corridors between primary junctions
        $lanes = [
            // KTM <-> Narayanghat (Central Triangle Prithvi Corridor)
            ['NP-KTM-HO', 'NP-CHIT-BHR', 145, 5.0, 'road'],
            ['NP-CHIT-BHR', 'NP-KTM-HO', 145, 5.0, 'road'],

            // KTM <-> Pokhara (Prithvi Highway West)
            ['NP-KTM-HO', 'NP-KASK-PKR', 200, 6.0, 'road'],
            ['NP-KASK-PKR', 'NP-KTM-HO', 200, 6.0, 'road'],

            // Pokhara <-> Bharatpur / Narayanghat
            ['NP-KASK-PKR', 'NP-CHIT-BHR', 125, 4.0, 'road'],
            ['NP-CHIT-BHR', 'NP-KASK-PKR', 125, 4.0, 'road'],

            // Narayanghat <-> Butwal (Mahendra Highway West gateway)
            ['NP-CHIT-BHR', 'NP-RUPA-BTW', 115, 3.5, 'road'],
            ['NP-RUPA-BTW', 'NP-CHIT-BHR', 115, 3.5, 'road'],

            // Butwal <-> Arghakhanchi (Mountain Feeder Spur)
            ['NP-RUPA-BTW', 'NP-ARGH-SND', 80, 3.0, 'road'],
            ['NP-ARGH-SND', 'NP-RUPA-BTW', 80, 3.0, 'road'],

            // Pokhara <-> Mustang / Jomsom (Mountain Spur)
            ['NP-KASK-PKR', 'NP-MUST-JOM', 155, 7.0, 'road'],
            ['NP-MUST-JOM', 'NP-KASK-PKR', 155, 7.0, 'road'],
            ['NP-KASK-PKR', 'NP-MUST-JOM', 70, 0.5, 'flight'],
            ['NP-MUST-JOM', 'NP-KASK-PKR', 70, 0.5, 'flight'],

            // Narayanghat <-> Hetauda <-> Bardibas (Eastern Terai Corridor)
            ['NP-CHIT-BHR', 'NP-MAKW-HTD', 75, 2.0, 'road'],
            ['NP-MAKW-HTD', 'NP-CHIT-BHR', 75, 2.0, 'road'],
            ['NP-MAKW-HTD', 'NP-MAHO-BRD', 110, 3.0, 'road'],
            ['NP-MAHO-BRD', 'NP-MAKW-HTD', 110, 3.0, 'road'],

            // Bardibas <-> Itahari (East Highway Trunk)
            ['NP-MAHO-BRD', 'NP-SUNS-ITH', 160, 4.0, 'road'],
            ['NP-SUNS-ITH', 'NP-MAHO-BRD', 160, 4.0, 'road'],

            // Itahari <-> Biratnagar / Birtamod
            ['NP-SUNS-ITH', 'NP-MORA-BRT', 25, 0.8, 'road'],
            ['NP-MORA-BRT', 'NP-SUNS-ITH', 25, 0.8, 'road'],
            ['NP-SUNS-ITH', 'NP-JHAP-BRT', 75, 2.0, 'road'],
            ['NP-JHAP-BRT', 'NP-SUNS-ITH', 75, 2.0, 'road'],

            // Butwal <-> Dang <-> Nepalgunj <-> Dhangadhi (Western Trunk)
            ['NP-RUPA-BTW', 'NP-DANG-GHO', 130, 4.0, 'road'],
            ['NP-DANG-GHO', 'NP-RUPA-BTW', 130, 4.0, 'road'],
            ['NP-DANG-GHO', 'NP-BANK-NPJ', 120, 3.5, 'road'],
            ['NP-BANK-NPJ', 'NP-DANG-GHO', 120, 3.5, 'road'],
            ['NP-BANK-NPJ', 'NP-KAIL-DHN', 190, 5.0, 'road'],
            ['NP-KAIL-DHN', 'NP-BANK-NPJ', 190, 5.0, 'road'],
            ['NP-KAIL-DHN', 'NP-KANC-MHN', 50, 1.2, 'road'],
            ['NP-KANC-MHN', 'NP-KAIL-DHN', 50, 1.2, 'road'],
        ];

        foreach ($lanes as $lane) {
            [$fromCode, $toCode, $dist, $hours, $mode] = $lane;
            $from = $branchMap[$fromCode] ?? null;
            $to = $branchMap[$toCode] ?? null;

            if (! $from || ! $to) {
                continue;
            }

            $fromCovId = (int) ($from->coverage_location_id ?: $from->id);
            $toCovId = (int) ($to->coverage_location_id ?: $to->id);
            $sig = BranchTransferLane::checkpointSignature([]);

            $laneRecord = BranchTransferLane::firstOrCreate(
                [
                    'from_branch_id' => $fromCovId,
                    'to_branch_id'   => $toCovId,
                    'service_type'   => 'standard',
                    'path_signature' => $sig,
                ],
                [
                    'transport_mode'  => $mode,
                    'distance_km'     => $dist,
                    'estimated_hours' => $hours,
                    'is_active'       => true,
                ]
            );

            $route = BranchTransferRoute::firstOrCreate(
                [
                    'origin_branch_id'      => $fromCovId,
                    'destination_branch_id' => $toCovId,
                    'service_type'          => 'standard',
                ],
                [
                    'route_code'              => "{$fromCode}-{$toCode}-STD",
                    'name'                    => "{$from->city} to {$to->city}",
                    'branch_transfer_lane_id' => $laneRecord->id,
                    'distance_km'             => $dist,
                    'estimated_hours'         => (int) ceil($hours),
                    'is_active'               => true,
                ]
            );

            if (Schema::hasTable('branch_transfer_route_lanes')) {
                \Modules\Rate\Models\BranchTransferRouteLane::firstOrCreate(
                    [
                        'branch_transfer_route_id' => $route->id,
                        'branch_transfer_lane_id'  => $laneRecord->id,
                    ],
                    [
                        'sequence_number' => 1,
                    ]
                );
            }
        }
    }

    private function seedTransferRequests(array $branchMap): void
    {
        $requests = [
            [
                'origin' => 'NP-ARGH-SND',
                'dest' => 'NP-RUPA-BTW',
                'parcels' => 38,
                'weight' => 86.5,
                'vehicle' => 'pickup',
                'urgency' => 'critical_capacity_exceeded',
                'exceeded' => true,
                'notes' => 'Threshold 80kg exceeded! Local pickup accumulated 86.5 kg of goods heading for Butwal & Kathmandu. 4x4 Bolero required for hill route.',
            ],
            [
                'origin' => 'NP-MUST-JOM',
                'dest' => 'NP-KASK-PKR',
                'parcels' => 19,
                'weight' => 45.0,
                'vehicle' => 'pickup',
                'urgency' => 'high',
                'exceeded' => false,
                'notes' => 'Mountain apple / herbal express dispatch before evening pass weather deterioration.',
            ],
            [
                'origin' => 'NP-KTM-HO',
                'dest' => 'NP-CHIT-BHR',
                'parcels' => 180,
                'weight' => 540.0,
                'vehicle' => 'truck',
                'urgency' => 'high',
                'exceeded' => true,
                'notes' => 'Consolidated evening trunk dispatch to Central Narayanghat Hub for Terai cross-docking.',
            ],
            [
                'origin' => 'NP-KAIL-DHN',
                'dest' => 'NP-BANK-NPJ',
                'parcels' => 65,
                'weight' => 220.0,
                'vehicle' => 'van',
                'urgency' => 'normal',
                'exceeded' => true,
                'notes' => 'Far-west parcel batch heading towards Nepalgunj line-haul connection.',
            ],
            [
                'origin' => 'NP-JHAP-BRT',
                'dest' => 'NP-SUNS-ITH',
                'parcels' => 42,
                'weight' => 95.0,
                'vehicle' => 'van',
                'urgency' => 'normal',
                'exceeded' => true,
                'notes' => 'Eastern border commercial trade parcels for Itahari hub consolidation.',
            ],
            [
                'origin' => 'NP-CHIT-BHR',
                'dest' => 'NP-RUPA-BTW',
                'parcels' => 95,
                'weight' => 310.0,
                'vehicle' => 'truck',
                'urgency' => 'high',
                'exceeded' => true,
                'notes' => 'Cross-docked transfer to Butwal Hub with onward connections to Dang and Surkhet.',
            ],
        ];

        foreach ($requests as $idx => $r) {
            $from = $branchMap[$r['origin']] ?? null;
            $to = $branchMap[$r['dest']] ?? null;
            if (! $from || ! $to) {
                continue;
            }

            $num = sprintf('TR-REQ-%s-%04d', date('Ym'), $idx + 1);

            TransferRequest::updateOrCreate(
                ['request_number' => $num],
                [
                    'origin_branch_id'       => $from->id,
                    'destination_hub_id'     => $to->id,
                    'parcel_count'           => $r['parcels'],
                    'total_weight_kg'        => $r['weight'],
                    'requested_vehicle_type' => $r['vehicle'],
                    'urgency'                => $r['urgency'],
                    'threshold_exceeded'     => $r['exceeded'],
                    'status'                 => 'pending',
                    'notes'                  => $r['notes'],
                    'created_at'             => now()->subHours(rand(1, 6)),
                ]
            );
        }
    }

    private function seedActiveManifests(array $branchMap): void
    {
        $manifests = [
            [
                'from' => 'NP-KTM-HO',
                'to' => 'NP-CHIT-BHR',
                'num' => 'TR-KATHM-000101',
                'parcels' => 125,
                'driver' => 'Ram Bahadur Shrestha',
                'phone' => '9851023456',
                'vehicle' => 'truck',
                'plate' => 'Ba 3 Kha 8912',
                'seal' => 'SEAL-NP-8801',
                'hours' => 5.0,
                'dispatched_mins_ago' => 110,
            ],
            [
                'from' => 'NP-CHIT-BHR',
                'to' => 'NP-RUPA-BTW',
                'num' => 'TR-BHARA-000088',
                'parcels' => 88,
                'driver' => 'Bikram Thapa',
                'phone' => '9847055123',
                'vehicle' => 'truck',
                'plate' => 'Lu 2 Kha 4521',
                'seal' => 'SEAL-NP-8802',
                'hours' => 3.5,
                'dispatched_mins_ago' => 60,
            ],
            [
                'from' => 'NP-KASK-PKR',
                'to' => 'NP-KTM-HO',
                'num' => 'TR-POKHA-000045',
                'parcels' => 46,
                'driver' => 'Sunil Gurung',
                'phone' => '9866032145',
                'vehicle' => 'van',
                'plate' => 'Ga 1 Cha 3390',
                'seal' => 'SEAL-NP-8803',
                'hours' => 6.0,
                'dispatched_mins_ago' => 190,
            ],
            [
                'from' => 'NP-MAHO-BRD',
                'to' => 'NP-SUNS-ITH',
                'num' => 'TR-BARDI-000032',
                'parcels' => 74,
                'driver' => 'Mukesh Yadav',
                'phone' => '9804077889',
                'vehicle' => 'truck',
                'plate' => 'Na 5 Kha 1122',
                'seal' => 'SEAL-NP-8804',
                'hours' => 4.0,
                'dispatched_mins_ago' => 90,
            ],
        ];

        foreach ($manifests as $m) {
            $from = $branchMap[$m['from']] ?? null;
            $to = $branchMap[$m['to']] ?? null;
            if (! $from || ! $to) {
                continue;
            }

            $dispatchedAt = now()->subMinutes($m['dispatched_mins_ago']);
            $arrivalAt = (clone $dispatchedAt)->addMinutes((int) ($m['hours'] * 60));

            $manifest = DispatchManifest::updateOrCreate(
                ['transfer_number' => $m['num']],
                [
                    'manifest_number'      => 'MF-' . substr($m['num'], 3),
                    'from_branch_id'       => $from->id,
                    'to_branch_id'         => $to->id,
                    'status'               => 'in_transit',
                    'vehicle_type'         => $m['vehicle'],
                    'vehicle_number'       => $m['plate'],
                    'driver_name'          => $m['driver'],
                    'driver_phone'         => $m['phone'],
                    'seal_number'          => $m['seal'],
                    'expected_count'       => $m['parcels'],
                    'max_parcels'          => $m['vehicle'] === 'truck' ? 800 : 50,
                    'max_weight_kg'        => $m['vehicle'] === 'truck' ? 8000.0 : 250.0,
                    'estimated_hours'      => $m['hours'],
                    'estimated_arrival_at' => $arrivalAt,
                    'dispatched_at'        => $dispatchedAt,
                ]
            );
        }
    }

    private function seedBranchOutboundParcels(array $branchMap): void
    {
        // Give key branches staged sorted_for_transfer packages so UI stats glow realistically
        $stagedCounts = [
            'NP-KTM-HO'    => 280,
            'NP-CHIT-BHR'  => 165,
            'NP-KASK-PKR'  => 85,
            'NP-RUPA-BTW'  => 140,
            'NP-SUNS-ITH'  => 110,
            'NP-MORA-BRT'  => 90,
            'NP-MAKW-HTD'  => 65,
            'NP-MAHO-BRD'  => 55,
            'NP-BANK-NPJ'  => 78,
            'NP-KAIL-DHN'  => 82,
            'NP-ARGH-SND'  => 38,
            'NP-MUST-JOM'  => 19,
            'NP-DANG-GHO'  => 44,
            'NP-KANC-MHN'  => 35,
            'NP-JHAP-BRT'  => 60,
        ];

        foreach ($stagedCounts as $code => $count) {
            $branch = $branchMap[$code] ?? null;
            if (! $branch) {
                continue;
            }

            // Ensure there are at least some dummy sorted_for_transfer shipments if fewer than 5 exist
            $existing = Shipment::where('current_branch_id', $branch->id)
                ->where('status', 'sorted_for_transfer')
                ->count();

            $needed = min(15, max(0, $count - $existing));

            for ($i = 0; $i < $needed; $i++) {
                $targetCode = array_rand($branchMap);
                while ($targetCode === $code) {
                    $targetCode = array_rand($branchMap);
                }
                $destBranch = $branchMap[$targetCode];

                $shipmentData = [
                    'tracking_number'       => 'TUK' . strtoupper(Str::random(9)),
                    'status'                => 'sorted_for_transfer',
                    'service_type'          => (rand(1, 10) > 7) ? 'express' : 'standard',
                    'origin_branch_id'      => $branch->id,
                    'current_branch_id'     => $branch->id,
                    'destination_branch_id' => $destBranch->id,
                    'weight'                => rand(5, 50) / 10.0,
                    'chargeable_weight'     => rand(5, 50) / 10.0,
                    'sender_name'           => 'Nepal Trader ' . rand(100, 999),
                    'receiver_name'         => 'Customer ' . rand(100, 999),
                    'receiver_phone'        => '98' . rand(10000000, 99999999),
                ];

                if (Schema::hasColumn('shipments', 'delivery_address')) {
                    $shipmentData['delivery_address'] = $destBranch->city . ', Nepal';
                } elseif (Schema::hasColumn('shipments', 'receiver_address')) {
                    $shipmentData['receiver_address'] = $destBranch->city . ', Nepal';
                }

                Shipment::create($shipmentData);
            }
        }
    }

    private function branchesList(): array
    {
        return [
            // Bagmati Province
            ['code' => 'NP-KTM-HO', 'name' => 'Kathmandu Central HQ', 'province' => 'Bagmati', 'district' => 'Kathmandu', 'city' => 'Kathmandu', 'latitude' => 27.7172, 'longitude' => 85.3240],
            ['code' => 'NP-LAL-PAT', 'name' => 'Lalitpur Patan Branch', 'province' => 'Bagmati', 'district' => 'Lalitpur', 'city' => 'Lalitpur', 'latitude' => 27.6710, 'longitude' => 85.3206],
            ['code' => 'NP-BHK-BHK', 'name' => 'Bhaktapur Branch', 'province' => 'Bagmati', 'district' => 'Bhaktapur', 'city' => 'Bhaktapur', 'latitude' => 27.6710, 'longitude' => 85.4298],
            ['code' => 'NP-DHAD-BES', 'name' => 'Dhading Besi Branch', 'province' => 'Bagmati', 'district' => 'Dhading', 'city' => 'Dhading', 'latitude' => 27.8767, 'longitude' => 84.9083],
            ['code' => 'NP-MAKW-HTD', 'name' => 'Hetauda Hub', 'province' => 'Bagmati', 'district' => 'Makwanpur', 'city' => 'Hetauda', 'latitude' => 27.4285, 'longitude' => 85.0322],
            ['code' => 'NP-SIND-KAM', 'name' => 'Sindhuli Kamalamai Branch', 'province' => 'Bagmati', 'district' => 'Sindhuli', 'city' => 'Sindhuli', 'latitude' => 27.2500, 'longitude' => 85.9167],
            ['code' => 'NP-KAVR-BAN', 'name' => 'Banepa Branch', 'province' => 'Bagmati', 'district' => 'Kavrepalanchok', 'city' => 'Banepa', 'latitude' => 27.6298, 'longitude' => 85.5214],

            // Gandaki Province
            ['code' => 'NP-KASK-PKR', 'name' => 'Pokhara Western Hub', 'province' => 'Gandaki', 'district' => 'Kaski', 'city' => 'Pokhara', 'latitude' => 28.2096, 'longitude' => 83.9856],
            ['code' => 'NP-CHIT-BHR', 'name' => 'Bharatpur / Narayanghat Central Hub', 'province' => 'Bagmati', 'district' => 'Chitwan', 'city' => 'Bharatpur', 'latitude' => 27.6833, 'longitude' => 84.4333],
            ['code' => 'NP-CHIT-MUG', 'name' => 'Mugling Junction Branch', 'province' => 'Bagmati', 'district' => 'Chitwan', 'city' => 'Mugling', 'latitude' => 27.8569, 'longitude' => 84.5574],
            ['code' => 'NP-NAWA-KAW', 'name' => 'Kawasoti Branch', 'province' => 'Gandaki', 'district' => 'Nawalpur', 'city' => 'Kawasoti', 'latitude' => 27.6500, 'longitude' => 84.1167],
            ['code' => 'NP-TANA-DAM', 'name' => 'Damauli Branch', 'province' => 'Gandaki', 'district' => 'Tanahun', 'city' => 'Damauli', 'latitude' => 27.9711, 'longitude' => 84.2833],
            ['code' => 'NP-GORK-GOK', 'name' => 'Gorkha Branch', 'province' => 'Gandaki', 'district' => 'Gorkha', 'city' => 'Gorkha', 'latitude' => 28.0000, 'longitude' => 84.6333],
            ['code' => 'NP-LAMJ-BES', 'name' => 'Besisahar Branch', 'province' => 'Gandaki', 'district' => 'Lamjung', 'city' => 'Besisahar', 'latitude' => 28.2333, 'longitude' => 84.3833],
            ['code' => 'NP-MYAG-BEN', 'name' => 'Beni Branch', 'province' => 'Gandaki', 'district' => 'Myagdi', 'city' => 'Beni', 'latitude' => 28.3439, 'longitude' => 83.5647],
            ['code' => 'NP-MUST-JOM', 'name' => 'Mustang Jomsom Mountain Hub', 'province' => 'Gandaki', 'district' => 'Mustang', 'city' => 'Jomsom', 'latitude' => 28.7817, 'longitude' => 83.7381],
            ['code' => 'NP-BAGL-BAG', 'name' => 'Baglung Branch', 'province' => 'Gandaki', 'district' => 'Baglung', 'city' => 'Baglung', 'latitude' => 28.2717, 'longitude' => 83.5939],
            ['code' => 'NP-PARB-KUS', 'name' => 'Kusma Branch', 'province' => 'Gandaki', 'district' => 'Parbat', 'city' => 'Kusma', 'latitude' => 28.2167, 'longitude' => 83.6833],

            // Lumbini Province
            ['code' => 'NP-RUPA-BTW', 'name' => 'Butwal Commercial Hub', 'province' => 'Lumbini', 'district' => 'Rupandehi', 'city' => 'Butwal', 'latitude' => 27.7006, 'longitude' => 83.4484],
            ['code' => 'NP-RUPA-BHA', 'name' => 'Bhairahawa Lumbini Gateway', 'province' => 'Lumbini', 'district' => 'Rupandehi', 'city' => 'Bhairahawa', 'latitude' => 27.5044, 'longitude' => 83.4503],
            ['code' => 'NP-PALP-TAN', 'name' => 'Tansen Palpa Branch', 'province' => 'Lumbini', 'district' => 'Palpa', 'city' => 'Tansen', 'latitude' => 27.8672, 'longitude' => 83.5469],
            ['code' => 'NP-ARGH-SND', 'name' => 'Sandhikharka Arghakhanchi Branch', 'province' => 'Lumbini', 'district' => 'Arghakhanchi', 'city' => 'Sandhikharka', 'latitude' => 27.9772, 'longitude' => 83.1367],
            ['code' => 'NP-KAPI-TAU', 'name' => 'Taulihawa Kapilvastu Branch', 'province' => 'Lumbini', 'district' => 'Kapilvastu', 'city' => 'Taulihawa', 'latitude' => 27.5458, 'longitude' => 83.0539],
            ['code' => 'NP-DANG-GHO', 'name' => 'Ghorahi Dang Branch', 'province' => 'Lumbini', 'district' => 'Dang', 'city' => 'Ghorahi', 'latitude' => 28.0417, 'longitude' => 82.4833],
            ['code' => 'NP-DANG-TUL', 'name' => 'Tulsipur Dang Branch', 'province' => 'Lumbini', 'district' => 'Dang', 'city' => 'Tulsipur', 'latitude' => 28.1306, 'longitude' => 82.2961],
            ['code' => 'NP-PYUT-PYU', 'name' => 'Pyuthan Branch', 'province' => 'Lumbini', 'district' => 'Pyuthan', 'city' => 'Pyuthan', 'latitude' => 28.1000, 'longitude' => 82.8833],
            ['code' => 'NP-ROLP-LIW', 'name' => 'Liwang Rolpa Branch', 'province' => 'Lumbini', 'district' => 'Rolpa', 'city' => 'Liwang', 'latitude' => 28.3000, 'longitude' => 82.6333],

            // Koshi Province
            ['code' => 'NP-MORA-BRT', 'name' => 'Biratnagar Eastern Commercial Hub', 'province' => 'Koshi', 'district' => 'Morang', 'city' => 'Biratnagar', 'latitude' => 26.4525, 'longitude' => 87.2718],
            ['code' => 'NP-SUNS-ITH', 'name' => 'Itahari Highway Junction Hub', 'province' => 'Koshi', 'district' => 'Sunsari', 'city' => 'Itahari', 'latitude' => 26.6636, 'longitude' => 87.2747],
            ['code' => 'NP-SUNS-DHA', 'name' => 'Dharan Branch', 'province' => 'Koshi', 'district' => 'Sunsari', 'city' => 'Dharan', 'latitude' => 26.8124, 'longitude' => 87.2834],
            ['code' => 'NP-SUNS-INA', 'name' => 'Inaruwa Branch', 'province' => 'Koshi', 'district' => 'Sunsari', 'city' => 'Inaruwa', 'latitude' => 26.6022, 'longitude' => 87.1519],
            ['code' => 'NP-JHAP-DMK', 'name' => 'Damak Branch', 'province' => 'Koshi', 'district' => 'Jhapa', 'city' => 'Damak', 'latitude' => 26.6588, 'longitude' => 87.7006],
            ['code' => 'NP-JHAP-BRT', 'name' => 'Birtamod Commercial Branch', 'province' => 'Koshi', 'district' => 'Jhapa', 'city' => 'Birtamod', 'latitude' => 26.6416, 'longitude' => 87.9904],
            ['code' => 'NP-JHAP-BHA', 'name' => 'Bhadrapur Border Gateway', 'province' => 'Koshi', 'district' => 'Jhapa', 'city' => 'Bhadrapur', 'latitude' => 26.5458, 'longitude' => 88.0935],
            ['code' => 'NP-ILAM-ILA', 'name' => 'Ilam Tea Garden Branch', 'province' => 'Koshi', 'district' => 'Ilam', 'city' => 'Ilam', 'latitude' => 26.9100, 'longitude' => 87.9275],
            ['code' => 'NP-PANC-PHI', 'name' => 'Phidim Branch', 'province' => 'Koshi', 'district' => 'Panchthar', 'city' => 'Phidim', 'latitude' => 27.1500, 'longitude' => 87.7600],
            ['code' => 'NP-UDAY-GAI', 'name' => 'Gaighat Branch', 'province' => 'Koshi', 'district' => 'Udayapur', 'city' => 'Gaighat', 'latitude' => 26.7925, 'longitude' => 86.7025],

            // Madhesh Province
            ['code' => 'NP-DHAN-JAN', 'name' => 'Janakpur Cultural Hub', 'province' => 'Madhesh', 'district' => 'Dhanusha', 'city' => 'Janakpur', 'latitude' => 26.7288, 'longitude' => 85.9244],
            ['code' => 'NP-MAHO-BRD', 'name' => 'Bardibas BP Highway Junction Hub', 'province' => 'Madhesh', 'district' => 'Mahottari', 'city' => 'Bardibas', 'latitude' => 26.9833, 'longitude' => 85.9000],
            ['code' => 'NP-MAHO-JAL', 'name' => 'Jaleshwar Branch', 'province' => 'Madhesh', 'district' => 'Mahottari', 'city' => 'Jaleshwar', 'latitude' => 26.6450, 'longitude' => 85.8010],
            ['code' => 'NP-SIRA-LAH', 'name' => 'Lahan Branch', 'province' => 'Madhesh', 'district' => 'Siraha', 'city' => 'Lahan', 'latitude' => 26.7296, 'longitude' => 86.4950],
            ['code' => 'NP-SAPT-RAJ', 'name' => 'Rajbiraj Branch', 'province' => 'Madhesh', 'district' => 'Saptari', 'city' => 'Rajbiraj', 'latitude' => 26.5414, 'longitude' => 86.7533],
            ['code' => 'NP-PARS-BRG', 'name' => 'Birgunj Dry Port Gateway', 'province' => 'Madhesh', 'district' => 'Parsa', 'city' => 'Birgunj', 'latitude' => 27.0135, 'longitude' => 84.8774],
            ['code' => 'NP-BARA-KAL', 'name' => 'Kalaiya Branch', 'province' => 'Madhesh', 'district' => 'Bara', 'city' => 'Kalaiya', 'latitude' => 27.0319, 'longitude' => 85.0028],
            ['code' => 'NP-RAUT-GAU', 'name' => 'Gaur Branch', 'province' => 'Madhesh', 'district' => 'Rautahat', 'city' => 'Gaur', 'latitude' => 26.7667, 'longitude' => 85.2833],

            // Karnali & Sudurpashchim Provinces
            ['code' => 'NP-BANK-NPJ', 'name' => 'Nepalgunj Gateway Hub', 'province' => 'Lumbini', 'district' => 'Banke', 'city' => 'Nepalgunj', 'latitude' => 28.0500, 'longitude' => 81.6167],
            ['code' => 'NP-BANK-KOH', 'name' => 'Kohalpur Highway Junction', 'province' => 'Lumbini', 'district' => 'Banke', 'city' => 'Kohalpur', 'latitude' => 28.1883, 'longitude' => 81.6917],
            ['code' => 'NP-SURK-BIR', 'name' => 'Birendranagar Surkhet Hub', 'province' => 'Karnali', 'district' => 'Surkhet', 'city' => 'Birendranagar', 'latitude' => 28.5983, 'longitude' => 81.6339],
            ['code' => 'NP-DAIL-DAI', 'name' => 'Dailekh Branch', 'province' => 'Karnali', 'district' => 'Dailekh', 'city' => 'Dailekh', 'latitude' => 28.8378, 'longitude' => 81.7100],
            ['code' => 'NP-JUML-JUM', 'name' => 'Jumla Mountain Hub', 'province' => 'Karnali', 'district' => 'Jumla', 'city' => 'Jumla', 'latitude' => 29.2747, 'longitude' => 82.1839],
            ['code' => 'NP-KAIL-DHN', 'name' => 'Dhangadhi Far-West Hub', 'province' => 'Sudurpashchim', 'district' => 'Kailali', 'city' => 'Dhangadhi', 'latitude' => 28.6833, 'longitude' => 80.6000],
            ['code' => 'NP-KAIL-TIK', 'name' => 'Tikapur Branch', 'province' => 'Sudurpashchim', 'district' => 'Kailali', 'city' => 'Tikapur', 'latitude' => 28.5333, 'longitude' => 81.1333],
            ['code' => 'NP-KANC-MHN', 'name' => 'Mahendranagar Border Hub', 'province' => 'Sudurpashchim', 'district' => 'Kanchanpur', 'city' => 'Mahendranagar', 'latitude' => 28.9667, 'longitude' => 80.1833],
            ['code' => 'NP-DADE-DAD', 'name' => 'Dadeldhura Hill Hub', 'province' => 'Sudurpashchim', 'district' => 'Dadeldhura', 'city' => 'Dadeldhura', 'latitude' => 29.3000, 'longitude' => 80.5833],
            ['code' => 'NP-DOTI-DIP', 'name' => 'Dipayal Silgadhi Branch', 'province' => 'Sudurpashchim', 'district' => 'Doti', 'city' => 'Dipayal', 'latitude' => 29.2611, 'longitude' => 80.9389],
        ];
    }
}
