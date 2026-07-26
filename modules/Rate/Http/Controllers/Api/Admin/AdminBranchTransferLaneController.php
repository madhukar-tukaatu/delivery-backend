<?php

namespace Modules\Rate\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdminBranchTransferLaneController extends Controller
{
    public function branches(): JsonResponse
    {
        $query = DB::table('branches');

        if (Schema::hasColumn('branches', 'parent_id')) {
            $query->whereNull('parent_id');
        }

        if (Schema::hasColumn('branches', 'is_active')) {
            $query->where('is_active', true);
        } elseif (Schema::hasColumn('branches', 'status')) {
            $query->whereIn('status', ['active', 'approved']);
        }

        return response()->json([
            'success' => true,
            'data' => $query
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->baseQuery();

        if ($request->filled('from_branch_id')) {
            $query->where(
                'lanes.from_branch_id',
                (int) $request->input('from_branch_id')
            );
        }

        if ($request->filled('to_branch_id')) {
            $query->where(
                'lanes.to_branch_id',
                (int) $request->input('to_branch_id')
            );
        }

        if ($request->filled('service_type')) {
            $query->where(
                'lanes.service_type',
                (string) $request->input('service_type')
            );
        }

        if ($request->has('is_active')) {
            $query->where(
                'lanes.is_active',
                $request->boolean('is_active')
            );
        }

        $perPage = min(
            100,
            max(1, (int) $request->input('per_page', 25))
        );

        return response()->json([
            'success' => true,
            'data' => $query
                ->orderBy('from_branch.name')
                ->orderBy('to_branch.name')
                ->paginate($perPage),
        ]);
    }

    public function show(int $branchTransferLane): JsonResponse
    {
        $lane = $this->baseQuery()
            ->where('lanes.id', $branchTransferLane)
            ->first();

        if (!$lane) {
            return response()->json([
                'success' => false,
                'message' => 'Transfer lane not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $lane,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);
        $this->ensureNoConflict($validated);

        $id = DB::table('branch_transfer_lanes')
            ->insertGetId([
                ...$validated,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Transfer lane created successfully.',
            'data' => $this->baseQuery()
                ->where('lanes.id', $id)
                ->first(),
        ], 201);
    }

    public function update(
        Request $request,
        int $branchTransferLane
    ): JsonResponse {
        $existing = DB::table('branch_transfer_lanes')
            ->where('id', $branchTransferLane)
            ->first();

        if (!$existing) {
            return response()->json([
                'success' => false,
                'message' => 'Transfer lane not found.',
            ], 404);
        }

        $validated = $this->validatePayload(
            $request,
            $branchTransferLane
        );

        $this->ensureNoConflict(
            $validated,
            $branchTransferLane
        );

        DB::table('branch_transfer_lanes')
            ->where('id', $branchTransferLane)
            ->update([
                ...$validated,
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Transfer lane updated successfully.',
            'data' => $this->baseQuery()
                ->where('lanes.id', $branchTransferLane)
                ->first(),
        ]);
    }

    public function toggle(int $branchTransferLane): JsonResponse
    {
        $lane = DB::table('branch_transfer_lanes')
            ->where('id', $branchTransferLane)
            ->first();

        if (!$lane) {
            return response()->json([
                'success' => false,
                'message' => 'Transfer lane not found.',
            ], 404);
        }

        $newStatus = !(bool) $lane->is_active;

        DB::table('branch_transfer_lanes')
            ->where('id', $branchTransferLane)
            ->update([
                'is_active' => $newStatus,
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => $newStatus
                ? 'Transfer lane activated.'
                : 'Transfer lane deactivated.',
            'data' => [
                'id' => $branchTransferLane,
                'is_active' => $newStatus,
            ],
        ]);
    }

    public function destroy(int $branchTransferLane): JsonResponse
    {
        $lane = DB::table('branch_transfer_lanes')
            ->where('id', $branchTransferLane)
            ->first();

        if (!$lane) {
            return response()->json([
                'success' => false,
                'message' => 'Transfer lane not found.',
            ], 404);
        }

        if (
            Schema::hasTable('pricing_quote_transfer_lanes') &&
            DB::table('pricing_quote_transfer_lanes')
                ->where('branch_transfer_lane_id', $branchTransferLane)
                ->exists()
        ) {
            return response()->json([
                'success' => false,
                'message' =>
                    'This transfer lane is already used by a pricing quote. Deactivate it instead of deleting it.',
            ], 422);
        }

        DB::table('branch_transfer_lanes')
            ->where('id', $branchTransferLane)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Transfer lane deleted successfully.',
        ]);
    }

    private function validatePayload(
        Request $request,
        ?int $ignoreId = null
    ): array {
        $validated = $request->validate([
            'from_branch_id' => [
                'required',
                'integer',
                'exists:branches,id',
                'different:to_branch_id',
                Rule::unique('branch_transfer_lanes')
                    ->where(fn ($query) => $query
                        ->where(
                            'to_branch_id',
                            $request->input('to_branch_id')
                        )
                        ->where(
                            'service_type',
                            $request->input('service_type', 'standard')
                        ))
                    ->ignore($ignoreId),
            ],
            'to_branch_id' => [
                'required',
                'integer',
                'exists:branches,id',
            ],
            'service_type' => [
                'required',
                Rule::in(['standard', 'express', 'same_day']),
            ],
            'transport_mode' => [
                'nullable',
                'string',
                'max:50',
            ],
            'distance_km' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'estimated_hours' => [
                'required',
                'integer',
                'min:0',
                'max:10000',
            ],
            'priority' => [
                'nullable',
                'integer',
                'min:0',
                'max:100000',
            ],
            'is_bidirectional' => [
                'nullable',
                'boolean',
            ],
            'is_active' => [
                'nullable',
                'boolean',
            ],
        ]);

        return [
            'from_branch_id' => (int) $validated['from_branch_id'],
            'to_branch_id' => (int) $validated['to_branch_id'],
            'service_type' => (string) $validated['service_type'],
            'transport_mode' => $validated['transport_mode'] ?? null,
            'distance_km' => isset($validated['distance_km'])
                ? (float) $validated['distance_km']
                : null,
            'estimated_hours' =>
                (int) $validated['estimated_hours'],
            'priority' => (int) ($validated['priority'] ?? 100),
            'is_bidirectional' =>
                (bool) ($validated['is_bidirectional'] ?? false),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }

    private function ensureNoConflict(
        array $data,
        ?int $ignoreId = null
    ): void {
        $reverse = DB::table('branch_transfer_lanes')
            ->where('from_branch_id', $data['to_branch_id'])
            ->where('to_branch_id', $data['from_branch_id'])
            ->where('service_type', $data['service_type'])
            ->when(
                $ignoreId !== null,
                fn ($query) => $query->where('id', '!=', $ignoreId)
            )
            ->first();

        if (
            $reverse &&
            (
                (bool) $data['is_bidirectional'] ||
                (bool) $reverse->is_bidirectional
            )
        ) {
            throw ValidationException::withMessages([
                'is_bidirectional' => [
                    'A reverse lane already covers this direction. Keep only one bidirectional lane or use two directional lanes.',
                ],
            ]);
        }
    }

    private function baseQuery(): Builder
    {
        return DB::table('branch_transfer_lanes as lanes')
            ->join(
                'branches as from_branch',
                'from_branch.id',
                '=',
                'lanes.from_branch_id'
            )
            ->join(
                'branches as to_branch',
                'to_branch.id',
                '=',
                'lanes.to_branch_id'
            )
            ->select([
                'lanes.*',
                'from_branch.name as from_branch_name',
                'from_branch.code as from_branch_code',
                'to_branch.name as to_branch_name',
                'to_branch.code as to_branch_code',
            ]);
    }
}
