<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'message' => 'Courier Delivery Gateway API is running.',
        'api' => url('/api/v1/health'),
    ]);
});
