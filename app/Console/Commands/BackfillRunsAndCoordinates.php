<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Delivery\Models\DeliveryRun;
use Modules\Delivery\Models\DeliveryRunItem;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Models\PickupRun;
use Modules\Pickup\Models\PickupRunItem;
use Modules\Shipment\Models\Shipment;

/**
 * Backfill existing orders, shipments, and pickups:
 * 1. Backfills missing coordinates from area names (Koteshwor, Baneshwor, etc.)
 * 2. Optionally groups existing active legacy assignments into sequenced Runs
 *
 * Usage:
 *   php artisan runs:backfill --coordinates
 *   php artisan runs:backfill --group-active
 *   php artisan runs:backfill --all --apply
 */
class BackfillRunsAndCoordinates extends Command
{
    protected $signature = 'runs:backfill
                            {--coordinates : Resolve and backfill missing lat/lng for existing shipments and pickups}
                            {--group-active : Auto-group existing active assigned pickups/deliveries into runs}
                            {--all : Run both coordinates and active assignments backfill}
                            {--apply : Commit changes to the database (preview only without this flag)}';

    protected $description = 'Backfill missing geo-coordinates and group legacy active pickups and deliveries into sequenced runs';

    private const KATHMANDU_PLACES = [
        'sallaghari' => [27.6718, 85.4286],
        'sanothimi' => [27.6812, 85.3705],
        'thimi' => [27.6722, 85.3868],
        'balkot' => [27.6644, 85.3615],
        'mid baneshwor' => [27.6955, 85.3375],
        'middle baneshwor' => [27.6955, 85.3375],
        'new baneshwor' => [27.6915, 85.3420],
        'old baneshwor' => [27.6990, 85.3330],
        'baneshwor' => [27.6916, 85.3422],
        'koteshwor' => [27.6792, 85.3494],
        'tinkune' => [27.6865, 85.3465],
        'gaushala' => [27.7070, 85.3460],
        'chabahil' => [27.7172, 85.3468],
        'sinamangal' => [27.6960, 85.3500],
        'battisputali' => [27.7042, 85.3418],
        'jorpati' => [27.7200, 85.3750],
        'bouddha' => [27.7215, 85.3620],
        'maitighar' => [27.6922, 85.3218],
        'tripureshwor' => [27.6953, 85.3134],
        'kalanki' => [27.6934, 85.2815],
        'thamel' => [27.7154, 85.3123],
        'lazimpat' => [27.7212, 85.3197],
        'balaju' => [27.7329, 85.3025],
        'maharajgunj' => [27.7364, 85.3308],
        'kupandole' => [27.6872, 85.3168],
        'pulchowk' => [27.6784, 85.3162],
        'jawalakhel' => [27.6728, 85.3155],
        'lagankhel' => [27.6672, 85.3242],
        'satdobato' => [27.6575, 85.3255],
        'balkhu' => [27.6851, 85.2974],
        'kalimati' => [27.6987, 85.2996],
        'dillibazar' => [27.7047, 85.3275],
        'putalisadak' => [27.7031, 85.3212],
        'anamnagar' => [27.6975, 85.3262],
        'gyaneshwor' => [27.7088, 85.3341],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $doCoords = $this->option('coordinates') || $this->option('all');
        $doGroup = $this->option('group-active') || $this->option('all');

        if (! $doCoords && ! $doGroup) {
            $this->info('Please specify --coordinates, --group-active, or --all.');
            $this->comment('Use --apply to persist changes to the database.');
            return self::SUCCESS;
        }

        $this->info($apply ? 'Executing backfill with --apply...' : 'Running preview (dry run). Use --apply to execute.');

        if ($doCoords) {
            $this->backfillCoordinates($apply);
        }

        if ($doGroup) {
            $this->backfillActivePickupRuns($apply);
            $this->backfillActiveDeliveryRuns($apply);
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function backfillCoordinates(bool $apply): void
    {
        $this->info('--- 1. Backfilling Geo Coordinates ---');

        // Shipments
        $shipments = Shipment::query()
            ->where(function ($q) {
                $q->whereNull('delivery_lat')->orWhere('delivery_lat', 0)
                  ->orWhereNull('pickup_lat')->orWhere('pickup_lat', 0);
            })
            ->get(['id', 'receiver_area', 'delivery_address', 'delivery_lat', 'delivery_lng', 'sender_area', 'pickup_address', 'pickup_lat', 'pickup_lng']);

        $shipmentsUpdated = 0;
        foreach ($shipments as $s) {
            $updates = [];
            if (empty($s->delivery_lat) || (float) $s->delivery_lat === 0.0) {
                $coords = $this->findCoords((string) $s->receiver_area, (string) $s->delivery_address);
                if ($coords) {
                    $updates['delivery_lat'] = $coords[0];
                    $updates['delivery_lng'] = $coords[1];
                }
            }
            if (empty($s->pickup_lat) || (float) $s->pickup_lat === 0.0) {
                $coords = $this->findCoords((string) $s->sender_area, (string) $s->pickup_address);
                if ($coords) {
                    $updates['pickup_lat'] = $coords[0];
                    $updates['pickup_lng'] = $coords[1];
                }
            }
            if (! empty($updates)) {
                $shipmentsUpdated++;
                if ($apply) {
                    $s->update($updates);
                }
            }
        }
        $this->line("Shipments with resolved coordinates: {$shipmentsUpdated} of {$shipments->count()}");

        // Pickup requests
        $pickups = PickupRequest::query()
            ->whereNull('pickup_lat')
            ->orWhere('pickup_lat', 0)
            ->get(['id', 'pickup_area', 'pickup_city', 'pickup_address', 'pickup_lat', 'pickup_lng']);

        $pickupsUpdated = 0;
        foreach ($pickups as $p) {
            $coords = $this->findCoords((string) ($p->pickup_area ?: $p->pickup_city), (string) $p->pickup_address);
            if ($coords) {
                $pickupsUpdated++;
                if ($apply) {
                    $p->update([
                        'pickup_lat' => $coords[0],
                        'pickup_lng' => $coords[1],
                    ]);
                }
            }
        }
        $this->line("Pickups with resolved coordinates: {$pickupsUpdated} of {$pickups->count()}");
    }

    private function backfillActivePickupRuns(bool $apply): void
    {
        $this->info('--- 2. Grouping Active Legacy Pickups into Runs ---');

        // Pickups that are assigned to a rider but not on a pickup_run
        $unassignedPickups = PickupRequest::query()
            ->whereNotNull('assigned_to')
            ->whereIn('status', ['assigned', 'accepted', 'in_progress'])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('pickup_run_items as pri')
                    ->whereColumn('pri.pickup_request_id', 'pickup_requests.id');
            })
            ->get();

        $grouped = $unassignedPickups->groupBy(function ($p) {
            $branchId = (int) ($p->pickup_sub_branch_id ?: $p->pickup_branch_id ?: $p->sub_branch_id ?: $p->branch_id ?: 1);
            return "{$branchId}_{$p->assigned_to}";
        });

        $this->line("Found {$unassignedPickups->count()} active legacy pickups across {$grouped->count()} rider group(s).");

        foreach ($grouped as $key => $items) {
            [$branchId, $riderId] = explode('_', (string) $key);
            $branchId = (int) $branchId;
            $riderId = (int) $riderId;
            $riderName = User::query()->where('id', $riderId)->value('name') ?: "Rider #{$riderId}";

            $this->line("-> Branch #{$branchId}, Rider {$riderName}: {$items->count()} pickup(s)");

            if ($apply) {
                $branchCode = Branch::query()->where('id', $branchId)->value('code') ?: 'MAIN';
                $prefix = 'PR-'.strtoupper(Str::slug($branchCode, ''));
                $count = DB::table('pickup_runs')->where('branch_id', $branchId)->count() + 1;
                $runNumber = sprintf('%s-%06d', $prefix, $count);

                $run = PickupRun::create([
                    'run_number' => $runNumber,
                    'branch_id' => $branchId,
                    'rider_user_id' => $riderId,
                    'status' => PickupRun::STATUS_IN_PROGRESS,
                    'expected_count' => $items->count(),
                    'picked_up_count' => 0,
                    'assigned_at' => now(),
                    'dispatched_at' => now(),
                    'notes' => 'Backfilled from active assigned pickups',
                ]);

                foreach ($items as $idx => $p) {
                    PickupRunItem::create([
                        'pickup_run_id' => $run->id,
                        'pickup_request_id' => $p->id,
                        'sequence' => $idx + 1,
                        'status' => PickupRunItem::STATUS_IN_PROGRESS,
                    ]);
                    $p->update(['pickup_run_id' => $run->id]);
                }
            }
        }
    }

