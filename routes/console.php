<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('courier:hello', function () {
    $this->info('Courier Delivery Gateway is ready.');
});
