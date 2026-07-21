<?php

namespace Modules\Branch\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Branch\Models\CoverageLocation;
use Modules\Branch\Services\BranchVisibilityService;

class BranchController extends Controller
{
    public function parentOptions(Request $request, BranchVisibilityService $visibility): JsonResponse
    {
        $branches = $visibility->parentOptionsForCreate(
            $request->user(),
            $request->query('type')
        );

        return response()->json([
            'data' => $branches->map(function ($branch) {
                return [
                    'id' => $branch->id,
                    'parent_id' => $branch->parent_id,
                    'coverage_location_id' => $branch->coverage_location_id,
                    'type' => $branch->type,
                    'name' => $branch->name,
                    'code' => $branch->code,
                    'city' => $branch->city,
                    'area' => $branch->area,
                    'status' => $branch->status,
                    'label' => collect([
                        $branch->name,
                        $branch->type,
                        $branch->area,
                        $branch->city,
                    ])->filter()->join(' - '),
                ];
            })->values(),
        ]);
    }

    public function index(Request $request, BranchVisibilityService $visibility): JsonResponse
    {
        $visibleIds = $visibility->visibleBranchIds($request->user());

        $query = Branch::query()
            ->with([
                'parent:id,name,type,city,area',
                'manager:id,name,email',
                'coverageLocation:id,name,code,type,latitude,longitude,coverage_radius_km,status',
            ])
            ->withCount(['children', 'documents', 'agreements'])
            ->whereIn('id', $visibleIds)
            ->latest('id');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('legal_name', 'like', "%{$search}%")
                    ->orWhere('owner_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('area', 'like', "%{$search}%")
                    ->orWhere('office_city', 'like', "%{$search}%")
                    ->orWhere('office_area', 'like', "%{$search}%")
                    ->orWhere('office_address', 'like', "%{$search}%");
            });
        }

        if ($request->filled('q')) {
            $search = trim((string) $request->input('q'));

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('legal_name', 'like', "%{$search}%")
                    ->orWhere('owner_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('area', 'like', "%{$search}%")
                    ->orWhere('office_city', 'like', "%{$search}%")
                    ->orWhere('office_area', 'like', "%{$search}%")
                    ->orWhere('office_address', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->input('parent_id'));
        }

        if ($request->filled('coverage_location_id')) {
            $query->where('coverage_location_id', $request->input('coverage_location_id'));
        }

        if ($request->boolean('map')) {
            return response()->json([
                'data' => $query
                    ->whereNotNull('latitude')
                    ->whereNotNull('longitude')
                    ->limit(1000)
                    ->get(),
            ]);
        }

        if ($request->boolean('all')) {
            return response()->json([
                'data' => $query->limit(2000)->get(),
            ]);
        }

        $perPage = min((int) $request->input('per_page', 20), 100);

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request, BranchVisibilityService $visibility): JsonResponse
    {
        $data = $this->validatedData($request);

        $data['type'] = $this->normalizeBranchType($data['type']);

        $this->validateHierarchyAccess($request, $visibility, $data);

        $this->applyCoverageLocationToBranchPayload($data);

        $branch = DB::transaction(function () use ($data) {
            $data['status'] = $data['status'] ?? Branch::STATUS_DRAFT;

            $branch = Branch::create($data);

            if (!empty($data['coverage_location_id'])) {
                CoverageLocation::where('id', $data['coverage_location_id'])->update([
                    'branch_id' => $branch->id,
                ]);
            }

            return $branch;
        });

        return response()->json([
            'message' => 'Branch created successfully.',
            'data' => $branch->load([
                'parent',
                'coverageLocation',
                'manager',
                'documents',
                'agreements',
            ]),
        ], 201);
    }

    public function show(Request $request, Branch $branch, BranchVisibilityService $visibility): JsonResponse
    {
        $this->abortIfBranchNotVisible($request, $branch, $visibility);

        return response()->json([
            'data' => $branch->load([
                'parent',
                'children',
                'coverageLocation',
                'manager:id,name,email,phone',
                'documents',
                'agreements',
                'approver:id,name,email',
                'rejecter:id,name,email',
            ]),
        ]);
    }

    public function update(Request $request, Branch $branch, BranchVisibilityService $visibility): JsonResponse
    {
        $this->abortIfBranchNotVisible($request, $branch, $visibility);

        $data = $this->validatedData($request, $branch);

        $data['type'] = $this->normalizeBranchType($data['type'] ?? $branch->type);

        $this->validateHierarchyAccess($request, $visibility, $data, $branch);

        $oldCoverageLocationId = $branch->coverage_location_id;

        $this->applyCoverageLocationToBranchPayload($data);

        DB::transaction(function () use ($branch, $data, $oldCoverageLocationId) {
            $branch->update($data);

            if ($oldCoverageLocationId && (int) $oldCoverageLocationId !== (int) ($data['coverage_location_id'] ?? 0)) {
                CoverageLocation::where('id', $oldCoverageLocationId)
                    ->where('branch_id', $branch->id)
                    ->update(['branch_id' => null]);
            }

            if (!empty($data['coverage_location_id'])) {
                CoverageLocation::where('id', $data['coverage_location_id'])->update([
                    'branch_id' => $branch->id,
                ]);
            }
        });

        return response()->json([
            'message' => 'Branch updated successfully.',
            'data' => $branch->fresh()->load([
                'parent',
                'coverageLocation',
                'manager',
                'documents',
                'agreements',
            ]),
        ]);
    }

    public function destroy(Request $request, Branch $branch, BranchVisibilityService $visibility): JsonResponse
    {
        $this->abortIfBranchNotVisible($request, $branch, $visibility);

        if ($branch->children()->exists()) {
            return response()->json([
                'message' => 'This branch has child branches. Move or delete child branches first.',
            ], 422);
        }

        CoverageLocation::where('branch_id', $branch->id)->update([
            'branch_id' => null,
        ]);

        $branch->delete();

        return response()->json([
            'message' => 'Branch deleted successfully.',
        ]);
    }

    public function approve(Request $request, Branch $branch, BranchVisibilityService $visibility): JsonResponse
    {
        $this->abortIfBranchNotVisible($request, $branch, $visibility);

        $branch->update([
            'status' => Branch::STATUS_APPROVED,
            'approved_by' => $request->user()?->id,
            'approved_at' => now(),
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);

        return response()->json([
            'message' => 'Branch approved successfully.',
            'data' => $branch->fresh()->load(['parent', 'coverageLocation', 'manager']),
        ]);
    }

    public function activate(Request $request, Branch $branch, BranchVisibilityService $visibility): JsonResponse
    {
        $this->abortIfBranchNotVisible($request, $branch, $visibility);

        $errors = [];

        foreach ([
            'name',
            'code',
            'phone',
            'address',
            'latitude',
            'longitude',
            'coverage_location_id',
            'office_address',
            'office_latitude',
            'office_longitude',
        ] as $field) {
            if (blank($branch->{$field})) {
                $errors[$field] = ["The {$field} field is required before activation."];
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'message' => 'Branch cannot be activated yet.',
                'errors' => $errors,
            ], 422);
        }

        $branch->update([
            'status' => Branch::STATUS_ACTIVE,
            'approved_by' => $branch->approved_by ?: $request->user()?->id,
            'approved_at' => $branch->approved_at ?: now(),
        ]);

        return response()->json([
            'message' => 'Branch activated successfully.',
            'data' => $branch->fresh()->load(['parent', 'coverageLocation', 'manager']),
        ]);
    }

    public function suspend(Request $request, Branch $branch, BranchVisibilityService $visibility): JsonResponse
    {
        $this->abortIfBranchNotVisible($request, $branch, $visibility);

        $branch->update([
            'status' => Branch::STATUS_SUSPENDED,
            'rejection_reason' => $request->input('reason'),
        ]);

        return response()->json([
            'message' => 'Branch suspended successfully.',
            'data' => $branch->fresh()->load(['parent', 'coverageLocation', 'manager']),
        ]);
    }

    public function reject(Request $request, Branch $branch, BranchVisibilityService $visibility): JsonResponse
    {
        $this->abortIfBranchNotVisible($request, $branch, $visibility);

        $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $branch->update([
            'status' => Branch::STATUS_REJECTED,
            'rejected_by' => $request->user()?->id,
            'rejected_at' => now(),
            'rejection_reason' => $request->input('reason'),
        ]);

        return response()->json([
            'message' => 'Branch rejected successfully.',
            'data' => $branch->fresh()->load(['parent', 'coverageLocation', 'manager']),
        ]);
    }

    private function validatedData(Request $request, ?Branch $branch = null): array
    {
        $branchId = $branch?->id;

        return $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:branches,id'],
            'coverage_location_id' => ['nullable', 'integer', 'exists:coverage_locations,id'],

            'type' => ['required', Rule::in($this->allowedBranchTypes())],
            'name' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:80', Rule::unique('branches', 'code')->ignore($branchId)],

            'legal_name' => ['nullable', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],

            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'alternative_phone' => ['nullable', 'string', 'max:50'],

            'pan_vat_number' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'business_type' => ['nullable', 'string', 'max:255'],

            'status' => ['nullable', 'string', 'max:50'],

            'country' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'landmark' => ['nullable', 'string', 'max:255'],

            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'coverage_radius_km' => ['nullable', 'numeric', 'min:0'],

            'office_address' => ['nullable', 'string', 'max:1000'],
            'office_city' => ['nullable', 'string', 'max:255'],
            'office_area' => ['nullable', 'string', 'max:255'],
            'office_street' => ['nullable', 'string', 'max:255'],
            'office_landmark' => ['nullable', 'string', 'max:255'],
            'office_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'office_longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'covered_areas' => ['nullable', 'array'],
            'covered_areas.*' => ['nullable', 'string', 'max:255'],

            'opening_time' => ['nullable', 'date_format:H:i'],
            'closing_time' => ['nullable', 'date_format:H:i'],
            'operating_days' => ['nullable', 'array'],
            'daily_shipment_capacity' => ['nullable', 'integer', 'min:0'],

            'pickup_enabled' => ['boolean'],
            'delivery_enabled' => ['boolean'],
            'pod_enabled' => ['boolean'],
            'return_enabled' => ['boolean'],

            'manager_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
    }

    private function validateHierarchyAccess(
        Request $request,
        BranchVisibilityService $visibility,
        array &$data,
        ?Branch $existingBranch = null
    ): void {
        $user = $request->user();

        $type = $this->normalizeBranchType($data['type'] ?? $existingBranch?->type);
        $parentId = array_key_exists('parent_id', $data)
            ? $data['parent_id']
            : $existingBranch?->parent_id;

        $isCreating = !$existingBranch;

        $oldType = $existingBranch ? $this->normalizeBranchType($existingBranch->type) : null;
        $oldParentId = $existingBranch?->parent_id;

        $typeChanged = $existingBranch && $type !== $oldType;
        $parentChanged = $existingBranch && (int) ($parentId ?: 0) !== (int) ($oldParentId ?: 0);

        if ($this->isHeadBranchType($type)) {
            $data['parent_id'] = null;

            if (!$this->isSystemAdmin($user) && ($isCreating || $typeChanged)) {
                throw ValidationException::withMessages([
                    'type' => ['Only Super Admin, Main Admin, or Admin can create or convert a head/main branch.'],
                ]);
            }

            return;
        }

        if (!$parentId) {
            throw ValidationException::withMessages([
                'parent_id' => ['Parent branch is required for this branch type.'],
            ]);
        }

        if ($existingBranch && (int) $parentId === (int) $existingBranch->id) {
            throw ValidationException::withMessages([
                'parent_id' => ['A branch cannot be its own parent.'],
            ]);
        }

        if (!$isCreating && !$typeChanged && !$parentChanged) {
            return;
        }

        $parentOptions = $visibility->parentOptionsForCreate($user, $type);

        $allowedParentIds = $parentOptions
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (!in_array((int) $parentId, $allowedParentIds, true)) {
            throw ValidationException::withMessages([
                'parent_id' => ['You are not allowed to create or move a branch under this parent.'],
            ]);
        }

        $data['parent_id'] = (int) $parentId;
    }

    private function applyCoverageLocationToBranchPayload(array &$data): void
    {
        if (empty($data['coverage_location_id'])) {
            return;
        }

        $coverageLocation = CoverageLocation::find($data['coverage_location_id']);

        if (!$coverageLocation) {
            throw ValidationException::withMessages([
                'coverage_location_id' => ['Coverage location not found.'],
            ]);
        }

        $type = $this->normalizeBranchType($data['type'] ?? null);

        $isMainBranch = in_array($type, [
            Branch::TYPE_HEAD_BRANCH,
            Branch::TYPE_FRANCHISE_BRANCH,
            'main_branch',
            'branch',
        ], true);

        $isSubBranch = in_array($type, [
            Branch::TYPE_SUB_BRANCH,
            Branch::TYPE_PICKUP_POINT,
            Branch::TYPE_DELIVERY_HUB,
        ], true);

        if ($isMainBranch && $coverageLocation->type !== CoverageLocation::TYPE_MAIN_BRANCH_ZONE) {
            throw ValidationException::withMessages([
                'coverage_location_id' => ['Main/head/franchise branch must be assigned to a main branch coverage zone.'],
            ]);
        }

        if ($isSubBranch && $coverageLocation->type !== CoverageLocation::TYPE_SUB_BRANCH_ZONE) {
            throw ValidationException::withMessages([
                'coverage_location_id' => ['Sub branch, pickup point, or delivery hub must be assigned to a sub-branch coverage zone.'],
            ]);
        }

        /*
         * Old branch latitude/longitude/radius stay as assigned coverage point.
         * Existing pricing/routing continues working.
         */
        $data['latitude'] = $coverageLocation->latitude;
        $data['longitude'] = $coverageLocation->longitude;
        $data['coverage_radius_km'] = $coverageLocation->coverage_radius_km;

        /*
         * Auto-fill normal branch address from coverage point only if empty.
         */
        $data['country'] = $data['country'] ?? $coverageLocation->country;
        $data['province'] = $data['province'] ?? $coverageLocation->province;
        $data['district'] = $data['district'] ?? $coverageLocation->district;
        $data['city'] = $data['city'] ?? $coverageLocation->city;
        $data['area'] = $data['area'] ?? $coverageLocation->area;
        $data['address'] = $data['address'] ?? $coverageLocation->address;
        $data['landmark'] = $data['landmark'] ?? $coverageLocation->landmark;
    }

    private function abortIfBranchNotVisible(
        Request $request,
        Branch $branch,
        BranchVisibilityService $visibility
    ): void {
        $visibleIds = $visibility->visibleBranchIds($request->user());

        if (!in_array((int) $branch->id, array_map('intval', $visibleIds), true)) {
            abort(403, 'You are not allowed to access this branch.');
        }
    }

    private function allowedBranchTypes(): array
    {
        return array_values(array_unique([
            Branch::TYPE_HEAD_BRANCH,
            'main_branch',
            'branch',
            Branch::TYPE_FRANCHISE_BRANCH,
            Branch::TYPE_SUB_BRANCH,
            Branch::TYPE_PICKUP_POINT,
            Branch::TYPE_DELIVERY_HUB,
        ]));
    }

    private function normalizeBranchType(?string $type): ?string
    {
        if (!$type) {
            return null;
        }

        $type = strtolower(trim($type));

        return match ($type) {
            'main', 'main_branch', 'head', 'head_branch' => Branch::TYPE_HEAD_BRANCH,
            'normal_branch', 'regular_branch' => 'branch',
            'franchise', 'franchise_branch' => Branch::TYPE_FRANCHISE_BRANCH,
            'sub', 'subbranch', 'sub_branch' => Branch::TYPE_SUB_BRANCH,
            'pickup', 'pickup_point' => Branch::TYPE_PICKUP_POINT,
            'hub', 'delivery_hub' => Branch::TYPE_DELIVERY_HUB,
            default => $type,
        };
    }

    private function isHeadBranchType(?string $type): bool
    {
        return in_array($this->normalizeBranchType($type), [
            Branch::TYPE_HEAD_BRANCH,
            Branch::TYPE_FRANCHISE_BRANCH,
            'main_branch',
            'branch',
        ], true);
    }

    private function isSystemAdmin($user): bool
    {
        return $user->hasRole('super_admin')
            || $user->hasRole('main_admin')
            || $user->hasRole('admin');
    }
}