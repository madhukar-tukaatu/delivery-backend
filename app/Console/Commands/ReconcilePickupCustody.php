<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Services\PickupRequestService;

/**
 * Terminal fallback for the admin "Fix pickup status" button on a pickup.
 * Preview by default; nothing changes without --apply.
 *
 *   php artisan pickups:reconcile PR-000025
 *   php artisan pickups:reconcile PR-000025 --apply
 */
final class ReconcilePickupCustody extends Command
{
    protected $signature = 'pickups:reconcile
                            {pickup : Pickup number (PR-000025), number (25) or id}
                            {--apply : Write the changes (default is preview only)}
                            {--receive : Also receive parcels whose wrong "in transit" status is corrected (only if they are physically at the branch)}
                            {--user= : User id recorded as the actor (default: first super admin)}';

    protected $description = 'Line a pickup up with where its parcels really are (parcels sent on a TR before the branch verified them, wrong in-transit status) and complete it';

    public function handle(PickupRequestService $service): int
    {
        $pickup = $this->findPickup((string) $this->argument('pickup'));
        if (! $pickup) {
            $this->error('Pickup not found: '.$this->argument('pickup'));

            return self::FAILURE;
        }

        $actorId = $this->option('user')
            ? (int) $this->option('user')
            : (int) User::query()->where('role', 'super_admin')->orderBy('id')->value('id');

        $apply = (bool) $this->option('apply');
        $report = $service->reconcile($pickup, $actorId ?: null, $apply, (bool) $this->option('receive'), 'console');

        $this->info(sprintf('%s  status: %s -> %s  (%s)', $report['request_number'], $report['status_before'], $report['status_after'], $apply ? 'APPLIED' : 'PREVIEW'));
        $this->table(
            ['Tracking', 'Shipment status', 'Pickup line', 'Where it is', 'Action'],
            array_map(fn ($r) => [
                $r['tracking_number'],
                $r['shipment_status'],
                $r['item_status'],
                $r['label'],
                $r['action_label'] ?? '-',
            ], $report['items'])
        );
        foreach ($report['errors'] as $e) {
            $this->warn('Shipment '.$e['shipment_id'].': '.$e['error']);
        }
        if (! $apply && $report['changes'] > 0) {
            $this->comment('Preview only. Run again with --apply to make these '.$report['changes'].' change(s).');
        } elseif ($report['changes'] === 0) {
            $this->comment('Nothing to fix.');
        }

        return self::SUCCESS;
    }

    private function findPickup(string $ref): ?PickupRequest
    {
        $ref = strtoupper(trim($ref));
        if ($ref === '') {
            return null;
        }
        $found = PickupRequest::query()->where('request_number', $ref)->first();
        if ($found) {
            return $found;
        }
        if (preg_match('/(\d+)$/', $ref, $m)) {
            $n = (int) $m[1];
            $found = PickupRequest::query()->where('request_number', 'PR-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT))->first();
            if ($found || ! ctype_digit($ref)) {
                return $found;
            }

            return PickupRequest::query()->find($n);
        }

        return null;
    }
}