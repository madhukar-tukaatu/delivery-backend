<?php

use Illuminate\Support\Facades\Broadcast;
use Modules\Shipment\Models\Shipment;

if (!function_exists('isDeliveryAdminUser')) {
    function isDeliveryAdminUser($user): bool {
        if (!$user) {
            return false;
        }

        $adminRoles = [
            'super_admin',
            'super-admin',
            'main_admin',
            'admin',
            'operations_manager',
            'operations-manager',
            'branch_manager',
            'branch-manager',
            'merchant_manager',
            'merchant-manager',
        ];

        if (isset($user->role) && in_array(strtolower(trim((string)$user->role)), $adminRoles, true)) {
            return true;
        }

        if (method_exists($user, 'hasAnyRole')) {
            try {
                if ($user->hasAnyRole($adminRoles)) {
                    return true;
                }
            } catch (\Throwable $e) {}
        }

        if (method_exists($user, 'hasRole')) {
            foreach ($adminRoles as $role) {
                try {
                    if ($user->hasRole($role)) {
                        return true;
                    }
                } catch (\Throwable $e) {}
            }
        }

        $candidates = [
            data_get($user, 'role.slug'),
            data_get($user, 'role.name'),
            data_get($user, 'role_name'),
            data_get($user, 'user_type'),
        ];

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate) && in_array(strtolower(trim((string) $candidate)), $adminRoles, true)) {
                return true;
            }
        }

        return false;
    }
}

Broadcast::channel('admin.dashboard', function ($user) {
    return isDeliveryAdminUser($user);
});

Broadcast::channel('admin.shipments', function ($user) {
    return isDeliveryAdminUser($user);
});

Broadcast::channel('admin.pickups', function ($user) {
    return isDeliveryAdminUser($user);
});

Broadcast::channel('admin.deliveries', function ($user) {
    return isDeliveryAdminUser($user);
});

Broadcast::channel('merchant.{merchantId}', function ($user, $merchantId) {
    return isDeliveryAdminUser($user)
        || (
            $user->hasRole('merchant')
            && $user->merchant
            && (int) $user->merchant->id === (int) $merchantId
        );
});

Broadcast::channel(
    'admin.merchant-applications',
    function ($user): bool {
        /*
         * Spatie permissions/roles.
         */
        if (method_exists(
            $user,
            'hasAnyRole'
        )) {
            return $user->hasAnyRole([
                'super_admin',
                'admin',
            ]);
        }

        if (method_exists(
            $user,
            'hasRole'
        )) {
            return
                $user->hasRole('super_admin') ||
                $user->hasRole('admin');
        }

        /*
         * Fallback for custom role models/columns.
         */
        $candidates = [
            data_get($user, 'role.slug'),
            data_get($user, 'role.name'),
            data_get($user, 'role_name'),
            data_get($user, 'user_type'),
        ];

        foreach ($candidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }

            $role = strtolower(
                trim((string) $candidate)
            );

            if (
                in_array(
                    $role,
                    [
                        'super_admin',
                        'super-admin',
                        'admin',
                    ],
                    true
                )
            ) {
                return true;
            }
        }

        return false;
    }
);

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
        || isDeliveryAdminUser($user);
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


Broadcast::channel(
    'admin.operations',
    function (User $user): bool {
        return $user->hasAnyRole([
            'super-admin',
            'admin',
            'operations-manager',
        ]);
    }
);

Broadcast::channel(
    'admin.merchants',
    function (User $user): bool {
        return $user->hasAnyRole([
            'super-admin',
            'admin',
            'merchant-manager',
        ]);
    }
);

Broadcast::channel(
    'admin.branches',
    function (User $user): bool {
        return $user->hasAnyRole([
            'super-admin',
            'admin',
            'branch-manager',
        ]);
    }
);

Broadcast::channel(
    'merchant.{merchantId}',
    function (User $user, int $merchantId): bool {
        return (int) optional($user->merchant)->id === $merchantId;
    }
);

Broadcast::channel(
    'user.{userId}',
    function (User $user, int $userId): bool {
        return (int) $user->id === $userId;
    }
);

Broadcast::channel(
    'presence.admins',
    function (User $user): array|bool {
        if (!$user->hasAnyRole([
            'super-admin',
            'admin',
            'operations-manager',
            'branch-manager',
            'merchant-manager',
        ])) {
            return false;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first(),
        ];
    }
);
