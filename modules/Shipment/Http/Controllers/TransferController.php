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
     * 
     * OUTBOUND: sorted_for_transfer status at current_branch or origin_branch
     * INBOUND: in_transit or received_at_destination statuses for this branch
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

        if ($direction === 'inbound') {
            // INBOUND: transfers coming TO this branch
            // Status: in_transit (on the way) or received_at_destination_branch (arrived)
            $query->whereIn('status', [
                CourierStatus::IN_TRANSIT,
                CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
                CourierStatus::RECEIVED_AT_TRANSIT_HUB,
                CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
            ]);
            
            // Only filter by branch if branch scope is not admin
            if ($branchId !== 0) {
                $query->where(function ($q) use ($branchId) {
                    // Received at destination OR currently at this branch as intermediate hub
                    $q->where('destination_branch_id', $branchId)
                        ->orWhere('current_branch_id', $branchId);
                });
            }
        } else {
            // OUTBOUND: sorted_for_transfer status at this branch
            // These are shipments that originated at this branch and are ready to be transferred
            $query->where('status', CourierStatus::SORTED_FOR_TRANSFER);
            
            // Only filter by branch if branch scope is not admin
            if ($branchId !== 0) {
                $query->where(function ($q) use ($branchId) {
                    // Either origin_branch or current_branch (in case transfers are queued here)
                    $q->where('origin_branch_id', $branchId)
                        ->orWhere('current_branch_id', $branchId);
                });
            }
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
     * 
     * Shows counts for this branch:
     * - outbound: ready to leave this branch (sorted_for_transfer)
     * - in_transit: transfers in transit TO this branch (in_transit)
     * - received: transfers received and ready for delivery (received_at_destination_branch)
     * - completed: transfers successfully delivered (delivered)
     */
    public function stats(Request $request)
    {
        $user = $request->user();
        $branchId = $this->getBranchScope($user);

        // Outbound: ready to send from this branch
        $outbound = Shipment::query()
            ->where('status', CourierStatus::SORTED_FOR_TRANSFER);
        
        if ($branchId !== 0) {
            $outbound->where(function ($q) use ($branchId) {
                $q->where('origin_branch_id', $branchId)
                    ->orWhere('current_branch_id', $branchId);
            });
        }
        $outbound = $outbound->count();

        // In Transit: transfers in transit to this branch
        $inTransit = Shipment::query()
            ->whereIn('status', [
                CourierStatus::IN_TRANSIT,
                CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
                CourierStatus::RECEIVED_AT_TRANSIT_HUB,
            ]);
        
        if ($branchId !== 0) {
            $inTransit->where(function ($q) use ($branchId) {
                $q->where('destination_branch_id', $branchId)
                    ->orWhere('current_branch_id', $branchId);
            });
        }
        $inTransit = $inTransit->count();

        // Received: arrived at this branch, ready for last-mile delivery
        $received = Shipment::query()
            ->where('status', CourierStatus::RECEIVED_AT_DESTINATION_BRANCH);
        
        if ($branchId !== 0) {
            $received->where('destination_branch_id', $branchId);
        }
        $received = $received->count();

        // Completed: delivered to final destination
        $completed = Shipment::query()
            ->where('status', CourierStatus::DELIVERED);
        
        if ($branchId !== 0) {
            $completed->where('destination_branch_id', $branchId);
        }
        $completed = $completed->count();

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

        $outbound = Shipment::query()
            ->where('status', CourierStatus::SORTED_FOR_TRANSFER);
        
        if ($branchId !== 0) {
            $outbound->where(function ($q) use ($branchId) {
                $q->where('origin_branch_id', $branchId)
                    ->orWhere('current_branch_id', $branchId);
            });
        }
        $outbound = $outbound->count();

        $inbound = Shipment::query()
            ->whereIn('status', [
                CourierStatus::IN_TRANSIT,
                CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
                CourierStatus::RECEIVED_AT_TRANSIT_HUB,
            ]);
        
        if ($branchId !== 0) {
            $inbound->where(function ($q) use ($branchId) {
                $q->where('destination_branch_id', $branchId)
                    ->orWhere('current_branch_id', $branchId);
            });
        }
        $inbound = $inbound->count();

        return ApiResponse::success([
            'outbound' => $outbound,
            'inbound' => $inbound,
        ]);
    }

    /**
     * Get received transfers at this branch
     * Note: Only shows CROSS-BRANCH transfers (origin != destination)
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

        // ONLY cross-branch transfers received at this branch
        $query->where('status', CourierStatus::RECEIVED_AT_DESTINATION_BRANCH)
            ->where('destination_branch_id', $branchId)
            ->whereColumn('origin_branch_id', '!=', 'destination_branch_id'); // Cross-branch only!

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
     * Note: Only shows CROSS-BRANCH transfers (origin != destination), not same-branch local deliveries
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

        // ONLY cross-branch transfers (delivered to this branch from another branch)
        $query->where('status', CourierStatus::DELIVERED)
            ->where('destination_branch_id', $branchId)
            ->whereColumn('origin_branch_id', '!=', 'destination_branch_id'); // Cross-branch only!

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

        // All transfer-related statuses
        $query->whereIn('status', [
            CourierStatus::SORTED_FOR_TRANSFER,
            CourierStatus::IN_TRANSIT,
            CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
            CourierStatus::SORTED_FOR_DELIVERY,
            CourierStatus::DELIVERED,
            CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
            CourierStatus::RECEIVED_AT_TRANSIT_HUB,
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

        // Verify shipments are ready for transfer (sorted_for_transfer or similar statuses)
        $available = Shipment::query()
            ->whereIn('id', $shipmentIds)
            ->where(function ($q) {
                // Accept shipments that are sorted for transfer, or picked up and ready
                $q->where('status', CourierStatus::SORTED_FOR_TRANSFER)
                    ->orWhere('status', CourierStatus::SORTED_FOR_DELIVERY)
                    ->orWhere('status', CourierStatus::PICKED_UP)
                    ->orWhere('status', CourierStatus::RECEIVED_AT_ORIGIN_BRANCH);
            })
            ->where(function ($q) use ($branchId) {
                if ($branchId !== 0) {
                    $q->where('origin_branch_id', $branchId)
                        ->orWhere('current_branch_id', $branchId);
                }
            })
            ->pluck('id')
            ->all();

        $skipped = array_diff($shipmentIds, $available);

        $result = $this->service->bulkDispatch($available, $user->id);

        foreach ($skipped as $id) {
            $result['skipped'][(int) $id] = 'Shipment not ready for dispatch (must be sorted first)';
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

        // Validate: this must be a transfer destined for this branch and in transit
        if (
            (int) ($shipment->destination_branch_id ?? 0) !== $branchId ||
            !in_array($shipment->status, [
                CourierStatus::IN_TRANSIT,
                CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
            ])
        ) {
            return ApiResponse::error(
                'This shipment is not destined for your branch or not in transit.',
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