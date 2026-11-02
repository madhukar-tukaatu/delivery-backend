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
                            {--dry-run : Show changes without persisting}';

    protected $description = 'Recalculate shipment next_hop from assigned/matched transfer route path';

    public function handle(TransferRouteProgressService $progress): int
    {
        $query = Shipment::query()
            ->whereIn('status', [
                CourierStatus::SORTED_FOR_TRANSFER,
                CourierStatus::RECEIVED_AT_ORIGIN_BRANCH,
                CourierStatus::RECEIVED_AT_TRANSIT_HUB,
                CourierStatus::IN_TRANSIT,
            ]);

        if ($tracking = $this->option('tracking')) {
            $query->where('tracking_number', $tracking);
        }
        if ($branch = $this->option('branch')) {
            $query->where('current_branch_id', (int) $branch);
        }

        $dry = (bool) $this->option('dry-run');
        $fixed = 0;
        $checked = 0;

        $query->orderBy('id')->chunkById(100, function ($shipments) use ($progress, $dry, &$fixed, &$checked) {
            foreach ($shipments as $shipment) {
                $checked++;
                $at = (int) ($shipment->current_branch_id ?? 0) ?: null;
                $before = (int) ($shipment->next_hop_branch_id ?? 0);
                $p = $progress->resolveForShipment($shipment, $at);
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
                    $progress->applyProgressToShipment($shipment, $at);
                }
                $fixed++;
            }
        });

        // Also reconcile in-flight TR runs (dispatch manifests)
        $manifestQuery = \Modules\Dispatch\Models\DispatchManifest::query()
            ->whereIn('status', ['open', 'dispatched', 'in_transit'])
            ->with(['items.shipment']);

        if ($trOption = $this->option('tr')) {
            $manifestQuery->where(function ($q) use ($trOption) {
                $q->where('transfer_number', $trOption)
                    ->orWhere('manifest_number', $trOption);
            });
        }
        $manifests = $manifestQuery->get();

        $fixedTrs = 0;
        foreach ($manifests as $manifest) {
            $firstShipment = $manifest->items->first()?->shipment;
            if (!$firstShipment) {
                continue;
            }
            $p = $progress->resolveForShipment($firstShipment, (int) $manifest->from_branch_id);
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
