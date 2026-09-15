<?php

declare(strict_types=1);

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\CourierStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\TransferService;

/**
 * Transfer Management Controller
 *
 * Handles cross-branch transfers with support for multi-hop routing:
 * - 1-hop: Direct transfer (A → B)
 * - 2-hop: Transfer via hub (A → Hub → B)
 * - 3-hop: Transfer via multiple hubs (A → Hub1 → Hub2 → B)
 *
 * Tabs:
 *   Outbound = Transfers ready to leave this branch
 *   In Transit = Transfers currently moving to/through this branch
 *   Received = Transfers arrived at this branch, ready for last-mile
 *   Completed = Transfers successfully delivered
 *   History = Complete transfer timeline
 */
final class TransferController extends Controller
{
    public function __construct(
        private readonly TransferService $service,
    ) {
    }

    /**
     * Main transfer board - list transfers by direction
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $branchId = $this->getBranchScope($user);
        $direction = $request->string('direction')->toString() ?: 'outbound';

        if (!in_array($direction, ['outbound', 'inbound'], true)) {
            $direction = 'outbound';
        }

        $query = Shipment::query()->with([
            'merchant',
            'originBranch',
            'originSubBranch.parent',
            'destinationBranch',
            'destinationSubBranch.parent',
            'currentBranch',
            'currentSubBranch.parent',
        ]);

        // Filter for cross-branch transfers only
        $query->whereNotNull('origin_branch_id')
            ->whereNotNull('destination_branch_id')
            ->whereColumn('origin_branch_id', '!=', 'destination_branch_id');

        if ($direction === 'inbound') {
            // In transit or at an intermediate hub destined for this branch
            $query->whereIn('status', [CourierStatus::IN_TRANSIT])
                ->where('destination_branch_id', $branchId);
        } else {
            // Outbound: ready to leave this branch
            $query->where('status', CourierStatus::SORTED_FOR_TRANSFER)
                ->where('origin_branch_id', $branchId);
        }

        // Search
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

    /**
     * Transfer statistics
     */
    public function stats(Request $request)
    {
        $user = $request->user();
        $branchId = $this->getBranchScope($user);

        // Cross-branch transfers only
        $baseQuery = Shipment::query()
            ->whereNotNull('origin_branch_id')
            ->whereNotNull('destination_branch_id')
            ->whereColumn('origin_branch_id', '!=', 'destination_branch_id');

        // Outbound: ready to send from this branch
        $outbound = (clone $baseQuery)
            ->where('status', CourierStatus::SORTED_FOR_TRANSFER)
            ->where('origin_branch_id', $branchId)
            ->count();

        // In transit: moving through this branch or to this branch
        $inTransit = (clone $baseQuery)
            ->where('status', CourierStatus::IN_TRANSIT)
            ->where(function ($q) use ($branchId) {
                $q->where('destination_branch_id', $branchId)
                    ->orWhere('current_branch_id', $branchId);
            })
            ->count();

        // Received: arrived at this branch
        $received = (clone $baseQuery)
            ->where('status', CourierStatus::RECEIVED_AT_DESTINATION_BRANCH)
            ->where('destination_branch_id', $branchId)
            ->count();

        // Completed: delivered after transfer to this branch
        $completed = (clone $baseQuery)
            ->where('status', CourierStatus::DELIVERED)
            ->where('destination_branch_id', $branchId)
            ->count();

        return ApiResponse::success([
            'outbound' => $outbound,
            'in_transit' => $inTransit,
            'received' => $received,
            'completed' => $completed,
        ]);
    }

    /**
     * Summary for transfer tabs
     */
    public function summary(Request $request)
    {
        $user = $request->user();
        $branchId = $this->getBranchScope($user);

        $baseQuery = Shipment::query()
            ->whereNotNull('origin_branch_id')
            ->whereNotNull('destination_branch_id')
            ->whereColumn('origin_branch_id', '!=', 'destination_branch_id');

        $outbound = (clone $baseQuery)
            ->where('status', CourierStatus::SORTED_FOR_TRANSFER)
            ->where('origin_branch_id', $branchId)
            ->count();

        $inbound = (clone $baseQuery)
            ->where('status', CourierStatus::IN_TRANSIT)
            ->where('destination_branch_id', $branchId)
            ->count();

        return ApiResponse::success([
            'outbound' => $outbound,
            'inbound' => $inbound,
        ]);
    }

