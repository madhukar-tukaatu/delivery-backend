<?php

namespace Modules\Dispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\CourierStatus;
use Illuminate\Http\Request;
use Modules\Dispatch\Models\DispatchManifest;
use Modules\Dispatch\Services\TransferContainerService;
use Modules\Shipment\Models\Shipment;

class DispatchController extends Controller
{
    public function index(Request $request)
    {
        $query = DispatchManifest::with(['fromBranch:id,name', 'toBranch:id,name', 'rider:id,name,phone'])
            ->withCount(['items as shipment_count' => fn ($q) => $q->whereNotIn('status', ['cancelled', 'moved'])])
            ->latest();
        if ($request->filled('status')) {
            $query->whereIn('status', array_filter(explode(',', (string) $request->status)));
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->search);
            $query->where(function ($q) use ($term) {
                $q->where('manifest_number', 'like', "%{$term}%")
                    ->orWhere('driver_name', 'like', "%{$term}%")
                    ->orWhere('vehicle_number', 'like', "%{$term}%");
                if (\Illuminate\Support\Facades\Schema::hasColumn('dispatch_manifests', 'transfer_number')) {
                    $q->orWhere('transfer_number', 'like', "%{$term}%");
                }
            });
        }
        $user = $request->user();
        if (! \App\Support\FinanceBranchScope::isHq($user) && $user->branch_id) {
            $query->where(fn ($q) => $q->where('from_branch_id', $user->branch_id)->orWhere('to_branch_id', $user->branch_id));
        }

        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    /**
     * Manual TR: parcels must be ready at the sending branch and have
     * to_branch as their next hop (same rules as Transfers > Outbound).
     */
    public function store(Request $request, TransferContainerService $containers)
    {
        $data = $request->validate([
            'from_branch_id' => ['nullable', 'exists:branches,id'],
            'from_sub_branch_id' => ['nullable', 'exists:branches,id'],
            'to_branch_id' => ['required', 'exists:branches,id'],
            'to_sub_branch_id' => ['nullable', 'exists:branches,id'],
            'vehicle_type' => ['nullable', 'in:'.implode(',', DispatchManifest::VEHICLE_TYPES)],
            'vehicle_number' => ['nullable', 'string', 'max:50'],
            'rider_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'driver_name' => ['nullable', 'string', 'max:100'],
            'driver_phone' => ['nullable', 'string', 'max:20'],
            'seal_number' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'transport_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'transport_cost_split_mode' => ['nullable', 'in:equal,weight'],
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['exists:shipments,id'],
        ]);

        $user = $request->user();
        $from = (int) ($data['from_branch_id'] ?? 0) ?: (int) ($user->branch_id ?? 0);
        if (! \App\Support\FinanceBranchScope::isHq($user) && $user->branch_id && $from !== (int) $user->branch_id) {
            abort(403, 'You can only dispatch from your own branch.');
        }

        $shipments = Shipment::query()->whereIn('id', $data['shipment_ids'])
            ->where(fn ($q) => $q->where('current_branch_id', $from)->orWhere(fn ($o) => $o->whereNull('current_branch_id')->where('origin_branch_id', $from)))
            ->whereIn('status', [
                CourierStatus::SORTED_FOR_TRANSFER,
                CourierStatus::RECEIVED_AT_ORIGIN_BRANCH,
                CourierStatus::RECEIVED_AT_TRANSIT_HUB,
            ])
            ->get();
        $notReady = array_diff(array_map('intval', $data['shipment_ids']), $shipments->pluck('id')->all());

        $out = $containers->dispatch($shipments, (int) $data['to_branch_id'], $from, $data, (int) $user->id);
        $skipped = $out['skipped'];
        foreach ($notReady as $id) {
            $skipped[$id] = 'Not ready for transfer at the sending branch.';
        }

        if ($out['manifests'] === []) {
            return ApiResponse::error(collect($skipped)->first() ?: 'Nothing dispatched.', 422, ['skipped' => $skipped]);
        }

        $manifest = $out['manifests'][0];

        return ApiResponse::success(
            $manifest->load('items.shipment')->setAttribute('skipped', $skipped),
            $manifest->display_number.' dispatched'.($skipped ? ', '.count($skipped).' skipped.' : '.'),
            201
        );
    }

