<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Database\Seeders\TransferCorridorSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Enums\BranchStatus;
use Modules\Branch\Enums\BranchType;
use Modules\Branch\Models\Branch;

/**
 * LOCAL-ONLY demo demand matching the transport manager's example volumes.
 * Do not run in production. Idempotent: replaces TMS-DEMO-* shipments only.
 *
 *   50 PKR->KTM, 40 PKR->Arghakhanchi, 30 KTM->Hetauda, 60 HTD->KTM,
 *   40 PKR->Bardibas, 30 KTM->Bardibas, 50 PKR->Itahari, 40 KTM->Itahari,
 *   60 HTD->Itahari, 60 HTD->Pokhara, 40 HTD->Mustang (Jomsom).
 */
class TransferPlanningDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->ensureBardibas();
        (new TransferCorridorSeeder())->run();

        $byCode = Branch::query()->get()->keyBy('code');
        $need = [
            'NP-BR-POKHA' => 'Pokhara',
            'NP-BR-KATHM' => 'Kathmandu',
            'NP-BR-HETAU' => 'Hetauda',
            'NP-BR-ARGHA' => 'Arghakhanchi',
            'NP-BR-BARDI' => 'Bardibas',
            'NP-BR-ITAHA' => 'Itahari',
            'NP-BR-JOMSO' => 'Jomsom / Mustang',
        ];
        foreach ($need as $code => $label) {
            if (! $byCode->get($code)) {
                $this->command?->error("Missing branch {$code} ({$label}). Aborting demo seed.");
                return;
            }
        }

        $merchantId = (int) (DB::table('merchants')->orderBy('id')->value('id') ?: 0);
        if ($merchantId <= 0) {
            $this->command?->error('No merchant found to attach demo shipments.');
            return;
        }

        $oldIds = DB::table('shipments')->where('tracking_number', 'like', 'TMS-DEMO-%')->pluck('id');
        if ($oldIds->isNotEmpty()) {
            if (Schema::hasTable('dispatch_manifest_items')) {
                DB::table('dispatch_manifest_items')->whereIn('shipment_id', $oldIds)->delete();
            }
            if (Schema::hasTable('transfer_run_items')) {
                DB::table('transfer_run_items')->whereIn('shipment_id', $oldIds)->delete();
            }
            DB::table('shipments')->whereIn('id', $oldIds)->delete();
        }
        if (Schema::hasTable('transfer_requests')) {
            DB::table('transfer_requests')->where('notes', 'like', 'TMS-DEMO%')->delete();
        }

        $lanes = [
            // origin, dest, count, expressCount, slaRiskCount (aged), destCity
            ['NP-BR-POKHA', 'NP-BR-KATHM', 50, 10, 6, 'Kathmandu'],
            ['NP-BR-POKHA', 'NP-BR-ARGHA', 40, 8, 4, 'Sandhikharka'],
            ['NP-BR-KATHM', 'NP-BR-HETAU', 30, 6, 3, 'Hetauda'],
            ['NP-BR-HETAU', 'NP-BR-KATHM', 60, 12, 8, 'Kathmandu'],
            ['NP-BR-POKHA', 'NP-BR-BARDI', 40, 8, 5, 'Bardibas'],
            ['NP-BR-KATHM', 'NP-BR-BARDI', 30, 6, 3, 'Bardibas'],
            ['NP-BR-POKHA', 'NP-BR-ITAHA', 50, 10, 7, 'Itahari'],
            ['NP-BR-KATHM', 'NP-BR-ITAHA', 40, 8, 5, 'Itahari'],
            ['NP-BR-HETAU', 'NP-BR-ITAHA', 60, 12, 6, 'Itahari'],
            ['NP-BR-HETAU', 'NP-BR-POKHA', 60, 10, 5, 'Pokhara'],
            ['NP-BR-HETAU', 'NP-BR-JOMSO', 40, 8, 8, 'Jomsom'],
        ];

        $now = Carbon::now();
        $seq = 1;
        $rows = [];
        foreach ($lanes as [$originCode, $destCode, $count, $express, $sla, $destCity]) {
            $origin = $byCode->get($originCode);
            $dest = $byCode->get($destCode);
            for ($i = 1; $i <= $count; $i++) {
                $isExpress = $i <= $express;
                $isSla = $i <= $sla;
                $service = $isExpress ? 'express' : 'standard';
                $created = $isSla
                    ? $now->copy()->subHours($isExpress ? 18 : 36)->subMinutes($i)
                    : $now->copy()->subHours($isExpress ? 4 : 8)->subMinutes($i);
                $tracking = sprintf('TMS-DEMO-%s-%s-%04d', substr($originCode, -5), substr($destCode, -5), $seq);
                $rows[] = [
                    'tracking_number' => $tracking,
                    'merchant_id' => $merchantId,
                    'merchant_order_id' => $tracking,
                    'order_source' => 'tms_demo',
                    'service_type' => $service,
                    'source' => 'manual',
                    'origin_branch_id' => $origin->id,
                    'destination_branch_id' => $dest->id,
                    'current_branch_id' => $origin->id,
                    'sender_name' => 'TMS Demo Sender',
                    'sender_phone' => '9800000000',
                    'sender_city' => $origin->city,
                    'receiver_name' => 'TMS Demo Receiver',
                    'receiver_phone' => '9800000001',
                    'delivery_address' => $destCity.' demo address',
                    'receiver_city' => $destCity,
                    'parcel_type' => 'non_fragile',
                    'description' => 'TMS planning demo parcel',
                    'quantity' => 1,
                    'weight' => 1.2,
                    'chargeable_weight' => 1.2,
                    'declared_value' => 500,
                    'payment_type' => 'prepaid',
                    'status' => 'sorted_for_transfer',
                    'sorted_at' => $created,
                    'estimated_delivery_time' => $isSla ? $created->copy()->addHours($isExpress ? 11 : 24) : $created->copy()->addHours($isExpress ? 11 : 24),
                    'created_at' => $created,
                    'updated_at' => $created,
                ];
                $seq++;
            }
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('shipments')->insert($chunk);
        }

        if (Schema::hasTable('transfer_requests')) {
            $cols = Schema::getColumnListing('transfer_requests');
            $reqSeq = 1;
            $make = function (Branch $from, Branch $to, string $service, int $count) use ($cols, $now, &$reqSeq) {
                $row = [];
                $map = [
                    'request_number' => 'TMS-DEMO-REQ-'.str_pad((string) $reqSeq++, 3, '0', STR_PAD_LEFT),
                    'from_branch_id' => $from->id,
                    'to_branch_id' => $to->id,
                    'origin_branch_id' => $from->id,
                    'destination_hub_id' => $to->id,
                    'service_type' => $service,
                    'preferred_service_type' => $service,
                    'vehicle_type' => 'pickup',
                    'requested_vehicle_type' => 'pickup',
                    'parcel_count' => $count,
                    'expected_count' => $count,
                    'urgency' => $service === 'express' ? 'high' : 'normal',
                    'status' => 'pending',
                    'notes' => 'TMS-DEMO branch request',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                foreach ($map as $k => $v) {
                    if (in_array($k, $cols, true)) {
                        $row[$k] = $v;
                    }
                }
                if ($row !== []) {
                    DB::table('transfer_requests')->insert($row);
                }
            };
            $make($byCode['NP-BR-POKHA'], $byCode['NP-BR-KATHM'], 'express', 10);
            $make($byCode['NP-BR-HETAU'], $byCode['NP-BR-ITAHA'], 'standard', 60);
            $make($byCode['NP-BR-KATHM'], $byCode['NP-BR-HETAU'], 'express', 6);
        }

        $this->command?->info('Seeded '.count($rows).' TMS demo parcels waiting to transfer (local only).');
    }

    private function ensureBardibas(): void
    {
        $existing = Branch::query()->where('code', 'NP-BR-BARDI')->first();
        if (! $existing) {
            $existing = Branch::query()->create([
                'name' => 'Bardibas Franchise Branch',
                'code' => 'NP-BR-BARDI',
                'type' => BranchType::FranchiseBranch,
                'status' => BranchStatus::Active,
                'city' => 'Bardibas',
                'district' => 'Mahottari',
                'province' => 'Madhesh',
                'country' => 'Nepal',
                'address' => 'Bardibas, Mahottari',
                'latitude' => 26.9855,
                'longitude' => 85.8940,
                'office_latitude' => 26.9855,
                'office_longitude' => 85.8940,
                'pickup_enabled' => true,
                'delivery_enabled' => true,
                'coverage_location_id' => Schema::hasTable('coverage_locations') ? 13 : null,
            ]);
        }
        if (Schema::hasTable('coverage_locations') && Schema::hasColumn('coverage_locations', 'branch_id')) {
            DB::table('coverage_locations')->where('id', 13)->update(['branch_id' => $existing->id]);
        }
        if (Schema::hasColumn('branches', 'coverage_location_id') && ! $existing->coverage_location_id) {
            $existing->forceFill(['coverage_location_id' => 13])->save();
        }
    }
}
