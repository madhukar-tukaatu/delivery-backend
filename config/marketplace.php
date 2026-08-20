<?php

return [
    'signature_tolerance_seconds' => (int) env(
        'MARKETPLACE_SIGNATURE_TOLERANCE_SECONDS',
        300
    ),

    'replay_ttl_seconds'          => (int) env(
        'MARKETPLACE_REPLAY_TTL_SECONDS',
        420
    ),

    'pricing_rate_limit'          => (int) env(
        'MARKETPLACE_PRICING_RATE_LIMIT',
        300
    ),

    // 'store_packet_mode' => env(
    //     'MARKETPLACE_STORE_PACKET_MODE',
    //     'single_per_store'
    // ),
    'store_packet_mode'           => 'single_per_store',
    // 'store_packet_mode' => 'explicit_packets',
    // 'store_packet_mode' => 'per_product_quantity',
    /*
     * Marketplace pricing uses physical transfer-lane count
     * instead of direct branch_route_rates.
     */
    'use_transfer_count_pricing'  =>
    true,

    /*
    |--------------------------------------------------------------------------
    | Marketplace route pricing
    |--------------------------------------------------------------------------
    */

    'base_rate_mode'              => 'branch_route',
    // 'base_rate_mode' => 'configured_transfer_route',

    /*
     * If marketplace product unit_weight is missing,
     * this weight is used as the actual-weight fallback.
     */
    'default_product_weight_kg'   => env(
        'MARKETPLACE_DEFAULT_PRODUCT_WEIGHT_KG',
        1.5
    ),

    /*
     * Volumetric weight:
     *
     * L(cm) × W(cm) × H(cm) / divisor
     */
    'volumetric_divisor'          => env(
        'MARKETPLACE_VOLUMETRIC_DIVISOR',
        5000
    ),

    'max_product_weight_kg'       => env(
        'MARKETPLACE_MAX_PRODUCT_WEIGHT_KG',
        100
    ),

    'max_packet_weight_kg'        => env(
        'MARKETPLACE_MAX_PACKET_WEIGHT_KG',
        100
    ),

    'max_store_weight_kg'         => env(
        'MARKETPLACE_MAX_STORE_WEIGHT_KG',
        100
    ),

    'max_dimension_cm'            => env(
        'MARKETPLACE_MAX_DIMENSION_CM',
        200
    ),

    'max_volume_cm3'              => env(
        'MARKETPLACE_MAX_VOLUME_CM3',
        200 * 200 * 200
    ),
];
