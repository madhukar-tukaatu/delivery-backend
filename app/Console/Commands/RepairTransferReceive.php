<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Dispatch\Models\DispatchManifest;
use Modules\Dispatch\Services\TransferContainerService;

/**
 * Repair a TR that was checked in wrongly. Same logic as the admin
 * "Repair receive" dialog (TransferContainerService::repairReceive).
 *
 *   php artisan transfers:repair-receive TR-000003 --clear-seal --found-all            (preview only)
 *   php artisan transfers:repair-receive TR-000003 --clear-seal --found-all --apply    (write)
 *   php artisan transfers:repair-receive 5 --id --found-all --apply                    (by TR id)
 *
 * The TR can be given as TR-000003, 3, 000003, tr-3, TR-KATHM-000003 or a legacy MF- number.
 */
final class RepairTransferReceive extends Command
{
    protected $signature = 'transfers:repair-receive {number : TR number (TR-000003, 3, TR-KATHM-3) or the TR id with --id}
        {--id : Treat the argument as the TR database id}
        {--clear-seal : Remove the arrival seal result}
        {--found-all : Receive + sort every parcel still marked missing}
        {--apply : Write the changes (default: preview only)}';

    protected $description = 'Repair a wrongly checked-in TR: clear a seal flag and/or receive its missing parcels';

    public function handle(TransferContainerService $svc): int
    {
        if (! Schema::hasTable('dispatch_manifests')) {
            $this->error('Table dispatch_manifests is missing. Run: php artisan migrate --force');

            return self::FAILURE;
        }
        $arg = trim((string) $this->argument('number'));
        $tr = $this->option('id') ? DispatchManifest::query()->find((int) $arg) : $svc->findByNumber($arg);
        if (! $tr) {
            $this->error("No TR found for \"{$arg}\". Try the number on the bag label (TR-000003 or 3), or the id with --id.");

            return self::FAILURE;
        }
        if (! $this->option('clear-seal') && ! $this->option('found-all')) {
            $this->error('Nothing to do: add --clear-seal and/or --found-all.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        try {
            $res = $svc->repairReceive($tr, (bool) $this->option('clear-seal'), (bool) $this->option('found-all'), ! $apply, null, 'console');
        } catch (\Throwable $e) {
            $this->error('Repair failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $b = $res['before'];
        $this->info(sprintf('%s (id %d): status=%s seal=%s entered=%s remark=%s items=%s',
            $tr->display_number, $tr->id, $b['status'], $b['seal_status'] ?? '-', $b['seal_checked_value'] ?? '-', $b['seal_remark'] ?? '-', json_encode($b['items'])));
        foreach ($res['changes'] as $c) {
            $this->line(($c['action'] ? '  * ' : '  - ').$c['label']);
        }
        foreach ($res['errors'] as $e) {
            $this->error('  ! '.$e);
        }
        if ($res['applied']) {
            $a = $res['after'];
            $this->info(sprintf('Applied. Now: status=%s seal=%s items=%s', $a['status'], $a['seal_status'] ?? '-', json_encode($a['items'])));
        } elseif (! $apply) {
            $this->comment('Preview only. Add --apply to write.');
        } else {
            $this->comment('Nothing to change.');
        }

        return $res['errors'] && ! $res['applied'] ? self::FAILURE : self::SUCCESS;
    }
}
