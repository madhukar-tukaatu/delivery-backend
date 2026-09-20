<?php

namespace Modules\POD\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\POD\Models\PodDeposit;
use Modules\POD\Models\PodRecord;
use Modules\Shipment\Models\Shipment;

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
            'payment_method' => $pod->payment_method ?: 'cash',
            'payment_destination' => $pod->payment_destination ?: 'rider',
        ]);

        if ($pod->shipment_id) {
            Shipment::whereKey($pod->shipment_id)->update([
                'pod_status' => 'collected',
                'settlement_status' => 'pending_deposit',
            ]);
        }

        return ApiResponse::success($pod->fresh('shipment'), 'POD marked collected.');
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

        foreach ($records as $record) {
            if ($record->status !== 'collected') {
                throw ValidationException::withMessages([
                    'pod_record_ids' => ['Only collected cash POD records can be deposited.'],
                ]);
            }
        }

        $branchId = $data['branch_id'] ?? $request->user()->branch_id;
        $amount = $records->sum('collected_amount');
        $deposit = PodDeposit::create([
            'branch_id' => $branchId,
            'staff_id' => $request->user()->id,
            'amount' => $amount,
            'status' => 'confirmed',
            'remarks' => $data['remarks'] ?? null,
        ]);

        PodRecord::whereIn('id', $recordIds)->update([
            'status' => 'deposited',
            'deposited_by' => $request->user()->id,
            'deposited_to_branch_id' => $branchId,
            'deposited_at' => now(),
            'remarks' => $data['remarks'] ?? null,
        ]);

        $shipmentIds = $records->pluck('shipment_id')->filter()->unique()->values();
        if ($shipmentIds->isNotEmpty()) {
            // Always record branch cash intake. Do not downgrade settlement if merchant
            // was already settled/processing via the on_collection path.
            Shipment::whereIn('id', $shipmentIds)->update(['pod_status' => 'deposited']);
            Shipment::whereIn('id', $shipmentIds)
                ->whereNotIn('settlement_status', ['processing', 'settled'])
                ->update(['settlement_status' => 'ready']);
        }

        return ApiResponse::success($deposit, 'POD deposited to branch. Shipments are ready for merchant settlement.');
    }
}