    /**
     * Old receive endpoint: now the TR check-in. received_shipment_ids = the
     * parcels that arrived (default: every parcel on the TR). Final-here parcels
     * go to last mile, onward ones are sorted for the next TR, the rest are
     * flagged missing.
     */
    public function receive(Request $request, DispatchManifest $dispatch, TransferContainerService $containers)
    {
        $data = $request->validate([
            'received_shipment_ids' => ['nullable', 'array'],
            'received_shipment_ids.*' => ['integer'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        $user = $request->user();
        $branchId = \App\Support\FinanceBranchScope::isHq($user) ? 0 : (int) ($user->branch_id ?? 0);

        $scanned = $data['received_shipment_ids']
            ?? $dispatch->items()->whereIn('status', TransferContainerService::OPEN_ITEM_STATUSES)->pluck('shipment_id')->all();

        $res = $containers->receive($dispatch, $scanned, $data['remarks'] ?? null, (int) $user->id, $branchId);

        return ApiResponse::success(
            $res['container']->load('items.shipment'),
            $res['container']->display_number.' received'.($res['missing'] ? ' ('.count($res['missing']).' missing).' : '.')
        );
    }

    /** GET admin/dispatches/{dispatch}/transport-cost : trip cost and per-shipment allocation. */
    public function transportCost(Request $request, DispatchManifest $dispatch)
    {
        $dispatch->load(['items.shipment:id,tracking_number,weight,chargeable_weight,status']);

        return ApiResponse::success([
            'manifest_id' => $dispatch->id,
            'manifest_number' => $dispatch->manifest_number,
            'transfer_number' => $dispatch->transfer_number ?? null,
            'display_number' => $dispatch->display_number,
            'from_branch_id' => $dispatch->from_branch_id,
            'to_branch_id' => $dispatch->to_branch_id,
            'transport_cost' => (float) ($dispatch->transport_cost ?? 0),
            'transport_cost_split_mode' => $dispatch->transport_cost_split_mode ?? 'equal',
            'transport_cost_entered_by' => $dispatch->transport_cost_entered_by,
            'transport_cost_entered_at' => $dispatch->transport_cost_entered_at,
            'transport_cost_updated_by' => $dispatch->transport_cost_updated_by,
            'transport_cost_updated_at' => $dispatch->transport_cost_updated_at,
            'transport_cost_log' => $dispatch->transport_cost_log ?? [],
            'can_edit' => $this->canEditTransportCost($request, $dispatch),
            'items' => $dispatch->items->map(fn ($i) => [
                'item_id' => $i->id,
                'shipment_id' => $i->shipment_id,
                'tracking_number' => $i->shipment?->tracking_number,
                'weight' => (float) ($i->shipment?->chargeable_weight ?: $i->shipment?->weight ?: 0),
                'status' => $i->status,
                'transport_cost' => (float) ($i->transport_cost ?? 0),
            ])->values(),
        ]);
    }

    /** POST admin/dispatches/{dispatch}/transport-cost {transport_cost, transport_cost_split_mode, reason?} */
    public function updateTransportCost(Request $request, DispatchManifest $dispatch)
    {
        abort_unless($this->canEditTransportCost($request, $dispatch), 403, 'Only the dispatching branch or HQ can change this transport cost.');

        $data = $request->validate([
            'transport_cost' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'transport_cost_split_mode' => ['nullable', 'in:equal,weight'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        app(\Modules\Dispatch\Services\ManifestHopService::class)->updateTransportCost(
            $dispatch,
            (float) $data['transport_cost'],
            (string) ($data['transport_cost_split_mode'] ?? $dispatch->transport_cost_split_mode ?? 'equal'),
            (int) $request->user()->id,
            $data['reason'] ?? null,
        );

        return $this->transportCost($request, $dispatch->fresh());
    }

    private function canEditTransportCost(Request $request, DispatchManifest $dispatch): bool
    {
        $user = $request->user();
        if (\App\Support\FinanceBranchScope::isHq($user)) {
            return true;
        }

        return $dispatch->from_branch_id
            && \App\Support\FinanceBranchScope::canSeeBranch($user, $dispatch->from_branch_id);
    }
}
