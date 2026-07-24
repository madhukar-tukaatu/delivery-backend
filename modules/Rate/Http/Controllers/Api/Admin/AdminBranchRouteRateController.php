<?php

namespace Modules\Rate\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Rate\Http\Requests\StoreBranchRouteRateRequest;
use Modules\Rate\Http\Requests\UpdateBranchRouteRateRequest;
use Modules\Rate\Services\PricingCacheService;

final class AdminBranchRouteRateController extends Controller
{
    public function __construct(
        private readonly PricingCacheService $cache
    ) {}

    public function branches(): JsonResponse
    {
        $branches = DB::table('branches')
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'code',
            ]);

        return response()->json([
            'success' => true,
            'data' => $branches,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('branch_route_rates as rates')
            ->join(
                'branches as pickup',
                'pickup.id',
                '=',
                'rates.pickup_branch_id'
            )
            ->join(
                'branches as delivery',
                'delivery.id',
                '=',
                'rates.delivery_branch_id'
            )
            ->select([
                'rates.id',
                'rates.pickup_branch_id',
                'rates.delivery_branch_id',
                'rates.base_rate',
                'rates.is_active',
                'rates.created_at',
                'rates.updated_at',
                'pickup.name as pickup_branch_name',
                'pickup.code as pickup_branch_code',
                'delivery.name as delivery_branch_name',
                'delivery.code as delivery_branch_code',
            ]);

        if ($request->filled('pickup_branch_id')) {
            $query->where(
                'rates.pickup_branch_id',
                (int) $request->query('pickup_branch_id')
            );
        }

        if ($request->filled('delivery_branch_id')) {
            $query->where(
                'rates.delivery_branch_id',
                (int) $request->query('delivery_branch_id')
            );
        }

        if ($request->has('is_active')) {
            $query->where(
                'rates.is_active',
                filter_var(
                    $request->query('is_active'),
                    FILTER_VALIDATE_BOOLEAN
                )
            );
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('pickup.name', 'like', "%{$search}%")
                    ->orWhere('pickup.code', 'like', "%{$search}%")
                    ->orWhere('delivery.name', 'like', "%{$search}%")
                    ->orWhere('delivery.code', 'like', "%{$search}%");
            });
        }

        $rates = $query
            ->orderBy('pickup.name')
            ->orderBy('delivery.name')
            ->paginate(
                min(200, max(1, (int) $request->integer('per_page', 30)))
            );

        return response()->json([
            'success' => true,
            'data' => $rates,
        ]);
    }

    public function matrix(): JsonResponse
    {
        $branches = DB::table('branches')
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'code',
            ]);

        $rates = DB::table('branch_route_rates')
            ->whereIn('pickup_branch_id', $branches->pluck('id'))
            ->whereIn('delivery_branch_id', $branches->pluck('id'))
            ->get([
                'id',
                'pickup_branch_id',
                'delivery_branch_id',
                'base_rate',
                'is_active',
            ])
            ->mapWithKeys(
                fn(object $rate): array => [
                    "{$rate->pickup_branch_id}:{$rate->delivery_branch_id}" =>
                        $rate,
                ]
            );

        return response()->json([
            'success' => true,
            'data' => [
                'branches' => $branches,
                'rates' => $rates,
            ],
        ]);
    }

    public function store(
        StoreBranchRouteRateRequest $request
    ): JsonResponse {
        $data = $request->validated();

        $result = DB::transaction(function () use ($data): array {
            $forwardId = $this->upsertRoute(
                pickupBranchId: (int) $data['pickup_branch_id'],
                deliveryBranchId: (int) $data['delivery_branch_id'],
                baseRate: (float) $data['base_rate'],
                isActive: (bool) $data['is_active']
            );

            $reverseId = null;

            if (
                (bool) $data['create_reverse_route'] &&
                (int) $data['pickup_branch_id'] !==
                    (int) $data['delivery_branch_id']
            ) {
                $reverseId = $this->upsertRoute(
                    pickupBranchId: (int) $data['delivery_branch_id'],
                    deliveryBranchId: (int) $data['pickup_branch_id'],
                    baseRate: (float) $data['reverse_base_rate'],
                    isActive: (bool) $data['is_active']
                );
            }

            return [
                'forward_id' => $forwardId,
                'reverse_id' => $reverseId,
            ];
        }, 3);

        $this->cache->forgetRoute(
            (int) $data['pickup_branch_id'],
            (int) $data['delivery_branch_id']
        );

        return response()->json([
            'success' => true,
            'message' => 'Branch route rate saved successfully.',
            'data' => $result,
        ], 201);
    }

    public function show(int $branchRouteRate): JsonResponse
    {
        $rate = DB::table('branch_route_rates')
            ->where('id', $branchRouteRate)
            ->first();

        if (!$rate) {
            return response()->json([
                'success' => false,
                'message' => 'Branch route rate not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $rate,
        ]);
    }

    public function update(
        UpdateBranchRouteRateRequest $request,
        int $branchRouteRate
    ): JsonResponse {
        $rate = DB::table('branch_route_rates')
            ->where('id', $branchRouteRate)
            ->first();

        if (!$rate) {
            return response()->json([
                'success' => false,
                'message' => 'Branch route rate not found.',
            ], 404);
        }

        $data = $request->validated();

        DB::table('branch_route_rates')
            ->where('id', $branchRouteRate)
            ->update([
                'base_rate' => $data['base_rate'],
                'is_active' => $data['is_active'],
                'updated_at' => now(),
            ]);

        $this->cache->forgetRoute(
            (int) $rate->pickup_branch_id,
            (int) $rate->delivery_branch_id
        );

        return response()->json([
            'success' => true,
            'message' => 'Branch route rate updated successfully.',
            'data' => DB::table('branch_route_rates')
                ->where('id', $branchRouteRate)
                ->first(),
        ]);
    }

    public function toggle(
        Request $request,
        int $branchRouteRate
    ): JsonResponse {
        $rate = DB::table('branch_route_rates')
            ->where('id', $branchRouteRate)
            ->first();

        if (!$rate) {
            return response()->json([
                'success' => false,
                'message' => 'Branch route rate not found.',
            ], 404);
        }

        $isActive = $request->boolean(
            'is_active',
            !(bool) $rate->is_active
        );

        DB::table('branch_route_rates')
            ->where('id', $branchRouteRate)
            ->update([
                'is_active' => $isActive,
                'updated_at' => now(),
            ]);

        $this->cache->forgetRoute(
            (int) $rate->pickup_branch_id,
            (int) $rate->delivery_branch_id
        );

        return response()->json([
            'success' => true,
            'message' => $isActive
                ? 'Branch route rate activated.'
                : 'Branch route rate deactivated.',
        ]);
    }

    public function destroy(int $branchRouteRate): JsonResponse
    {
        $rate = DB::table('branch_route_rates')
            ->where('id', $branchRouteRate)
            ->first();

        if (!$rate) {
            return response()->json([
                'success' => false,
                'message' => 'Branch route rate not found.',
            ], 404);
        }

        DB::table('branch_route_rates')
            ->where('id', $branchRouteRate)
            ->delete();

        $this->cache->forgetRoute(
            (int) $rate->pickup_branch_id,
            (int) $rate->delivery_branch_id
        );

        return response()->json([
            'success' => true,
            'message' => 'Branch route rate deleted successfully.',
        ]);
    }

    private function upsertRoute(
        int $pickupBranchId,
        int $deliveryBranchId,
        float $baseRate,
        bool $isActive
    ): int {
        $existing = DB::table('branch_route_rates')
            ->where('pickup_branch_id', $pickupBranchId)
            ->where('delivery_branch_id', $deliveryBranchId)
            ->first();

        if ($existing) {
            DB::table('branch_route_rates')
                ->where('id', $existing->id)
                ->update([
                    'base_rate' => $baseRate,
                    'is_active' => $isActive,
                    'updated_at' => now(),
                ]);

            return (int) $existing->id;
        }

        return DB::table('branch_route_rates')
            ->insertGetId([
                'pickup_branch_id' => $pickupBranchId,
                'delivery_branch_id' => $deliveryBranchId,
                'base_rate' => $baseRate,
                'is_active' => $isActive,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
