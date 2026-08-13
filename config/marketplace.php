<?php

return [
    'signature_tolerance_seconds' => (int) env(
        'MARKETPLACE_SIGNATURE_TOLERANCE_SECONDS',
        300
    ),

    'replay_ttl_seconds' => (int) env(
        'MARKETPLACE_REPLAY_TTL_SECONDS',
        420
    ),

    'pricing_rate_limit' => (int) env(
        'MARKETPLACE_PRICING_RATE_LIMIT',
        300
    ),

    // 'store_packet_mode' => env(
    //     'MARKETPLACE_STORE_PACKET_MODE',
    //     'single_per_store'
    // ),
    'store_packet_mode' => 'single_per_store',
    // 'store_packet_mode' => 'explicit_packets',
    // 'store_packet_mode' => 'per_product_quantity',
    /*
     * Marketplace pricing uses physical transfer-lane count
     * instead of direct branch_route_rates.
     */
    'use_transfer_count_pricing' =>
    true,

    /*
    |--------------------------------------------------------------------------
    | Marketplace route pricing
    |--------------------------------------------------------------------------
    */

    'base_rate_mode' => 'branch_route',
    // 'base_rate_mode' => 'configured_transfer_route',
];
