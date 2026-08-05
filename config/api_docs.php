<?php

return [
    /*
     * Enables Scramble documentation outside
     * the local environment.
     */
    'enabled' => env(
        'API_DOCS_ENABLED',
        false
    ),
];