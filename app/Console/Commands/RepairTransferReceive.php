<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CourierStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Dispatch\Models\DispatchManifestItem;
use Modules\Dispatch\Services\TransferContainerService;

/**
 * Repair a TR that was checked in wrongly (e.g. a seal flag that nobody set on
 * purpose, or parcels flagged missing because nothing was ticked).
 *
 *   php artisan transfers:repair-receive TR-000003            (dry run: shows what would change)
 *   php artisan transfers:repair-receive TR-000003 --clear-seal --found-all --apply
 *
 * --clear-seal  removes the arrival seal result (status, entered value, remark, checked by / at)
 * --found-all   marks every "missing" parcel on the TR as found: received at the TR's
 *               receiving branch and auto-sorted (last mile -> sorted for delivery,
 *               onward -> sorted for transfer), same as the "Found" button
 * --apply       actually write; without it nothing changes
 */
final class RepairTransferReceive extends Command
{
    protected $signature = 'transfers:repair-receive {number : TR number, e.g. TR-000003 or 3}
        {--clear-seal : Remove the arrival seal result}
        {--found-all : Receive + sort every parcel flagged missing}
        {--apply : Write the changes (default is a dry run)}';

    protected $description = 'Repair a wrongly checked-in TR: clear a seal flag and/or receive its missing parcels';

    public function handle(TransferContainerService $svc): int
    {
        $tr = $svc->findByNumber((string) $this->argument('number'));
        if (! $tr) {
            $this->error('TR not found.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $this->info(sprintf('%s  status=%s  from=%d to=%d  seal_number=%s  seal_status=%s  entered=%s  remark=%s',
            $tr->display_number, $tr->status, $tr->from_branch_id, $tr->to_branch_id,
            $tr->seal_number ?? '-', $tr->seal_status ?? '-', $tr->seal_checked_value ?? '-', $tr->seal_remark ?? '-'));

        $items = DispatchManifestItem::query()->where('dispatch_manifest_id', $tr->id)->with('shipment')->get();
        foreach ($items as $item) {
            $s = $item->shipment;
            $this->line(sprintf('  item %d  %s  line=%s  parcel=%s  at_branch=%s  dest=%s',
                $item->id, $s?->tracking_number ?? '#'.$item->shipment_id, $item->status,
                $s?->status ?? '-', $s?->current_branch_id ?? '-', $s?->destination_branch_id ?? '-'));
        }

        if ($this->option('clear-seal')) {
            $cols = array_intersect(['seal_status', 'seal_checked_value', 'seal_checked_by', 'seal_checked_at', 'seal_remark'], Schema::getColumnListing('dispatch_manifests'));
            $this->warn(($apply ? 'Clearing' : 'Would clear').' seal result: '.implode(', ', $cols));
            if ($apply && $cols) {
                DB::table('dispatch_manifests')->where('id', $tr->id)->update(array_fill_keys($cols, null));
            }
        }

        if ($this->option('found-all')) {
            $missing = $items->where('status', 'missing');
            $this->warn(($apply ? 'Receiving' : 'Would receive').' '.$missing->count().' missing parcel(s) as found at branch '.$tr->to_branch_id);
            if ($apply) {
                foreach ($missing as $item) {
                    $svc->resolveItem($tr->fresh(), $item, 'found', 'Repair: parcel was on the TR (receive bug)', null, (int) $tr->to_branch_id);
                }
            }
        }

        if ($apply) {
            foreach (DispatchManifestItem::query()->where('dispatch_manifest_id', $tr->id)->with('shipment')->get() as $item) {
                $s = $item->shipment;
                $this->line(sprintf('  after: %s  line=%s  parcel=%s', $s?->tracking_number ?? '#'.$item->shipment_id, $item->status, $s?->status ?? '-'));
            }
        } else {
            $this->comment('Dry run. Add --apply to write. Parcels that should be sorted: last mile -> '.CourierStatus::SORTED_FOR_DELIVERY.', onward -> '.CourierStatus::SORTED_FOR_TRANSFER.'.');
        }

        return self::SUCCESS;
    }
}
