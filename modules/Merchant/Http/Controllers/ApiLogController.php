<?php

namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Merchant\Models\ApiLog;

class ApiLogController extends Controller
{
    public function index(Request $request)
    {
        $query = ApiLog::query()->with(['merchant','apiKey'])->latest();
        if ($request->filled('_scope_merchant_id')) $query->where('merchant_id', $request->get('_scope_merchant_id'));
        if ($request->filled('merchant_id')) $query->where('merchant_id', $request->merchant_id);
        if ($request->filled('status_code')) $query->where('status_code', $request->status_code);
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }
}
