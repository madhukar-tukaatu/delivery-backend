<?php

namespace Modules\POD\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\POD\Models\CodDeposit;
use Modules\POD\Models\CodRecord;

class CodController extends Controller
{
    public function index(Request $request)
    {
        $query = CodRecord::with('shipment')->latest();
        if ($request->user()->role === 'merchant') $query->where('merchant_id', $request->user()->merchant_id);
        if ($request->filled('status')) $query->where('status', $request->status);
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function collect(Request $request, CodRecord $pod)
    {
        $data = $request->validate([
            'collected_amount' => ['required', 'numeric', 'min:0'],
        ]);
        $pod->update([
            'status' => 'collected',
            'collected_amount' => $data['collected_amount'],
            'collected_by' => $request->user()->id,
            'collected_at' => now(),
        ]);
        return ApiResponse::success($pod->fresh(), 'POD marked collected.');
    }

    public function deposit(Request $request)
    {
        $data = $request->validate([
            'pod_record_ids' => ['required', 'array'],
            'pod_record_ids.*' => ['exists:pod_records,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'remarks' => ['nullable', 'string'],
        ]);
        $records = CodRecord::whereIn('id', $data['pod_record_ids'])->get();
        $amount = $records->sum('collected_amount');
        $deposit = CodDeposit::create([
            'branch_id' => $data['branch_id'] ?? $request->user()->branch_id,
            'staff_id' => $request->user()->id,
            'amount' => $amount,
            'status' => 'confirmed',
            'remarks' => $data['remarks'] ?? null,
        ]);
        CodRecord::whereIn('id', $data['pod_record_ids'])->update([
            'status' => 'deposited',
            'deposited_to_branch_id' => $data['branch_id'] ?? $request->user()->branch_id,
            'deposited_at' => now(),
        ]);
        return ApiResponse::success($deposit, 'POD deposited to branch.');
    }
}