    /**
     * Get received transfers at this branch
     */
    public function received(Request $request)
    {
        $user = $request->user();
        $branchId = $this->getBranchScope($user);

        $query = Shipment::query()->with([
            'merchant',
            'originBranch',
            'destinationBranch',
            'currentBranch',
        ]);

        $query->whereNotNull('origin_branch_id')
            ->whereNotNull('destination_branch_id')
            ->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
            ->where('status', CourierStatus::RECEIVED_AT_DESTINATION_BRANCH)
            ->where('destination_branch_id', $branchId);

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

    /**
     * Get completed transfers delivered to this branch
     */
    public function completed(Request $request)
    {
        $user = $request->user();
        $branchId = $this->getBranchScope($user);

        $query = Shipment::query()->with([
            'merchant',
            'originBranch',
            'destinationBranch',
        ]);

        $query->whereNotNull('origin_branch_id')
            ->whereNotNull('destination_branch_id')
            ->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
            ->where('status', CourierStatus::DELIVERED)
            ->where('destination_branch_id', $branchId);

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

        if ($request->filled('date_from')) {
            $query->whereDate('delivered_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('delivered_at', '<=', $request->date('date_to'));
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $results = $query->latest('delivered_at')->paginate($perPage);

        // Add transfer metadata
        $results->getCollection()->transform(function ($shipment) {
            $shipment->transfer_details = [
                'origin' => $shipment->originBranch?->name ?? 'Unknown',
                'destination' => $shipment->destinationBranch?->name ?? 'Unknown',
                'dispatched_at' => $shipment->dispatched_at,
                'received_at' => $shipment->received_at_destination_at,
                'delivered_at' => $shipment->delivered_at,
            ];
            return $shipment;
        });

        return ApiResponse::success($results);
    }

    /**
     * Get complete transfer history with all statuses
     */
    public function history(Request $request)
    {
        $user = $request->user();
        $branchId = $this->getBranchScope($user);

        $query = Shipment::query()->with([
            'merchant',
            'originBranch',
            'destinationBranch',
        ]);

        // Cross-branch transfers only
        $query->whereNotNull('origin_branch_id')
            ->whereNotNull('destination_branch_id')
            ->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
            ->whereIn('status', [
                CourierStatus::SORTED_FOR_TRANSFER,
                CourierStatus::IN_TRANSIT,
                CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
                CourierStatus::SORTED_FOR_DELIVERY,
                CourierStatus::DELIVERED,
            ])
            ->where(function ($q) use ($branchId) {
                // Show transfers that originate from or are destined for this branch
                $q->where('origin_branch_id', $branchId)
                    ->orWhere('destination_branch_id', $branchId);
            });

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

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        $results = $query->latest('updated_at')->paginate($perPage);

        // Add timeline events
        $results->getCollection()->transform(function ($shipment) {
            $trackingEvents = DB::table('tracking_events')
                ->where('shipment_id', $shipment->id)
                ->whereIn('status', [
                    CourierStatus::SORTED_FOR_TRANSFER,
                    CourierStatus::IN_TRANSIT,
                    CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
                    CourierStatus::SORTED_FOR_DELIVERY,
                    CourierStatus::DELIVERED,
                ])
                ->orderBy('created_at')
                ->get();

            $shipment->timeline = $trackingEvents->map(function ($event) {
                return [
                    'status' => $event->status,
                    'description' => $event->description,
                    'at' => $event->created_at,
                ];
            });

            $shipment->transfer_route = [
                'origin' => $shipment->originBranch?->name ?? 'Unknown',
                'destination' => $shipment->destinationBranch?->name ?? 'Unknown',
            ];

            return $shipment;
        });

        return ApiResponse::success($results);
    }

    /**
     * Dispatch transfers (bulk or single)
     */
    public function dispatch(Request $request)
    {
        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer'],
        ]);

        $user = $request->user();
        $branchId = $this->getBranchScope($user);
        $shipmentIds = array_values(array_unique(array_map(fn($id) => (int) $id, $data['shipment_ids'])));

        // Verify shipments are from this branch and ready for transfer
        $available = Shipment::query()
            ->whereIn('id', $shipmentIds)
            ->where('status', CourierStatus::SORTED_FOR_TRANSFER)
            ->where('origin_branch_id', $branchId)
            ->whereNotNull('destination_branch_id')
            ->where('destination_branch_id', '!=', $branchId)
            ->pluck('id')
            ->all();

        $skipped = array_diff($shipmentIds, $available);

        $result = $this->service->bulkDispatch($available, $user->id);

        foreach ($skipped as $id) {
            $result['skipped'][(int) $id] = 'Not available for dispatch or not cross-branch';
        }

        $ok = count($result['dispatched'] ?? []);
        $fail = count($result['skipped'] ?? []);

        return ApiResponse::success(
            $result,
            $fail === 0 ? "{$ok} dispatched." : "{$ok} dispatched, {$fail} skipped."
        );
    }

    /**
     * Receive transfer at this branch
     */
    public function receive(Request $request, Shipment $shipment)
    {
        $user = $request->user();
        $branchId = $this->getBranchScope($user);

        // Validate: this must be a cross-branch transfer destined for this branch
        if (
            (int) ($shipment->destination_branch_id ?? 0) !== $branchId ||
            (int) ($shipment->origin_branch_id ?? 0) === $branchId ||
            $shipment->status !== CourierStatus::IN_TRANSIT
        ) {
            return ApiResponse::error(
                'This transfer is not destined for your branch or not in transit.',
                403
            );
        }

        try {
            $result = $this->service->receiveAtDestination($shipment, $user->id);
            return ApiResponse::success($result, 'Transfer received and queued for last-mile delivery.');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Get branch scope for current user
     */
    private function getBranchScope($user): int
    {
        if ($user->isSuperAdmin() || $user->hasRole('main_admin')) {
            return 0; // No scope restriction
        }

        return (int) ($user->branch_id ?? 0);
    }
}