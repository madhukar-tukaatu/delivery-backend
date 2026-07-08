<?php

namespace Modules\COD\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\COD\Models\CodDeposit;
use Modules\COD\Models\CodRecord;

class CodController extends Controller
{
    public function index(Request $request)
    {
        $query = CodRecord::with('shipment')->latest();
        if ($request->user()->role === 'merchant') $query->where('merchant_id', $request->user()->merchant_id);
        if ($request->filled('status')) $query->where('status', $request->status);
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function collect(Request $request, CodRecord $cod)
    {
        $data = $request->validate([
            'collected_amount' => ['required', 'numeric', 'min:0'],
        ]);
        $cod->update([
            'status' => 'collected',
            'collected_amount' => $data['collected_amount'],
            'collected_by' => $request->user()->id,
            'collected_at' => now(),
        ]);
        return ApiResponse::success($cod->fresh(), 'COD marked collected.');
    }

    public function deposit(Request $request)
    {
        $data = $request->validate([
            'cod_record_ids' => ['required', 'array'],
            'cod_record_ids.*' => ['exists:cod_records,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'remarks' => ['nullable', 'string'],
        ]);
        $records = CodRecord::whereIn('id', $data['cod_record_ids'])->get();
        $amount = $records->sum('collected_amount');
        $deposit = CodDeposit::create([
            'branch_id' => $data['branch_id'] ?? $request->user()->branch_id,
            'staff_id' => $request->user()->id,
            'amount' => $amount,
            'status' => 'confirmed',
            'remarks' => $data['remarks'] ?? null,
        ]);
        CodRecord::whereIn('id', $data['cod_record_ids'])->update([
            'status' => 'deposited',
            'deposited_to_branch_id' => $data['branch_id'] ?? $request->user()->branch_id,
            'deposited_at' => now(),
        ]);
        return ApiResponse::success($deposit, 'COD deposited to branch.');
    }
}
