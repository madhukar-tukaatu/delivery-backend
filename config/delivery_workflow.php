<?php

return [
    'volumetric_divisor' => 5000,

    'pricing' => [
        'base_fee' => 80,
        'rate_per_km' => 8,
        'rate_per_kg' => 25,
        'pod_fee_percent' => 1.0,
        'minimum_pod_fee' => 20,
    ],

    'sharing' => [
        'branch_percent' => 50,
        'franchise_percent' => 20,
        'hq_percent' => 30,
    ],

    'failed_delivery' => [
        'max_attempts' => 3,
        'return_fee_percent_of_delivery_charge' => 100,
    ],

    'statuses' => [
        'shipment' => [
            'pending_pickup',
            'picked_up',
            'at_origin_hub',
            'in_transit',
            'at_destination_hub',
            'out_for_delivery',
            'delivered',
            'failed_delivery',
            'return_pending',
            'returned',
        ],
    ],
];
