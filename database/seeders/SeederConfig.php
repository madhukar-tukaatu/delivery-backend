<?php

namespace Database\Seeders;

class SeederConfig
{
    public static function performance(): array
    {
        return [
            'merchants' => (int) env('SEED_MERCHANTS', 500),
            'customers' => (int) env('SEED_CUSTOMERS', 5000),
            'staff_per_branch' => (int) env('SEED_STAFF_PER_BRANCH', 2),
            'riders_per_branch' => (int) env('SEED_RIDERS_PER_BRANCH', 2),
            'shipments' => (int) env('SEED_SHIPMENTS', 12000),
            'chunk' => (int) env('SEED_CHUNK', 1000),
            'cod_ratio' => (float) env('SEED_COD_RATIO', 0.72),
            'delivered_ratio' => (float) env('SEED_DELIVERED_RATIO', 0.62),
            'failed_ratio' => (float) env('SEED_FAILED_RATIO', 0.08),
        ];
    }
}
