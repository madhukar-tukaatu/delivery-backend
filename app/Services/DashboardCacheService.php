<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Pickup\Models\PickupRequest;
use Modules\Shipment\Models\Shipment;

class DashboardCacheService
{
    public function adminDashboard(): array
    {
        return Cache::remember('dashboard:admin', now()->addSeconds(30), function () {
            return [
                'booked_shipments' => Shipment::where('status', 'booked')->count(),
                'pickup_assigned' => Shipment::where('status', 'pickup_assigned')->count(),
                'in_transit_shipments' => Shipment::whereIn('status', ['in_transit', 'dispatched'])->count(),
                'out_for_delivery' => Shipment::where('status', 'out_for_delivery')->count(),
                'delivered_today' => Shipment::whereDate('delivered_at', today())->count(),
                'pending_pickups' => PickupRequest::whereIn('status', ['pending', 'assigned'])->count(),
                'active_deliveries' => DeliveryAssignment::whereIn('status', ['assigned', 'out_for_delivery'])->count(),
            ];
        });
    }

    public function branchDashboard(int $branchId): array
    {
        return Cache::remember("dashboard:branch:{$branchId}", now()->addSeconds(30), function () use ($branchId) {
            return [
                'shipments_here' => Shipment::where('current_branch_id', $branchId)->count(),
                'pending_pickups' => PickupRequest::where('branch_id', $branchId)
                    ->whereIn('status', ['pending', 'assigned'])
                    ->count(),
                'active_deliveries' => DeliveryAssignment::where('branch_id', $branchId)
                    ->whereIn('status', ['assigned', 'out_for_delivery'])
                    ->count(),
            ];
        });
    }

    public function forgetAdminDashboard(): void
    {
        Cache::forget('dashboard:admin');
    }

    public function forgetBranchDashboard(?int $branchId): void
    {
        if ($branchId) {
            Cache::forget("dashboard:branch:{$branchId}");
        }
    }

    public function forgetForShipment(Shipment $shipment): void
    {
        $this->forgetAdminDashboard();
        $this->forgetBranchDashboard($shipment->current_branch_id);
        $this->forgetBranchDashboard($shipment->origin_branch_id);
        $this->forgetBranchDashboard($shipment->destination_branch_id);
    }
}
