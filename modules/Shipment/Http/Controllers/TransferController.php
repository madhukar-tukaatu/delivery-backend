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
        $branchId = $this->scopedBranchId($request, $user, $isAdmin);
        $direction = $request->string('direction')->toString() ?: 'outbound';

        if (! in_array($direction, ['outbound', 'inbound'], true)) {
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
            $query->where('status', CourierStatus::IN_TRANSIT);

            $this->applyBranchScope($query, $branchId, [
                'destination_branch_id',
                'destination_sub_branch_id',
            ]);
        } else {
            $query->where('status', CourierStatus::SORTED_FOR_TRANSFER);

            $this->applyBranchScope($query, $branchId, [
                'origin_branch_id',
                'origin_sub_branch_id',
                'current_branch_id',
                'current_sub_branch_id',
            ]);
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
        $branchId = $this->scopedBranchId($request, $user, $isAdmin);

        $outboundQuery = Shipment::query()
            ->where('status', CourierStatus::SORTED_FOR_TRANSFER);
        $this->applyBranchScope($outboundQuery, $branchId, [
            'origin_branch_id',
            'origin_sub_branch_id',
            'current_branch_id',
            'current_sub_branch_id',
        ]);

        $inboundQuery = Shipment::query()
            ->where('status', CourierStatus::IN_TRANSIT);
        $this->applyBranchScope($inboundQuery, $branchId, [
            'destination_branch_id',
            'destination_sub_branch_id',
        ]);

        return ApiResponse::success([
            'outbound' => $outboundQuery->count(),
            'inbound' => $inboundQuery->count(),
        ]);
    }

    /**
     * Resolve the branch used for operational scope.
     *
     * Super/main admins may optionally filter by branch_id; branch users are
     * always restricted to their authenticated branch and cannot widen scope
     * through a query-string value.
     */
    private function scopedBranchId(Request $request, $user, bool $isAdmin): ?int
    {
        if ($isAdmin) {
            return $request->filled('branch_id')
                ? (int) $request->input('branch_id')
                : null;
        }

        return (int) ($user->branch_id ?? 0);
    }

    /**
     * Apply a branch/sub-branch scope to a transfer query.
     *
     * A zero branch id must produce no rows rather than accidentally exposing
     * every transfer to a user whose account is not assigned to a branch.
     */
    private function applyBranchScope($query, ?int $branchId, array $columns)
    {
        if ($branchId === null) {
            return $query;
        }

        if ($branchId < 1) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($branchId, $columns) {
            foreach ($columns as $index => $column) {
                if ($index === 0) {
                    $q->where($column, $branchId);
                } else {
                    $q->orWhere($column, $branchId);
                }
            }
        });
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

        $user = $request->user();
        $isAdmin = $user->isSuperAdmin() || $user->hasRole('main_admin');
        $branchId = $this->scopedBranchId($request, $user, $isAdmin);
        $shipmentIds = array_values(array_unique(array_map(
            static fn($id): int => (int) $id,
            $data['shipment_ids']
        )));

        /*
         * Filter the submitted IDs using the same origin/current-branch scope
         * as the outbound board. The service still re-checks status inside a
         * row lock, so this query is an authorization boundary, not a race-
         * condition workaround.
         */
        $availableQuery = Shipment::query()
            ->whereIn('id', $shipmentIds)
            ->where('status', CourierStatus::SORTED_FOR_TRANSFER);

        $this->applyBranchScope($availableQuery, $branchId, [
            'origin_branch_id',
            'origin_sub_branch_id',
            'current_branch_id',
            'current_sub_branch_id',
        ]);

        $availableIds = $availableQuery
            ->pluck('id')
            ->map(static fn($id): int => (int) $id)
            ->all();
        $availableLookup = array_fill_keys($availableIds, true);
        $skipped = [];

        foreach ($shipmentIds as $shipmentId) {
            if (! isset($availableLookup[$shipmentId])) {
                $skipped[$shipmentId] = $branchId === null
                    ? 'Shipment is not sorted for transfer.'
                    : 'Shipment is not available for transfer at your branch.';
            }
        }

        $result = $this->service->bulkDispatch($availableIds, $user->id);
        $result['skipped'] = $skipped + ($result['skipped'] ?? []);

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
        $user = $request->user();
        $isAdmin = $user->isSuperAdmin() || $user->hasRole('main_admin');
        $branchId = $this->scopedBranchId($request, $user, $isAdmin);

        if (
            $branchId !== null
            && ! $this->shipmentMatchesBranch(
                $shipment,
                $branchId,
                ['destination_branch_id', 'destination_sub_branch_id']
            )
        ) {
            return ApiResponse::error(
                'This shipment is not addressed to your branch.',
                403
            );
        }

        return ApiResponse::success(
            $this->service->receiveAtDestination($shipment, $user->id),
            'Shipment received at destination branch and queued for delivery.'
        );
    }

    private function shipmentMatchesBranch(
        Shipment $shipment,
        ?int $branchId,
        array $columns
    ): bool {
        if ($branchId === null || $branchId < 1) {
            return false;
        }

        foreach ($columns as $column) {
            if ((int) ($shipment->{$column} ?? 0) === $branchId) {
                return true;
            }
        }

        return false;
    }
}
