<?php

namespace Modules\Setting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Setting\Models\Setting;

class SettingController extends Controller
{
    public function index()
    {
        return ApiResponse::success(Setting::orderBy('key')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'string'],
            'value' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
        ]);
        $setting = Setting::updateOrCreate(['key' => $data['key']], $data);
        return ApiResponse::success($setting, 'Setting saved.');
    }
}
