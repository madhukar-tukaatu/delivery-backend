<?php

use Illuminate\Support\Facades\Broadcast;
use Modules\Shipment\Models\Shipment;

Broadcast::channel('admin.dashboard', function ($user) {
    return $user->hasRole('super_admin') || $user->hasRole('main_admin');
});

Broadcast::channel('merchant.{merchantId}', function ($user, $merchantId) {
    return $user->hasRole('super_admin')
        || $user->hasRole('main_admin')
        || (
            $user->hasRole('merchant')
            && $user->merchant
            && (int) $user->merchant->id === (int) $merchantId
        );
});

Broadcast::channel('branch.{branchId}', function ($user, $branchId) {
    return $user->hasRole('super_admin')
        || $user->hasRole('main_admin')
        || (int) $user->branch_id === (int) $branchId;
});

Broadcast::channel('sub_branch.{subBranchId}', function ($user, $subBranchId) {
    return $user->hasRole('super_admin')
        || $user->hasRole('main_admin')
        || (int) $user->branch_id === (int) $subBranchId;
});

Broadcast::channel('staff.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId
        || $user->hasRole('super_admin')
        || $user->hasRole('main_admin');
});

Broadcast::channel('shipments.{shipmentId}', function ($user, $shipmentId) {
    $shipment = Shipment::find($shipmentId);

    if (!$shipment) {
        return false;
    }

    if ($user->hasRole('super_admin') || $user->hasRole('main_admin')) {
        return true;
    }

    if (
        $user->hasRole('merchant')
        && $user->merchant
        && (int) $user->merchant->id === (int) $shipment->merchant_id
    ) {
        return true;
    }

    return in_array((int) $user->branch_id, array_filter([
        (int) $shipment->origin_branch_id,
        (int) $shipment->origin_sub_branch_id,
        (int) $shipment->destination_branch_id,
        (int) $shipment->destination_sub_branch_id,
        (int) $shipment->current_branch_id,
        (int) $shipment->current_sub_branch_id,
    ]), true);
});

Broadcast::channel('rider.{riderId}.location', function ($user, $riderId) {
    return (int) $user->id === (int) $riderId
        || $user->hasRole('super_admin')
        || $user->hasRole('main_admin')
        || $user->hasRole('branch_manager')
        || $user->hasRole('sub_branch_manager');
});
