<?php

namespace Modules\Access\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Delivery\Models\DeliveryRun;
use Modules\Delivery\Services\DeliveryRunService;
use Modules\Shipment\Http\Controllers\TransferController;

/**
 * Sidebar badge counts ("work waiting") keyed by menu path.
 *
 * Each badge is scoped the same way as the page it points to:
 *  - HQ (super_admin, main_admin, admin): all branches
 *  - branch staff: their branch (and sub-branches for deliveries)
 *  - riders (staff section): their own jobs
 * A badge is only computed when the user may open that page.
 */
class MenuBadgeService
{
    public function __construct(
        private readonly DeliveryRunService $runs,
    ) {}

    /**
     * @return array<string, list<array{key:string,count:int,label:string,color:string}>>
     */
    public function forUser(User $user, string $section): array
    {
        $badges = $section === 'staff'
            ? $this->staffBadges($user)
            : ($section === 'admin' ? $this->adminBadges($user) : []);

        // Drop empty badges so the client only renders real work.
        $out = [];
        foreach ($badges as $path => $list) {
            $list = array_values(array_filter($list, fn ($b) => (int) $b['count'] > 0));
            if ($list) {
                $out[$path] = $list;
            }
        }

        return $out;
    }

    /* ------------------------------ admin ------------------------------ */

    private function adminBadges(User $user): array
    {
        $hq = $this->isHq($user);
        $branchId = $hq ? 0 : (int) ($user->branch_id ?? 0);
        if (! $hq && $branchId <= 0) {
            return [];
        }

        $out = [];

        if ($this->allowed($user, 'deliveries.view')) {
            $out['/admin/deliveries'] = $this->safe('deliveries', fn () => $this->deliveryBadges($branchId));
        }
        if ($this->allowed($user, 'transfers.view')) {
            $out['/admin/transfers'] = $this->safe('transfers', fn () => $this->transferBadges($user, $branchId));
        }
        if ($this->allowed($user, 'pickups.view')) {
            $out['/admin/pickups'] = $this->safe('pickups', fn () => $this->pickupBadges($branchId));
        }

        return $out;
    }

    private function deliveryBadges(int $branchId): array
    {
        $ready = $this->runs->readyQuery($branchId)->count();

        $runs = DeliveryRun::query()
            ->when($branchId > 0, fn ($q) => $q->whereIn('branch_id', $this->runs->branchFamily($branchId)))
            ->whereIn('status', DeliveryRun::ACTIVE_STATUSES)
            ->selectRaw('status, COUNT(*) c')
            ->groupBy('status')
            ->pluck('c', 'status');
        $toClose = (int) ($runs[DeliveryRun::STATUS_COMPLETED] ?? 0);
        $active = (int) $runs->sum() - $toClose;

        $failed = DeliveryAssignment::query()
            ->when($branchId > 0, fn ($q) => $q->whereIn('branch_id', $this->runs->branchFamily($branchId)))
            ->where('status', 'failed')
            ->count();

        return [
            ['key' => 'ready', 'count' => $ready, 'label' => 'Ready to Assign', 'color' => '#0284c7'],
            ['key' => 'active', 'count' => $active, 'label' => 'Out for Delivery (Runs)', 'color' => '#2563eb'],
            ['key' => 'completed', 'count' => $toClose, 'label' => 'Completed Runs (to close)', 'color' => '#10b981'],
            ['key' => 'failed', 'count' => $failed, 'label' => 'Failed Deliveries', 'color' => '#ef4444'],
        ];
    }

