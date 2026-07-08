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
        $module = $parts[1];
        $action = $parts[2];

        if (!in_array($section, ['admin', 'merchant', 'staff'], true)) {
            return null;
        }

        $module = self::normalize($module);
        $action = self::mapAction($action);

        return "{$module}.{$action}";
    }

    private static function normalize(string $value): string
    {
        return str_replace('-', '_', $value);
    }

    private static function mapAction(string $action): string
    {
        $action = self::normalize($action);

        return match ($action) {
            'index',
            'show',
            'list',
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
            'assign' => 'assign',
            'assign_branch' => 'assign_branch',
            'request_more_info' => 'request_more_info',
            'retry' => 'retry',
            'test' => 'test',
            'export' => 'export',
            'calculate' => 'calculate',
            'collect' => 'collect',
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