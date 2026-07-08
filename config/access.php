<?php

return [

    'permission_groups' => [

        'dashboard' => [
            'label' => 'Dashboard',
            'permissions' => [
                'dashboard.view',
            ],
        ],

        'users' => [
            'label' => 'Users',
            'permissions' => [
                'users.view',
                'users.create',
                'users.update',
                'users.delete',
                'users.status',
            ],
        ],

        'roles' => [
            'label' => 'Roles & Permissions',
            'permissions' => [
                'roles.view',
                'roles.create',
                'roles.update',
                'roles.delete',
            ],
        ],

        'menus' => [
            'label' => 'Menus',
            'permissions' => [
                'menus.view',
                'menus.create',
                'menus.update',
                'menus.delete',
            ],
        ],

        'settings' => [
            'label' => 'Settings',
            'permissions' => [
                'settings.view',
                'settings.update',
                'settings.manage',
            ],
        ],

        'branches' => [
            'label' => 'Branches',
            'permissions' => [
                'branches.view',
                'branches.create',
                'branches.update',
                'branches.delete',
                'branches.tree',
            ],
        ],

        'merchants' => [
            'label' => 'Merchants',
            'permissions' => [
                'merchants.view',
                'merchants.create',
                'merchants.update',
                'merchants.delete',
                'merchants.approve',
                'merchants.suspend',
            ],
        ],

        'merchant_applications' => [
            'label' => 'Merchant Applications',
            'permissions' => [
                'merchant_applications.view',
                'merchant_applications.approve',
                'merchant_applications.reject',
                'merchant_applications.request_more_info',
                'merchant_applications.assign_branch',
            ],
        ],

        'merchant_onboarding' => [
            'label' => 'Merchant Onboarding',
            'permissions' => [
                'merchant_onboarding.view',
                'merchant_onboarding.update',
                'merchant_onboarding.submit',
            ],
        ],

        'customers' => [
            'label' => 'Customers',
            'permissions' => [
                'customers.view',
                'customers.create',
                'customers.update',
                'customers.delete',
            ],
        ],

        'shipments' => [
            'label' => 'Shipments',
            'permissions' => [
                'shipments.view',
                'shipments.create',
                'shipments.update',
                'shipments.delete',
                'shipments.cancel',
                'shipments.status',
            ],
        ],

        'pickups' => [
            'label' => 'Pickups',
            'permissions' => [
                'pickups.view',
                'pickups.create',
                'pickups.assign',
                'pickups.assign_branch',
                'pickups.status',
            ],
        ],

        'dispatches' => [
            'label' => 'Dispatches',
            'permissions' => [
                'dispatches.view',
                'dispatches.create',
                'dispatches.receive',
                'dispatches.status',
            ],
        ],

        'deliveries' => [
            'label' => 'Deliveries',
            'permissions' => [
                'deliveries.view',
                'deliveries.create',
                'deliveries.assign',
                'deliveries.assign_branch',
                'deliveries.status',
            ],
        ],

        'cod' => [
            'label' => 'COD',
            'permissions' => [
                'cod.view',
                'cod.collect',
                'cod.deposit',
                'cod.confirm',
            ],
        ],

        'settlements' => [
            'label' => 'Settlements',
            'permissions' => [
                'settlements.view',
                'settlements.create',
                'settlements.pay',
                'settlements.mark_paid',
            ],
        ],

        'billing' => [
            'label' => 'Billing',
            'permissions' => [
                'invoices.view',
                'invoices.create',
                'receipts.view',
                'receipts.create',
            ],
        ],

        'rates' => [
            'label' => 'Rates',
            'permissions' => [
                'rates.view',
                'rates.create',
                'rates.update',
                'rates.delete',
                'rates.calculate',
                'rates.manage',
            ],
        ],

        'rate_cards' => [
            'label' => 'Rate Cards',
            'permissions' => [
                'rate_cards.view',
                'rate_cards.create',
                'rate_cards.update',
                'rate_cards.delete',
            ],
        ],

        'rate_rules' => [
            'label' => 'Rate Rules',
            'permissions' => [
                'rate_rules.view',
                'rate_rules.create',
                'rate_rules.update',
                'rate_rules.delete',
            ],
        ],

        'api_keys' => [
            'label' => 'API Keys',
            'permissions' => [
                'api_keys.view',
                'api_keys.create',
                'api_keys.delete',
                'api_keys.manage',
            ],
        ],

        'webhooks' => [
            'label' => 'Webhooks',
            'permissions' => [
                'webhooks.view',
                'webhooks.create',
                'webhooks.update',
                'webhooks.delete',
                'webhooks.test',
                'webhooks.retry',
                'webhooks.manage',
            ],
        ],

        'notifications' => [
            'label' => 'Notifications',
            'permissions' => [
                'notifications.view',
                'notifications.create',
                'notifications.manage',
                'notifications.mark_sent',
            ],
        ],

        'support_tickets' => [
            'label' => 'Support Tickets',
            'permissions' => [
                'support_tickets.view',
                'support_tickets.create',
                'support_tickets.update',
                'support_tickets.delete',
            ],
        ],

        'reports' => [
            'label' => 'Reports',
            'permissions' => [
                'reports.view',
                'reports.shipments',
                'reports.merchants',
                'reports.branches',
                'reports.staff',
                'reports.cod',
                'reports.revenue',
                'reports.export',
            ],
        ],

        'logs' => [
            'label' => 'Logs',
            'permissions' => [
                'api_logs.view',
                'audit_logs.view',
                'webhook_logs.view',
                'webhook_logs.retry',
                'email_logs.view',
                'sms_logs.view',
            ],
        ],

        'merchant_portal' => [
            'label' => 'Merchant Portal',
            'permissions' => [
                'merchant.dashboard',
                'merchant.shipments',
                'merchant.pickups',
                'merchant.cod',
                'merchant.settlements',
                'merchant.invoices',
                'merchant.api_keys',
                'merchant.webhooks',
                'merchant.support',
            ],
        ],

        'staff_portal' => [
            'label' => 'Staff Portal',
            'permissions' => [
                'staff.dashboard',
                'staff.pickups',
                'staff.deliveries',
                'staff.cod',
            ],
        ],
    ],

    'role_permissions' => [

        'super_admin' => ['*'],

        'main_admin' => [
            'dashboard.view',

            'merchant_applications.view',
            'merchant_applications.approve',
            'merchant_applications.reject',
            'merchant_applications.request_more_info',
            'merchant_applications.assign_branch',

            'merchants.view',
            'merchants.create',
            'merchants.update',
            'merchants.approve',
            'merchants.suspend',

            'customers.view',
            'customers.create',
            'customers.update',

            'shipments.view',
            'shipments.create',
            'shipments.update',
            'shipments.cancel',
            'shipments.status',

            'pickups.view',
            'pickups.assign',
            'pickups.assign_branch',
            'pickups.status',

            'dispatches.view',
            'dispatches.create',
            'dispatches.receive',
            'dispatches.status',

            'deliveries.view',
            'deliveries.assign',
            'deliveries.assign_branch',
            'deliveries.status',

            'cod.view',
            'cod.collect',
            'cod.deposit',
            'cod.confirm',

            'settlements.view',
            'settlements.create',
            'settlements.pay',
            'settlements.mark_paid',

            'branches.view',
            'branches.create',
            'branches.update',
            'branches.tree',

            'rates.view',
            'rates.calculate',
            'rate_cards.view',
            'rate_rules.view',

            'reports.view',
            'reports.shipments',
            'reports.merchants',
            'reports.branches',
            'reports.staff',
            'reports.cod',
            'reports.revenue',
            'reports.export',

            'notifications.view',
            'support_tickets.view',
            'support_tickets.update',

            'settings.view',
        ],

        'branch_manager' => [
            'dashboard.view',

            'shipments.view',
            'shipments.create',
            'shipments.update',
            'shipments.status',

            'pickups.view',
            'pickups.assign',
            'pickups.status',

            'dispatches.view',
            'dispatches.create',
            'dispatches.receive',
            'dispatches.status',

            'deliveries.view',
            'deliveries.assign',
            'deliveries.status',

            'cod.view',
            'cod.deposit',

            'branches.view',
            'customers.view',
            'reports.view',
            'reports.shipments',
            'reports.cod',
        ],

        'sub_branch_manager' => [
            'dashboard.view',

            'shipments.view',
            'shipments.status',

            'pickups.view',
            'pickups.assign',
            'pickups.status',

            'dispatches.view',
            'dispatches.receive',
            'dispatches.status',

            'deliveries.view',
            'deliveries.assign',
            'deliveries.status',

            'cod.view',
            'cod.deposit',
        ],

        'booking_staff' => [
            'dashboard.view',

            'customers.view',
            'customers.create',
            'customers.update',

            'shipments.view',
            'shipments.create',
            'shipments.update',

            'pickups.view',
            'rates.view',
            'rates.calculate',
        ],

        'pickup_staff' => [
            'staff.dashboard',
            'staff.pickups',
            'pickups.view',
            'pickups.status',
        ],

        'delivery_rider' => [
            'staff.dashboard',
            'staff.deliveries',
            'staff.cod',
            'deliveries.view',
            'deliveries.status',
            'cod.collect',
        ],

        'merchant' => [
            'merchant.dashboard',
            'merchant.shipments',
            'merchant.pickups',
            'merchant.cod',
            'merchant.settlements',
            'merchant.invoices',
            'merchant.api_keys',
            'merchant.webhooks',
            'merchant.support',

            'merchant_onboarding.view',
            'merchant_onboarding.update',
            'merchant_onboarding.submit',

            'shipments.view',
            'shipments.create',
            'pickups.view',
            'cod.view',
            'settlements.view',
        ],
    ],
];