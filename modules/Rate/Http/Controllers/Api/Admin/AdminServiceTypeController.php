<?php

namespace Modules\Rate\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Rate\Http\Requests\StoreServiceTypeRequest;
use Modules\Rate\Http\Requests\UpdateServiceTypeRequest;
use Modules\Rate\Services\PricingCacheService;

final class AdminServiceTypeController extends Controller
{
    public function __construct(
        private readonly PricingCacheService $cache
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = DB::table('service_types');

        if ($search = trim((string) $request->query('search'))) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where(
                'is_active',
                filter_var(
                    $request->query('is_active'),
                    FILTER_VALIDATE_BOOLEAN
                )
            );
        }

        $items = $query
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(
                min(100, max(1, (int) $request->integer('per_page', 20)))
            );

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function store(
        StoreServiceTypeRequest $request
    ): JsonResponse {
        $data = $request->validated();

        $id = DB::table('service_types')->insertGetId([
            'name' => $data['name'],
            'code' => $data['code'],
            'description' => $data['description'] ?? null,
            'estimated_hours' => $data['estimated_hours'],
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->cache->forgetServiceType($data['code']);

        return response()->json([
            'success' => true,
            'message' => 'Service type created successfully.',
            'data' => DB::table('service_types')->where('id', $id)->first(),
        ], 201);
    }

    public function show(int $serviceType): JsonResponse
    {
        $item = DB::table('service_types')
            ->where('id', $serviceType)
            ->first();

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Service type not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $item,
        ]);
    }

    public function update(
        UpdateServiceTypeRequest $request,
        int $serviceType
    ): JsonResponse {
        $existing = DB::table('service_types')
            ->where('id', $serviceType)
            ->first();

        if (!$existing) {
            return response()->json([
                'success' => false,
                'message' => 'Service type not found.',
            ], 404);
        }

        $data = $request->validated();

        $isReferenced = DB::table('pricing_quotes')
            ->where('service_type_id', $serviceType)
            ->exists();

        if (
            $isReferenced &&
            (string) $existing->code !== (string) $data['code']
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'The code cannot be changed because this service type is already used by pricing quotes.',
            ], 422);
        }

        DB::table('service_types')
            ->where('id', $serviceType)
            ->update([
                'name' => $data['name'],
                'code' => $data['code'],
                'description' => $data['description'] ?? null,
                'estimated_hours' => $data['estimated_hours'],
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => $data['is_active'],
                'updated_at' => now(),
            ]);

        $this->cache->forgetServiceType((string) $existing->code);
        $this->cache->forgetServiceType((string) $data['code']);

        return response()->json([
            'success' => true,
            'message' => 'Service type updated successfully.',
            'data' => DB::table('service_types')
                ->where('id', $serviceType)
                ->first(),
        ]);
    }

    public function toggle(
        Request $request,
        int $serviceType
    ): JsonResponse {
        $item = DB::table('service_types')
            ->where('id', $serviceType)
            ->first();

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Service type not found.',
            ], 404);
        }

        $isActive = $request->boolean(
            'is_active',
            !(bool) $item->is_active
        );

        DB::table('service_types')
            ->where('id', $serviceType)
            ->update([
                'is_active' => $isActive,
                'updated_at' => now(),
            ]);

        $this->cache->forgetServiceType((string) $item->code);

        return response()->json([
            'success' => true,
            'message' => $isActive
                ? 'Service type activated.'
                : 'Service type deactivated.',
        ]);
    }

    public function destroy(int $serviceType): JsonResponse
    {
        $item = DB::table('service_types')
            ->where('id', $serviceType)
            ->first();

        if (!$item) {
            return response()->json([
                'success' => false,
                'message' => 'Service type not found.',
            ], 404);
        }

        if (in_array($item->code, ['standard', 'express', 'same_day'], true)) {
            return response()->json([
                'success' => false,
                'message' =>
                    'Core service types cannot be deleted. Deactivate the service instead.',
            ], 422);
        }

        $isReferenced = DB::table('pricing_quotes')
            ->where('service_type_id', $serviceType)
            ->exists();

        if ($isReferenced) {
            return response()->json([
                'success' => false,
                'message' =>
                    'This service type is already used by pricing quotes and cannot be deleted.',
            ], 422);
        }

        DB::table('service_types')
            ->where('id', $serviceType)
            ->delete();

        $this->cache->forgetServiceType((string) $item->code);

        return response()->json([
            'success' => true,
            'message' => 'Service type deleted successfully.',
        ]);
    }
}
