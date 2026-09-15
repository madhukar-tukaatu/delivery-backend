<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Shipment\Models\Shipment;
use Modules\Branch\Models\Branch;
use Illuminate\Support\Facades\DB;

class BackfillShipmentBranchIds extends Command
{
    protected $signature = 'shipment:backfill-branch-ids {--dry-run : Preview changes without committing}';

    protected $description = 'Backfill origin_branch_id and destination_branch_id for existing shipments';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be committed');
        }

        $this->info('Starting backfill of missing branch IDs...');
        $this->newLine();

        // Get all shipments with NULL origin_branch_id or destination_branch_id
        $shipments = Shipment::query()
            ->whereNull('origin_branch_id')
            ->orWhereNull('destination_branch_id')
            ->get();

        $this->info("Found {$shipments->count()} shipments with missing branch IDs");

        if ($shipments->isEmpty()) {
            $this->info('✅ All shipments have branch IDs. Nothing to backfill.');
            return 0;
        }

        $updated = 0;
        $skipped = 0;
        $errors = 0;

        // Get active branches for destination matching
        $branches = Branch::query()
            ->where(function ($q) {
                $q->where('status', 'active')
                    ->orWhere(function ($subQ) {
                        $subQ->where('status', 'approved')
                            ->where('account_invitation_status', 'account_configured');
                    });
            })
            ->get();

        $this->newLine();
        $bar = $this->output->createProgressBar($shipments->count());
        $bar->start();

        foreach ($shipments as $shipment) {
            try {
                $needsUpdate = false;
                $updates = [];

                // Handle origin_branch_id
                if (is_null($shipment->origin_branch_id)) {
                    $originBranchId = $this->resolveOriginBranch($shipment);
                    if ($originBranchId) {
                        $updates['origin_branch_id'] = $originBranchId;
                        if (is_null($shipment->current_branch_id)) {
                            $updates['current_branch_id'] = $originBranchId;
                        }
                        $needsUpdate = true;
                    }
                }

                // Handle destination_branch_id
                if (is_null($shipment->destination_branch_id)) {
                    $destBranchId = $this->resolveDestinationBranch($shipment, $branches);
                    if ($destBranchId) {
                        $updates['destination_branch_id'] = $destBranchId;
                        $needsUpdate = true;
                    }
                }

                if ($needsUpdate) {
                    if (!$isDryRun) {
                        $shipment->update($updates);
                    }
                    $updated++;
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->error("Error processing shipment {$shipment->id}: {$e->getMessage()}");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Summary
        $this->table(['Status', 'Count'], [
            ['Updated', $updated],
            ['Skipped', $skipped],
            ['Errors', $errors],
            ['Total', $shipments->count()],
        ]);

        if ($isDryRun) {
            $this->info('✅ DRY RUN COMPLETE - No changes were committed');
            $this->info('Run without --dry-run flag to apply changes');
        } else {
            $this->info('✅ Backfill complete!');
        }

        return 0;
    }

    /**
     * Resolve origin branch from pickup location or merchant
     */
    private function resolveOriginBranch(Shipment $shipment): ?int
    {
        // Try pickup location's branch
        if ($shipment->pickup_location_id) {
            $pickupBranch = DB::table('merchant_pickup_locations')
                ->where('id', $shipment->pickup_location_id)
                ->value('branch_id');

            if ($pickupBranch) {
                return (int) $pickupBranch;
            }
        }

        // Try merchant's primary location
        if ($shipment->merchant_id) {
            $merchantBranch = DB::table('merchant_pickup_locations')
                ->where('merchant_id', $shipment->merchant_id)
                ->where('is_primary', true)
                ->value('branch_id');

            if ($merchantBranch) {
                return (int) $merchantBranch;
            }
        }

        // Fallback: get first active branch
        $firstBranch = DB::table('branches')
            ->where('status', 'active')
            ->value('id');

        return $firstBranch ? (int) $firstBranch : null;
    }

    /**
     * Resolve destination branch from delivery address using nearest branch
     */
    private function resolveDestinationBranch(Shipment $shipment, $branches): ?int
    {
        // No coordinates, try city/area matching
        if (!$shipment->delivery_lat || !$shipment->delivery_lng) {
            return $this->matchBranchByLocation($shipment, $branches);
        }

        // Use Haversine formula to find nearest branch
        $nearest = null;
        $minDistance = PHP_FLOAT_MAX;

        foreach ($branches as $branch) {
            if (!$branch->latitude || !$branch->longitude) {
                continue;
            }

            $distance = $this->haversineDistance(
                $shipment->delivery_lat,
                $shipment->delivery_lng,
                $branch->latitude,
                $branch->longitude
            );

            if ($distance < $minDistance) {
                $minDistance = $distance;
                $nearest = $branch->id;
            }
        }

        if ($nearest) {
            return (int) $nearest;
        }

        // Fallback to city/area matching
        return $this->matchBranchByLocation($shipment, $branches);
    }

    /**
     * Match branch by city or area
     */
    private function matchBranchByLocation(Shipment $shipment, $branches): ?int
    {
        if ($shipment->delivery_city) {
            foreach ($branches as $branch) {
                if (stripos($branch->city ?? '', $shipment->delivery_city) !== false) {
                    return (int) $branch->id;
                }
            }
        }

        if ($shipment->delivery_area) {
            foreach ($branches as $branch) {
                if (stripos($branch->area ?? '', $shipment->delivery_area) !== false) {
                    return (int) $branch->id;
                }
            }
        }

        // Return first active branch
        return $branches->first()?->id ? (int) $branches->first()->id : null;
    }

    /**
     * Calculate distance between two coordinates using Haversine formula
     */
    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // in km

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * asin(sqrt($a));

        return $earthRadius * $c;
    }
}
