<?php

namespace Modules\Branch\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Branch\Models\CoverageLocation;

class AdminCoverageLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CoverageLocation::query()
            ->with([
                'parent:id,name,code,type',
                'children:id,name,code,type,parent_id,latitude,longitude,coverage_radius_km,status',
                'branch:id,name,code,type,status',
                'assignedBranches:id,name,code,type,status,coverage_location_id,office_latitude,office_longitude,office_address',
            ])
            ->latest('id');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('parent_id')) {
            $query->where('parent_id', $request->input('parent_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));

            $query->where(function ($x) use ($q) {
                $x->where('name', 'like', "%{$q}%")
                    ->orWhere('code', 'like', "%{$q}%")
                    ->orWhere('province', 'like', "%{$q}%")
                    ->orWhere('district', 'like', "%{$q}%")
                    ->orWhere('city', 'like', "%{$q}%")
                    ->orWhere('area', 'like', "%{$q}%")
                    ->orWhere('address', 'like', "%{$q}%");
            });
        }

        if ($request->boolean('all')) {
            return response()->json([
                'data' => $query->limit(2000)->get(),
            ]);
        }

        $perPage = min((int) $request->input('per_page', 20), 100);

        return response()->json($query->paginate($perPage));
    }

    public function map(Request $request): JsonResponse
    {
        $query = CoverageLocation::query()
            ->with([
                'parent:id,name,code,type',
                'children:id,name,code,type,parent_id,latitude,longitude,coverage_radius_km,status',
                'branch:id,name,code,type,status,office_latitude,office_longitude,office_address',
                'assignedBranches:id,name,code,type,status,coverage_location_id,office_latitude,office_longitude,office_address',
            ])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->orderBy('type')
            ->orderBy('name');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json([
            'data' => $query->limit(2000)->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedData($request);

        if ($data['type'] === CoverageLocation::TYPE_MAIN_BRANCH_ZONE) {
            $data['parent_id'] = null;
        }

        if ($data['type'] === CoverageLocation::TYPE_SUB_BRANCH_ZONE && empty($data['parent_id'])) {
            return response()->json([
                'message' => 'Parent main branch zone is required for sub-branch zone.',
                'errors' => [
                    'parent_id' => ['Parent main branch zone is required for sub-branch zone.'],
                ],
            ], 422);
        }

        $data['created_by'] = $request->user()?->id;
        $data['updated_by'] = $request->user()?->id;

        $location = CoverageLocation::create($data);

        return response()->json([
            'message' => 'Coverage location created successfully.',
            'data' => $location->fresh(['parent', 'children', 'branch', 'assignedBranches']),
        ], 201);
    }

    public function show(CoverageLocation $coverageLocation): JsonResponse
    {
        return response()->json([
            'data' => $coverageLocation->load([
                'parent',
                'children',
                'branch',
                'assignedBranches',
            ]),
        ]);
    }

    public function update(Request $request, CoverageLocation $coverageLocation): JsonResponse
    {
        $data = $this->validatedData($request, $coverageLocation);

        if ($data['type'] === CoverageLocation::TYPE_MAIN_BRANCH_ZONE) {
            $data['parent_id'] = null;
        }

        if ($data['type'] === CoverageLocation::TYPE_SUB_BRANCH_ZONE && empty($data['parent_id'])) {
            return response()->json([
                'message' => 'Parent main branch zone is required for sub-branch zone.',
                'errors' => [
                    'parent_id' => ['Parent main branch zone is required for sub-branch zone.'],
                ],
            ], 422);
        }

        if (!empty($data['parent_id']) && (int) $data['parent_id'] === (int) $coverageLocation->id) {
            return response()->json([
                'message' => 'Coverage location cannot be its own parent.',
                'errors' => [
                    'parent_id' => ['Coverage location cannot be its own parent.'],
                ],
            ], 422);
        }

        $data['updated_by'] = $request->user()?->id;

        $coverageLocation->update($data);

        return response()->json([
            'message' => 'Coverage location updated successfully.',
            'data' => $coverageLocation->fresh(['parent', 'children', 'branch', 'assignedBranches']),
        ]);
    }

    public function destroy(CoverageLocation $coverageLocation): JsonResponse
    {
        if ($coverageLocation->children()->exists()) {
            return response()->json([
                'message' => 'This location has sub-branch zones. Remove them first.',
            ], 422);
        }

        if ($coverageLocation->assignedBranches()->exists()) {
            return response()->json([
                'message' => 'This location is assigned to branch/franchise. Remove assignment first.',
            ], 422);
        }

        $coverageLocation->delete();

        return response()->json([
            'message' => 'Coverage location deleted successfully.',
        ]);
    }

    private function validatedData(Request $request, ?CoverageLocation $coverageLocation = null): array
    {
        $ignoreId = $coverageLocation?->id;

        return $request->validate([
            'name' => ['required', 'string', 'max:150'],

            'code' => [
                'required',
                'string',
                'max:80',
                Rule::unique('coverage_locations', 'code')->ignore($ignoreId),
            ],

            'type' => [
                'required',
                Rule::in([
                    CoverageLocation::TYPE_MAIN_BRANCH_ZONE,
                    CoverageLocation::TYPE_SUB_BRANCH_ZONE,
                ]),
            ],

            'parent_id' => ['nullable', 'integer', 'exists:coverage_locations,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],

            'country' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:120'],
            'area' => ['nullable', 'string', 'max:120'],
            'street' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:1000'],
            'landmark' => ['nullable', 'string', 'max:150'],

            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'coverage_radius_km' => ['required', 'numeric', 'min:0.1', 'max:100'],

            'is_hq_managed' => ['nullable', 'boolean'],

            'status' => [
                'required',
                Rule::in([
                    CoverageLocation::STATUS_ACTIVE,
                    CoverageLocation::STATUS_INACTIVE,
                ]),
            ],

            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}