    private function backfillActiveDeliveryRuns(bool $apply): void
    {
        $this->info('--- 3. Grouping Active Legacy Deliveries into Runs ---');

        $activeDeliveries = DeliveryAssignment::query()
            ->whereNotNull('rider_id')
            ->whereIn('status', ['assigned', 'accepted', 'out_for_delivery'])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('delivery_run_items as dri')
                    ->whereColumn('dri.delivery_id', 'delivery_assignments.id');
            })
            ->get();

        $grouped = $activeDeliveries->groupBy(function ($d) {
            $branchId = (int) ($d->sub_branch_id ?: $d->branch_id ?: 1);
            return "{$branchId}_{$d->rider_id}";
        });

        $this->line("Found {$activeDeliveries->count()} active legacy delivery assignments across {$grouped->count()} rider group(s).");

        foreach ($grouped as $key => $items) {
            [$branchId, $riderId] = explode('_', (string) $key);
            $branchId = (int) $branchId;
            $riderId = (int) $riderId;
            $riderName = User::query()->where('id', $riderId)->value('name') ?: "Rider #{$riderId}";

            $this->line("-> Branch #{$branchId}, Rider {$riderName}: {$items->count()} parcel(s)");

            if ($apply) {
                $branchCode = Branch::query()->where('id', $branchId)->value('code') ?: 'MAIN';
                $prefix = 'DR-'.strtoupper(Str::slug($branchCode, ''));
                $count = DB::table('delivery_runs')->where('branch_id', $branchId)->count() + 1;
                $runNumber = sprintf('%s-%06d', $prefix, $count);

                $run = DeliveryRun::create([
                    'run_number' => $runNumber,
                    'branch_id' => $branchId,
                    'rider_user_id' => $riderId,
                    'status' => DeliveryRun::STATUS_OUT,
                    'expected_count' => $items->count(),
                    'delivered_count' => 0,
                    'assigned_at' => now(),
                    'dispatched_at' => now(),
                    'notes' => 'Backfilled from active delivery assignments',
                ]);

                foreach ($items as $idx => $d) {
                    DeliveryRunItem::create([
                        'delivery_run_id' => $run->id,
                        'shipment_id' => $d->shipment_id,
                        'delivery_id' => $d->id,
                        'sequence' => $idx + 1,
                        'status' => DeliveryRunItem::STATUS_OUT,
                    ]);
                }
            }
        }
    }

    private function findCoords(string $area, string $address): ?array
    {
        $haystack = strtolower("{$area} {$address}");
        foreach (self::KATHMANDU_PLACES as $name => $coords) {
            if (str_contains($haystack, $name)) {
                return $coords;
            }
        }
        return null;
    }
}

