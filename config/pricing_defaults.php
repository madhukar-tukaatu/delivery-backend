<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default pricing-rule values
    |--------------------------------------------------------------------------
    |
    | These values are loaded into the /admin/rates form.
    | Loading the defaults does not automatically save or activate them.
    |
    */

    'rules' => [
        'name' => 'Kathmandu Default Pricing Rules',

        'base_weight_kg' => 1.50,
        'base_distance_km' => 5.00,

        'local_extra_weight_rate' => 20.00,
        'transfer_extra_weight_rate' => 30.00,

        'extra_distance_rate' => 6.00,

        'fragile_multiplier' => 1.0500,

        'local_same_day_multiplier' => 1.5000,
        'transfer_same_day_multiplier' => 2.0000,

        'local_express_multiplier' => 1.2000,
        'transfer_express_multiplier' => 1.3000,

        'same_day_cutoff_time' => '12:00',

        'minimum_free_pickup_packets' => 3,
        'small_pickup_charge' => 50.00,

        'vat_percentage' => 13.00,
        'vat_inclusive' => true,

        'weight_rounding' => 'none',
        'distance_rounding' => 'none',
        'money_rounding' => 'round',

        'fragile_enabled' => true,
        'same_day_enabled' => true,
        'express_enabled' => true,
        'pickup_charge_enabled' => true,
        'vat_enabled' => true,
    ],
];