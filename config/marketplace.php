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
];
