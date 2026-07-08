<?php

namespace Database\Seeders\System;

use Illuminate\Database\Seeder;
use Modules\Setting\Models\Setting;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'company_name' => ['Hotstone Courier Nepal','string'],
            'tracking_prefix' => ['NPD','string'],
            'default_currency' => ['NPR','string'],
            'seed_last_profile' => [env('SEED_PROFILE', 'demo'),'string'],
        ] as $key => [$value, $type]) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value, 'type' => $type]);
        }
    }
}
