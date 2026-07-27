<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default pricing preset
    |--------------------------------------------------------------------------
    |
    | These values reproduce the supplied Kathmandu pricing sheet.
    | The importer creates a new pricing-settings version and updates existing
    | configured transfer routes. It never invents a multi-stop path.
    |
    */

    'preset_name' => 'Kathmandu Default Pricing',

    'currency' => 'NPR',

    'service_type' => 'standard',

    'source_branch_aliases' => [
        'NP-KTM-MAIN',
        'KTM-MAIN',
        'KTM',
        'Kathmandu Main Branch',
        'Kathmandu Branch',
        'TukaatuHQ',
    ],

    'settings' => [
        'name' => 'Kathmandu Default Pricing',
        'included_weight_kg' => 1.50,
        'same_branch_excess_weight_rate' => 20.00,
        'transfer_branch_excess_weight_rate' => 30.00,
        'included_delivery_distance_km' => 5.00,
        'extra_distance_rate_per_km' => 6.00,
        'fragile_multiplier' => 1.05,
        'same_day_same_branch_multiplier' => 1.50,
        'same_day_transfer_branch_multiplier' => 2.00,
        'same_day_cutoff_time' => '12:00',
        'minimum_pickup_packet_count' => 3,
        'low_packet_pickup_charge' => 50.00,
        'vat_percentage' => 13.00,
        'vat_inclusive' => true,
        'quote_validity_minutes' => 30,
        'change_reason' => 'Imported from Kathmandu default pricing sheet.',
    ],

    'return_rules' => [
        [
            'scenario_code' => 'same_branch_warehouse',
            'name' => 'Same Branch Warehouse',
            'base_rate_percentage' => 0.00,
            'distance_rate_per_km' => 0.00,
            'fixed_charge' => 0.00,
        ],
        [
            'scenario_code' => 'same_branch_assigned_delivery',
            'name' => 'Same Branch Warehouse and Assigned for Delivery',
            'base_rate_percentage' => 0.00,
            'distance_rate_per_km' => 2.00,
            'fixed_charge' => 0.00,
        ],
        [
            'scenario_code' => 'transfer_branch_warehouse',
            'name' => 'Transfer Branch Warehouse',
            'base_rate_percentage' => 30.00,
            'distance_rate_per_km' => 0.00,
            'fixed_charge' => 0.00,
        ],
        [
            'scenario_code' => 'transfer_branch_assigned_delivery',
            'name' => 'Transferred Branch Warehouse and Assigned for Delivery',
            'base_rate_percentage' => 30.00,
            'distance_rate_per_km' => 3.00,
            'fixed_charge' => 0.00,
        ],
    ],

    /*
     * The first matching alias is used for each destination branch.
     */
    'route_rates' => [
        [
            'destination_aliases' => [
                'NP-KTM-MAIN',
                'KTM-MAIN',
                'KTM',
                'Kathmandu Main Branch',
                'Kathmandu Branch',
                'TukaatuHQ',
            ],
            'base_rate' => 79.00,
        ],
        [
            'destination_aliases' => ['Itahari Branch', 'Itahari'],
            'base_rate' => 149.00,
        ],
        [
            'destination_aliases' => [
                'Birtamode Branch',
                'Birtamod Branch',
                'Birtamode',
                'Birtamod',
            ],
            'base_rate' => 149.00,
        ],
        [
            'destination_aliases' => ['Damak Branch', 'Damak'],
            'base_rate' => 169.00,
        ],
        [
            'destination_aliases' => [
                'NP-PKR-MAIN',
                'PKR',
                'PKR-Branch',
                'Pokhara Branch',
                'Pokhara',
            ],
            'base_rate' => 149.00,
        ],
        [
            'destination_aliases' => [
                'Bhairahawa Branch',
                'Bhairahawa',
                'Siddharthanagar',
            ],
            'base_rate' => 149.00,
        ],
        [
            'destination_aliases' => [
                'NP-BRT-MAIN',
                'BRT',
                'Biratnagar Tukaatu',
                'Biratnagar Branch',
                'Biratnagar',
            ],
            'base_rate' => 149.00,
        ],
        [
            'destination_aliases' => ['Birgunj Branch', 'Birgunj'],
            'base_rate' => 149.00,
        ],
        [
            'destination_aliases' => [
                'Chitwan-Bharatpur Branch',
                'Bharatpur Branch',
                'Chitwan Branch',
                'Bharatpur',
                'Chitwan',
            ],
            'base_rate' => 149.00,
        ],
        [
            'destination_aliases' => ['Banepa Branch', 'Banepa'],
            'base_rate' => 149.00,
        ],
        [
            'destination_aliases' => ['Dharan Branch', 'Dharan'],
            'base_rate' => 169.00,
        ],
        [
            'destination_aliases' => ['Janakpur Branch', 'Janakpur'],
            'base_rate' => 149.00,
        ],
        [
            'destination_aliases' => ['Nepalgunj Branch', 'Nepalgunj'],
            'base_rate' => 149.00,
        ],
        [
            'destination_aliases' => [
                'Mahendranagar Branch',
                'Mahendranagar',
                'Bhimdatta',
            ],
            'base_rate' => 149.00,
        ],
        [
            'destination_aliases' => [
                'Birendranagar Branch',
                'Birendranagar',
                'Surkhet Branch',
                'Surkhet',
            ],
            'base_rate' => 149.00,
        ],
        [
            'destination_aliases' => ['Dhangadhi Branch', 'Dhangadhi'],
            'base_rate' => 149.00,
        ],
        [
            'destination_aliases' => ['Lahan Branch', 'Lahan'],
            'base_rate' => 169.00,
        ],
        [
            'destination_aliases' => ['Hetauda Branch', 'Hetauda'],
            'base_rate' => 149.00,
        ],
        [
            'destination_aliases' => ['Inaruwa Branch', 'Inaruwa'],
            'base_rate' => 169.00,
        ],
        [
            'destination_aliases' => ['Bardibas Branch', 'Bardibas'],
            'base_rate' => 169.00,
        ],
        [
            'destination_aliases' => [
                'Butwal Branch',
                'Butwol Branch',
                'Butwal',
                'Butwol',
            ],
            'base_rate' => 169.00,
        ],
        [
            'destination_aliases' => ['Dhankuta Branch', 'Dhankuta'],
            'base_rate' => 189.00,
        ],
        [
            'destination_aliases' => ['Ilam Branch', 'Ilam'],
            'base_rate' => 169.00,
        ],
        [
            'destination_aliases' => ['Baglung Branch', 'Baglung'],
            'base_rate' => 169.00,
        ],
    ],
];
