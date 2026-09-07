<?php

namespace App\Support;

class RoutePermissionMapper
{
    public static function fromRouteName(?string $routeName): ?string
    {
        if (!$routeName) {
            return null;
        }

        $parts = explode('.', $routeName);

        if (count($parts) < 3) {
            return null;
        }

        $section = $parts[0];
        
        if (!in_array($section, ['admin', 'merchant', 'staff'], true)) {
            return null;
        }

        // Handle nested resources like staff.pickups.shipments.collect
        // Take the LAST part as action, second-to-last as module
        $action = end($parts);
        $module = $parts[1]; // Always use second part (pickups, shipments, etc)
        
        // If there are more than 3 parts and the second part is a common nested module,
        // use it as the module. Examples:
        // - staff.pickups.shipments.collect -> pickups.collect (not pickups.shipments)
        // - admin.shipments.index -> shipments.view
        // - staff.orders.items.store -> orders.create (not orders.items)

        $module = self::normalize($module);
        $action = self::mapAction($action);

        return "{$module}.{$action}";
    }

    private static function normalize(string $value): string
    {
        // Convert camelCase (e.g. "startTransit") to snake_case
        // ("start_transit") and dashes to underscores, so route names
        // written in any casing map to a single canonical permission key.
        $value = preg_replace('/([a-z])([A-Z])/', '$1_$2', $value) ?? $value;

        return strtolower(str_replace('-', '_', $value));
    }

    private static function mapAction(string $action): string
    {
        $action = self::normalize($action);

        return match ($action) {
            'index',
            'show',
            'list',
            'summary',
            'permissions' => 'view',

            'create',
            'store' => 'create',

            'edit',
            'update' => 'update',

            'destroy',
            'delete' => 'delete',

            'toggle',
            'activate',
            'deactivate',
            'change_status',
            'update_status',
            'status',
            'picked_up',
            'out_for_delivery',
            'delivered',
            'failed',
            'dispatch_next_step',
            'receive_current_step',
            'receive_origin_sub_branch' => 'status',

            'approve' => 'approve',
            'reject' => 'reject',

            // Rider cancels a pickup (missing shipment, cutoff passed, service
            // not fulfillable) or a pickup is failed. Both map to the existing
            // "failed" permission.
            'cancel',
            'fail' => 'failed',
            'assign' => 'assign',
            'assign_branch' => 'assign_branch',
            'request_more_info' => 'request_more_info',
            'retry' => 'retry',
            'test' => 'test',

            // Resend a pickup lifecycle callback. Managed alongside assign.
            'resend_callback' => 'assign',
            'export' => 'export',
            'calculate' => 'calculate',
            'collect' => 'collect',

            // Rider closes the collection phase and starts transit to the
            // origin branch. Reuses the existing "complete" permission since
            // it is the rider's final collection action.
            'start_transit',
            'starttransit',
            'complete' => 'complete',

            'deposit' => 'deposit',
            'confirm' => 'confirm',
            'mark_paid' => 'mark_paid',
            'pay' => 'pay',
            'submit' => 'submit',
            'manage' => 'manage',

            default => $action,
        };
    }
}