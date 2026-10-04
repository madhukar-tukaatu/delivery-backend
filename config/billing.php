<?php

return [
    /*
    | Fallback only. Marketplace free-delivery bills email marketplaces.email
    | for the shipment marketplace (tukaatu.com, FCA, others).
    | BILLING_MARKETPLACE_EMAIL is used when that email is empty.
    */
    'marketplace_email' => env('BILLING_MARKETPLACE_EMAIL'),
];
