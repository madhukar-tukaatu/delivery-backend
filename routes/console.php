<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('courier:hello', function () {
    $this->info('Tukaatu Express is ready.');
});