    private function transferBadges(User $user, int $branchId): array
    {
        // Parcels ready to leave: reuse the Transfers board's own scope so the
        // badge always matches the Outbound tab.
        $outbound = 0;
        try {
            $sub = Request::create('/', 'GET', $branchId > 0 ? ['branch_id' => $branchId] : []);
            $sub->setUserResolver(fn () => $user);
            $payload = app(TransferController::class)->summary($sub)->getData(true);
            $outbound = (int) ($payload['data']['outbound'] ?? 0);
        } catch (\Throwable $e) {
            Log::info('menu_badges.transfer_outbound_failed', ['error' => $e->getMessage()]);
        }

        // TR containers on the way to this branch (dispatched, not yet received).
        $incoming = 0;
        if (Schema::hasTable('dispatch_manifests')) {
            $incoming = DB::table('dispatch_manifests')
                ->whereIn('status', ['dispatched', 'in_transit'])
                ->when($branchId > 0, fn ($q) => $q->where('to_branch_id', $branchId))
                ->count();
        }

        return [
            ['key' => 'incoming', 'count' => $incoming, 'label' => 'Incoming TR Containers in Transit', 'color' => '#0891b2'],
            ['key' => 'outbound', 'count' => $outbound, 'label' => 'Parcels Ready to Send', 'color' => '#f59e0b'],
        ];
    }

    private function pickupBadges(int $branchId): array
    {
        if (! Schema::hasTable('pickup_requests')) {
            return [];
        }
        $base = DB::table('pickup_requests')
            ->when($branchId > 0, fn ($q) => $q->where('pickup_branch_id', $branchId));

        $new = (clone $base)->whereIn('status', ['requested', 'pending'])->count();
        $incoming = (clone $base)->whereIn('status', ['collected', 'on_way_to_branch'])->count();

        $toClose = 0;
        if (Schema::hasTable('pickup_runs')) {
            $toClose = DB::table('pickup_runs')
                ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
                ->where('status', 'completed')
                ->count();
        }

        $failed = (clone $base)->where('status', 'failed')->count();

        return [
            ['key' => 'pending', 'count' => $new, 'label' => 'Pending Pickups', 'color' => '#f59e0b'],
            ['key' => 'incoming', 'count' => $incoming, 'label' => 'Pickups in Transit to Branch', 'color' => '#0891b2'],
            ['key' => 'completed', 'count' => $toClose, 'label' => 'Completed Pickup Runs (to close)', 'color' => '#10b981'],
            ['key' => 'failed', 'count' => $failed, 'label' => 'Failed Pickups', 'color' => '#ef4444'],
        ];
    }

    /* ------------------------------ staff ------------------------------ */

    private function staffBadges(User $user): array
    {
        $out = [];

        $out['/staff/deliveries'] = $this->safe('staff_deliveries', function () use ($user) {
            $active = DB::table('delivery_assignments')
                ->where('rider_id', $user->id)
                ->whereIn('status', ['assigned', 'accepted', 'out_for_delivery'])
                ->count();

            return [['key' => 'active', 'count' => $active, 'label' => 'deliveries to do', 'color' => '#1677ff']];
        });

        if (Schema::hasTable('pickup_requests')) {
            $out['/staff/pickups'] = $this->safe('staff_pickups', function () use ($user) {
                $active = DB::table('pickup_requests')
                    ->where('assigned_to', $user->id)
                    ->whereIn('status', ['assigned', 'accepted', 'started', 'arrived', 'collected', 'on_way_to_branch'])
                    ->count();

                return [['key' => 'active', 'count' => $active, 'label' => 'pickups to do', 'color' => '#fa541c']];
            });
        }

        return $out;
    }

    /* ----------------------------- helpers ----------------------------- */

    private function isHq(User $user): bool
    {
        if (
            $user->isSuperAdmin()
            || (method_exists($user, 'isHqUser') && $user->isHqUser())
            || in_array((string) $user->role, ['main_admin', 'admin', 'hq_admin', 'hq_manager', 'hq_operations_staff', 'hq_accounts_staff'], true)
        ) {
            return true;
        }
        try {
            return $user->hasAnyRole(['main_admin', 'admin', 'hq_admin', 'hq_manager', 'hq_operations_staff', 'hq_accounts_staff']);
        } catch (\Throwable) {
            return false;
        }
    }

    private function allowed(User $user, string $permission): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        try {
            return $user->can($permission);
        } catch (\Throwable) {
            return false;
        }
    }

    /** One failing counter must never break the sidebar. */
    private function safe(string $name, callable $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('menu_badges.failed', ['badge' => $name, 'error' => $e->getMessage()]);

            return [];
        }
    }
}
