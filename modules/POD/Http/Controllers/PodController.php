<?php

namespace Modules\POD\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\POD\Models\PodDeposit;
use Modules\POD\Models\PodRecord;

class PodController extends Controller
{
    public function index(Request $request)
    {
        $query = PodRecord::with('shipment')->latest();
        if ($request->user()->role === 'merchant') $query->where('merchant_id', $request->user()->merchant_id);
        if ($request->filled('status')) $query->where('status', $request->status);
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function collect(Request $request, PodRecord $pod)
    {
        if ($pod->payment_destination === 'merchant') {
            throw ValidationException::withMessages([
                'pod' => ['This payment was already paid directly to the merchant and cannot be collected by the platform.'],
            ]);
        }

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

        $recordIds = array_values(array_unique($data['pod_record_ids']));
        $records = PodRecord::whereIn('id', $recordIds)
            ->where(function ($query) {
                $query->whereNull('payment_destination')
                    ->orWhere('payment_destination', '!=', 'merchant');
            })
            ->get();

        if ($records->count() !== count($recordIds)) {
            throw ValidationException::withMessages([
                'pod_record_ids' => ['Direct merchant payments cannot be deposited to the platform.'],
            ]);
        }

        $amount = $records->sum('collected_amount');
        $deposit = PodDeposit::create([
            'branch_id' => $data['branch_id'] ?? $request->user()->branch_id,
            'staff_id' => $request->user()->id,
            'amount' => $amount,
            'status' => 'confirmed',
            'remarks' => $data['remarks'] ?? null,
        ]);
        PodRecord::whereIn('id', $recordIds)->update([
            'status' => 'deposited',
            'deposited_to_branch_id' => $data['branch_id'] ?? $request->user()->branch_id,
            'deposited_at' => now(),
        ]);
        return ApiResponse::success($deposit, 'POD deposited to branch.');
    }
}
