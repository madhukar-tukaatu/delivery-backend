<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CourierStatus;
use Illuminate\Console\Command;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\TransferRouteProgressService;

/**
 * Recalculate and persist next_hop_branch_id / hop_meta for outbound transfer parcels.
 * Fixes rows sorted before a multi-hop route existed (next hop stuck on final destination).
 *
 * Usage:
 *   php artisan transfers:reconcile-next-hops
 *   php artisan transfers:reconcile-next-hops --tracking=TEX-20260928-507677
 *   php artisan transfers:reconcile-next-hops --dry-run
 */
final class ReconcileTransferNextHops extends Command
{
    protected $signature = 'transfers:reconcile-next-hops
                            {--tracking= : Limit to a tracking number}
                            {--tr= : Limit to a specific TR number, e.g. TR-KATHM-000001}
                            {--branch= : Limit to current_branch_id}
                            {--force : Force re-evaluating routes even if transfer_route_id is already assigned}
                            {--dry-run : Show changes without persisting}';

    protected $description = 'Recalculate shipment next_hop from assigned/matched transfer route path';

    public function handle(TransferRouteProgressService $progress): int
    {
        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $trOption = $this->option('tr');
        $tracking = $this->option('tracking');
        $branch = $this->option('branch');

        $fixed = 0;
        $checked = 0;
        $fixedTrs = 0;

        // If a specific TR is specified, reconcile that TR and its manifest items directly
        if ($trOption) {
            $manifests = \Modules\Dispatch\Models\DispatchManifest::query()
                ->where(function ($q) use ($trOption) {
                    $q->where('transfer_number', $trOption)
                        ->orWhere('manifest_number', $trOption);
                })
                ->with(['items.shipment'])
                ->get();

            if ($manifests->isEmpty()) {
                $this->error("No transfer manifest found matching TR: {$trOption}");
                return self::FAILURE;
            }

            foreach ($manifests as $manifest) {
                $fromBranchId = (int) $manifest->from_branch_id;
                $this->line("Processing TR {$manifest->manifest_number} (From Branch #{$fromBranchId}, Current To: #{$manifest->to_branch_id}, Status: {$manifest->status})");

                $newHop = null;
                $matchedRoute = null;

                foreach ($manifest->items as $item) {
                    $shipment = $item->shipment;
                    if (!$shipment) {
                        continue;
                    }
                    $checked++;
                    $beforeHop = (int) ($shipment->next_hop_branch_id ?? 0);
                    // Force ignore previously saved route to resolve to the active multi-hop route
                    $p = $progress->resolveForShipment($shipment, $fromBranchId, true);
                    $afterHop = (int) ($p['next_hop_branch_id'] ?? 0);

                    if (!$p['has_route'] || $afterHop <= 0) {
                        $this->warn("  {$shipment->tracking_number}: No valid multi-hop route found from origin #{$fromBranchId} to dest #{$shipment->destination_branch_id}");
                        continue;
                    }

                    if ($newHop === null) {
                        $newHop = $afterHop;
                        $matchedRoute = $p;
                    }

                    $this->line(sprintf(
                        '  %s  next %s → %s  route=%s (%s)%s',
                        $shipment->tracking_number,
                        $beforeHop ?: 'null',
                        $afterHop,
                        $p['route_code'] ?? ($p['transfer_route_id'] ?? '-'),
                        $p['path_text'] ?? '-',
                        $dry ? '  [dry-run]' : ''
                    ));

                    if (!$dry) {
                        $shipment->update([
                            'next_hop_branch_id' => $afterHop,
                            'transfer_route_id' => $p['transfer_route_id'] ?? $shipment->transfer_route_id,
                            'path_text' => $p['path_text'] ?? $shipment->path_text,
                            'route_code' => $p['route_code'] ?? $shipment->route_code,
                            'route_name' => $p['route_name'] ?? $shipment->route_name,
                        ]);
                    }
                    $fixed++;
                }

                if ($newHop > 0) {
                    $trChanged = $newHop !== (int) $manifest->to_branch_id;
                    $this->line(sprintf(
                        'TR %s (%s)  dest %s → %s%s',
                        $manifest->manifest_number ?? $manifest->transfer_number,
                        $manifest->status,
                        $manifest->to_branch_id,
                        $newHop,
                        $dry ? '  [dry-run]' : ''
                    ));

                    if (!$dry) {
                        $manifest->update([
                            'to_branch_id' => $newHop,
                            'transfer_route_id' => $matchedRoute['transfer_route_id'] ?? $manifest->transfer_route_id,
                        ]);

                        if (\Illuminate\Support\Facades\Schema::hasTable('shipment_route_steps')) {
                            \Illuminate\Support\Facades\DB::table('shipment_route_steps')
                                ->where('dispatch_manifest_id', $manifest->id)
                                ->update(['to_branch_id' => $newHop]);
                        }

                        app(\Modules\Dispatch\Services\ManifestHopService::class)->refreshCounts((int) $manifest->id);
                    }
                    $fixedTrs++;
                }
            }

            $this->info(($dry ? 'Would fix' : 'Fixed') . " {$fixed} / {$checked} shipments, {$fixedTrs} TRs");
            return self::SUCCESS;
        }

        $query = Shipment::query()
            ->whereIn('status', [
                CourierStatus::SORTED_FOR_TRANSFER,
                CourierStatus::RECEIVED_AT_ORIGIN_BRANCH,
                CourierStatus::RECEIVED_AT_TRANSIT_HUB,
                CourierStatus::IN_TRANSIT,
            ]);

        if ($tracking) {
            $query->where('tracking_number', $tracking);
        }
        if ($branch) {
            $query->where('current_branch_id', (int) $branch);
        }

        $query->orderBy('id')->chunkById(100, function ($shipments) use ($progress, $dry, $force, &$fixed, &$checked) {
            foreach ($shipments as $shipment) {
                $checked++;
                $at = (int) ($shipment->current_branch_id ?? 0) ?: null;
                $before = (int) ($shipment->next_hop_branch_id ?? 0);
                $p = $progress->resolveForShipment($shipment, $at, $force);
                $after = (int) ($p['next_hop_branch_id'] ?? 0);
                $routeId = (int) ($p['transfer_route_id'] ?? 0);

                if (!$p['has_route'] || $after <= 0) {
                    continue;
                }

                $routeChanged = $routeId > 0 && $routeId !== (int) ($shipment->transfer_route_id ?? 0);
                $hopChanged = $before !== $after;
                if (!$hopChanged && !$routeChanged) {
                    continue;
                }

                $this->line(sprintf(
                    '%s  next %s → %s  route=%s  path=%s%s',
                    $shipment->tracking_number,
                    $before ?: 'null',
                    $after,
                    $p['route_code'] ?? $routeId,
                    $p['path_text'] ?? '-',
                    $dry ? '  [dry-run]' : ''
                ));

                if (!$dry) {
                    $progress->applyProgressToShipment($shipment, $at, $force);
                }
                $fixed++;
            }
        });

        // Also reconcile in-flight TR runs (dispatch manifests)
        $manifestQuery = \Modules\Dispatch\Models\DispatchManifest::query()
            ->whereIn('status', ['open', 'dispatched', 'in_transit'])
            ->with(['items.shipment']);

        $manifests = $manifestQuery->get();

        foreach ($manifests as $manifest) {
            $firstShipment = $manifest->items->first()?->shipment;
            if (!$firstShipment) {
                continue;
            }
            $p = $progress->resolveForShipment($firstShipment, (int) $manifest->from_branch_id, $force);
            $computedHop = (int) ($p['next_hop_branch_id'] ?? 0);

            if ($computedHop > 0 && $computedHop !== (int) $manifest->to_branch_id) {
                $this->line(sprintf(
                    'TR %s (%s)  dest %s → %s%s',
                    $manifest->manifest_number ?? $manifest->transfer_number,
                    $manifest->status,
                    $manifest->to_branch_id,
                    $computedHop,
                    $dry ? '  [dry-run]' : ''
                ));

                if (!$dry) {
                    $manifest->update([
                        'to_branch_id' => $computedHop,
                        'transfer_route_id' => $p['transfer_route_id'] ?? $manifest->transfer_route_id,
                    ]);

                    foreach ($manifest->items as $item) {
                        if ($item->shipment) {
                            $item->shipment->update([
                                'next_hop_branch_id' => $computedHop,
                                'transfer_route_id' => $p['transfer_route_id'] ?? $item->shipment->transfer_route_id,
                                'path_text' => $p['path_text'] ?? $item->shipment->path_text,
                                'route_code' => $p['route_code'] ?? $item->shipment->route_code,
                                'route_name' => $p['route_name'] ?? $item->shipment->route_name,
                            ]);
                        }
                    }

                    if (\Illuminate\Support\Facades\Schema::hasTable('shipment_route_steps')) {
                        \Illuminate\Support\Facades\DB::table('shipment_route_steps')
                            ->where('dispatch_manifest_id', $manifest->id)
                            ->update(['to_branch_id' => $computedHop]);
                    }

                    app(\Modules\Dispatch\Services\ManifestHopService::class)->refreshCounts((int) $manifest->id);
                }
                $fixedTrs++;
            }
        }

        $this->info(($dry ? 'Would fix' : 'Fixed') . " {$fixed} / {$checked} shipments, {$fixedTrs} TRs");

        return self::SUCCESS;
    }
}
