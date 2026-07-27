<?php

namespace Database\Seeders\Pricing;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class BranchTransferRateTierSeeder extends Seeder
{
    public function run(): void
    {
        /*
         * Replace these amounts with the official HQ rates.
         */
        $rates = [
            0 => 79.00,
            1 => 149.00,
            2 => 249.00,
            3 => 349.00,
        ];

        foreach ($rates as $transferCount => $baseRate) {
            DB::table(
                'branch_transfer_rate_tiers'
            )->updateOrInsert(
                [
                    'service_type' =>
                        'standard',

                    'transfer_count' =>
                        $transferCount,
                ],
                [
                    'base_rate' =>
                        $baseRate,

                    'is_active' =>
                        true,

                    'created_at' =>
                        now(),

                    'updated_at' =>
                        now(),
                ]
            );
        }
    }
}