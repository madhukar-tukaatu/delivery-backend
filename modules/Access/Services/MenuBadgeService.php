<?php

namespace Modules\Access\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

        return [
            ['key' => 'ready', 'count' => $ready, 'label' => 'parcels ready for a delivery run', 'color' => '#1677ff'],
            ['key' => 'runs', 'count' => $active, 'label' => 'delivery runs open or on the road', 'color' => '#722ed1'],
            ['key' => 'close', 'count' => $toClose, 'label' => 'delivery runs back and waiting to close', 'color' => '#f5222d'],
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
            ['key' => 'incoming', 'count' => $incoming, 'label' => 'TRs in transit to receive', 'color' => '#13c2c2'],
            ['key' => 'outbound', 'count' => $outbound, 'label' => 'parcels ready to send', 'color' => '#fa8c16'],
        ];
    }

    private function pickupBadges(int $branchId): array
    {
        if (! Schema::hasTable('pickup_requests')) {
            return [];
        }
        $base = DB::table('pickup_requests')
            ->when($branchId > 0, fn ($q) => $q->where('pickup_branch_id', $branchId));

        $new = (clone $base)->where('status', 'requested')->count();
        $incoming = (clone $base)->whereIn('status', ['collected', 'on_way_to_branch'])->count();

        return [
            ['key' => 'requested', 'count' => $new, 'label' => 'pickup requests waiting for a rider', 'color' => '#fa541c'],
            ['key' => 'incoming', 'count' => $incoming, 'label' => 'pickups collected, on the way to the branch', 'color' => '#13c2c2'],
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
        if ($user->isSuperAdmin() || in_array((string) $user->role, ['main_admin', 'admin'], true)) {
            return true;
        }
        try {
            return $user->hasAnyRole(['main_admin', 'admin']);
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
