<?php

declare(strict_types=1);

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\CourierStatus;
use Illuminate\Http\Request;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\TransferService;

/**
 * Branch-to-branch transfer board.
 *
 *   Outbound = shipments sorted_for_transfer at this branch (ready to send).
 *   Inbound  = shipments in_transit whose destination is this branch
 *              (ready to receive).
 */
final class TransferController extends Controller
{
    public function __construct(
        private readonly TransferService $service,
    ) {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->isSuperAdmin() || $user->hasRole('main_admin');
        $branchId = (int) ($user->branch_id ?? 0);
        $direction = $request->string('direction')->toString() ?: 'outbound';

        $query = Shipment::query()->with([
            'merchant',
            'originBranch',
            'destinationBranch',
        ]);

        if ($direction === 'inbound') {
            $query->where('status', CourierStatus::IN_TRANSIT);

            if (! $isAdmin && $branchId > 0) {
                $query->where(function ($q) use ($branchId) {
                    $q->where('destination_branch_id', $branchId)
                        ->orWhere('destination_sub_branch_id', $branchId);
                });
            } elseif ($request->filled('branch_id')) {
                $bid = (int) $request->input('branch_id');
                $query->where(function ($q) use ($bid) {
                    $q->where('destination_branch_id', $bid)
                        ->orWhere('destination_sub_branch_id', $bid);
                });
            }
        } else {
            // outbound
            $query->where('status', CourierStatus::SORTED_FOR_TRANSFER);

            if (! $isAdmin && $branchId > 0) {
                $query->where(function ($q) use ($branchId) {
                    $q->where('origin_branch_id', $branchId)
                        ->orWhere('origin_sub_branch_id', $branchId)
                        ->orWhere('current_branch_id', $branchId)
                        ->orWhere('current_sub_branch_id', $branchId);
                });
            } elseif ($request->filled('branch_id')) {
                $bid = (int) $request->input('branch_id');
                $query->where(function ($q) use ($bid) {
                    $q->where('origin_branch_id', $bid)
                        ->orWhere('current_branch_id', $bid);
                });
            }
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('tracking_number', 'like', "%{$search}%")
                        ->orWhere('receiver_name', 'like', "%{$search}%")
                        ->orWhere('receiver_phone', 'like', "%{$search}%");
                });
            }
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return ApiResponse::success($query->latest('id')->paginate($perPage));
    }

    public function summary(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->isSuperAdmin() || $user->hasRole('main_admin');
        $branchId = (int) ($user->branch_id ?? 0);

        $outbound = Shipment::query()->where('status', CourierStatus::SORTED_FOR_TRANSFER)
            ->when(! $isAdmin && $branchId > 0, function ($q) use ($branchId) {
                $q->where(function ($qq) use ($branchId) {
                    $qq->where('origin_branch_id', $branchId)
                        ->orWhere('current_branch_id', $branchId);
                });
            })->count();

        $inbound = Shipment::query()->where('status', CourierStatus::IN_TRANSIT)
            ->when(! $isAdmin && $branchId > 0, function ($q) use ($branchId) {
                $q->where(function ($qq) use ($branchId) {
                    $qq->where('destination_branch_id', $branchId)
                        ->orWhere('destination_sub_branch_id', $branchId);
                });
            })->count();

        return ApiResponse::success([
            'outbound' => $outbound,
            'inbound' => $inbound,
        ]);
    }

    /**
     * Dispatch one or many transfer shipments.
     */
    public function dispatch(Request $request)
    {
        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer'],
        ]);

        $result = $this->service->bulkDispatch($data['shipment_ids'], $request->user()->id);

        $ok = count($result['dispatched']);
        $skip = count($result['skipped']);

        return ApiResponse::success(
            $result,
            $skip === 0 ? "{$ok} shipment(s) dispatched." : "{$ok} dispatched, {$skip} skipped."
        );
    }

    /**
     * Receive an in-transit transfer at the destination branch.
     */
    public function receive(Request $request, Shipment $shipment)
    {
        return ApiResponse::success(
            $this->service->receiveAtDestination($shipment, $request->user()->id),
            'Shipment received at destination branch and queued for delivery.'
        );
    }
}
