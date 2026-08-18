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

    public function coverageLocations(): JsonResponse
    {
        $locations = DB::table('coverage_locations')
            ->where('status', 'active')
            ->where('type', 'main_branch_zone')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'code',
                'latitude',
                'longitude',
            ]);

        return response()->json([
            'success' => true,
            'data' => $locations,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('branch_route_rates as rates')
            ->join(
                'coverage_locations as pickup',
                'pickup.id',
                '=',
                'rates.pickup_coverage_location_id'
            )
            ->join(
                'coverage_locations as delivery',
                'delivery.id',
                '=',
                'rates.delivery_coverage_location_id'
            )
            ->leftJoin(
                'branch_transfer_routes as tr',
                'tr.id',
                '=',
                'rates.branch_transfer_route_id'
            )
            ->select([
                'rates.id',
                'rates.pickup_coverage_location_id',
                'rates.delivery_coverage_location_id',
                'rates.branch_transfer_route_id',
                'rates.base_rate',
                'rates.is_active',
                'rates.express_enabled',
                'rates.same_day_enabled',
                'rates.created_at',
                'rates.updated_at',
                'pickup.name as pickup_branch_name',
                'pickup.code as pickup_branch_code',
                'delivery.name as delivery_branch_name',
                'delivery.code as delivery_branch_code',
                'tr.route_code as transfer_route_code',
                'tr.name as transfer_route_name',
            ]);

        if ($request->filled('pickup_coverage_location_id')) {
            $query->where(
                'rates.pickup_coverage_location_id',
                (int) $request->query('pickup_coverage_location_id')
            );
        }

        if ($request->filled('delivery_coverage_location_id')) {
            $query->where(
                'rates.delivery_coverage_location_id',
                (int) $request->query('delivery_coverage_location_id')
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
        $locations = DB::table('coverage_locations')
            ->where('status', 'active')
            ->where('type', 'main_branch_zone')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'latitude', 'longitude']);

        $rates = DB::table('branch_route_rates')
            ->whereIn('pickup_coverage_location_id', $locations->pluck('id'))
            ->whereIn('delivery_coverage_location_id', $locations->pluck('id'))
            ->get([
                'id',
                'pickup_coverage_location_id',
                'delivery_coverage_location_id',
                'base_rate',
                'is_active',
            ])
            ->mapWithKeys(
                fn(object $rate): array => [
                    "{$rate->pickup_coverage_location_id}:{$rate->delivery_coverage_location_id}" =>
                        $rate,
                ]
            );

        return response()->json([
            'success' => true,
            'data' => [
                'branches' => $locations,
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
                pickupLocationId: (int) $data['pickup_coverage_location_id'],
                deliveryLocationId: (int) $data['delivery_coverage_location_id'],
                baseRate: (float) $data['base_rate'],
                isActive: (bool) $data['is_active'],
                expressEnabled: (bool) $data['express_enabled'],
                sameDayEnabled: (bool) $data['same_day_enabled'],
                transferRouteId: isset($data['branch_transfer_route_id'])
                    ? (int) $data['branch_transfer_route_id']
                    : null
            );

            $reverseId = null;

            if (
                (bool) $data['create_reverse_route'] &&
                (int) $data['pickup_coverage_location_id'] !==
                    (int) $data['delivery_coverage_location_id']
            ) {
                $reverseId = $this->upsertRoute(
                    pickupLocationId: (int) $data['delivery_coverage_location_id'],
                    deliveryLocationId: (int) $data['pickup_coverage_location_id'],
                    baseRate: (float) $data['reverse_base_rate'],
                    isActive: (bool) $data['is_active'],
                    expressEnabled: (bool) $data['express_enabled'],
                    sameDayEnabled: (bool) $data['same_day_enabled']
                );
            }

            return [
                'forward_id' => $forwardId,
                'reverse_id' => $reverseId,
            ];
        }, 3);

        $this->cache->forgetRoute(
            (int) $data['pickup_coverage_location_id'],
            (int) $data['delivery_coverage_location_id']
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
                'base_rate'                 => $data['base_rate'],
                'is_active'                 => $data['is_active'],
                'express_enabled'           => $data['express_enabled'],
                'same_day_enabled'          => $data['same_day_enabled'],
                'branch_transfer_route_id'  => $data['branch_transfer_route_id'] ?? null,
                'updated_at'                => now(),
            ]);

        $this->cache->forgetRoute(
            (int) $rate->pickup_coverage_location_id,
            (int) $rate->delivery_coverage_location_id
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
            (int) $rate->pickup_coverage_location_id,
            (int) $rate->delivery_coverage_location_id
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
            (int) $rate->pickup_coverage_location_id,
            (int) $rate->delivery_coverage_location_id
        );

        return response()->json([
            'success' => true,
            'message' => 'Branch route rate deleted successfully.',
        ]);
    }

    private function upsertRoute(
        int $pickupLocationId,
        int $deliveryLocationId,
        float $baseRate,
        bool $isActive,
        bool $expressEnabled = true,
        bool $sameDayEnabled = true,
        ?int $transferRouteId = null
    ): int {
        $existing = DB::table('branch_route_rates')
            ->where('pickup_coverage_location_id', $pickupLocationId)
            ->where('delivery_coverage_location_id', $deliveryLocationId)
            ->first();

        if ($existing) {
            DB::table('branch_route_rates')
                ->where('id', $existing->id)
                ->update([
                    'base_rate'                => $baseRate,
                    'is_active'                => $isActive,
                    'express_enabled'          => $expressEnabled,
                    'same_day_enabled'         => $sameDayEnabled,
                    'branch_transfer_route_id' => $transferRouteId,
                    'updated_at'               => now(),
                ]);

            return (int) $existing->id;
        }

        return DB::table('branch_route_rates')
            ->insertGetId([
                'pickup_coverage_location_id'  => $pickupLocationId,
                'delivery_coverage_location_id'=> $deliveryLocationId,
                'branch_transfer_route_id'     => $transferRouteId,
                'base_rate'                    => $baseRate,
                'is_active'                    => $isActive,
                'express_enabled'              => $expressEnabled,
                'same_day_enabled'             => $sameDayEnabled,
                'created_at'                   => now(),
                'updated_at'                   => now(),
            ]);
    }
}
