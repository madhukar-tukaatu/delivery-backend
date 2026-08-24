<?php

declare(strict_types=1);

namespace Modules\Branch\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Branch\Models\CoverageLocation;
use RuntimeException;

class AdminCoverageLocationController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Index
    |--------------------------------------------------------------------------
    */

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
            $search = trim((string) $request->input('q'));

            $query->where(function ($q) use ($search) {
                $like = "%{$search}%";

                $q->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('province', 'like', $like)
                    ->orWhere('district', 'like', $like)
                    ->orWhere('city', 'like', $like)
                    ->orWhere('area', 'like', $like)
                    ->orWhere('address', 'like', $like);
            });
        }

        if ($request->boolean('all')) {
            return response()->json([
                'data' => $query->limit(2000)->get(),
            ]);
        }

        $perPage = min(
            max((int) $request->input('per_page', 20), 1),
            100
        );

        return response()->json(
            $query->paginate($perPage)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Map
    |--------------------------------------------------------------------------
    */

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
            $query->where(
                'status',
                $request->input('status')
            );
        }

        return response()->json([
            'data' => $query->limit(2000)->get(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Parent Main Branch Options
    |--------------------------------------------------------------------------
    |
    | GET:
    |
    | /api/v1/admin/coverage-locations/parent-options?q=chit
    |
    | Optional:
    |
    | ?exclude_id=7
    |
    |--------------------------------------------------------------------------
    */

    public function parentOptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        /*
         * Support both:
         *
         * exclude_id
         * excludeId
         *
         * so old and new frontend code both work.
         */
        $excludeId = $request->query(
            'exclude_id',
            $request->query('excludeId')
        );

        /*
         * Don't query for extremely short searches.
         */
        if (mb_strlen($search) < 2) {
            return response()->json([
                'data' => [],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Main branch zones only
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | Do NOT use CoverageLocationType here.
        |
        | We use the constants defined by CoverageLocation itself.
        |
        |--------------------------------------------------------------------------
        */

        $mainBranchType = CoverageLocation::TYPE_MAIN_BRANCH_ZONE;

        $query = CoverageLocation::query()
            ->where('type', $mainBranchType);

        /*
        |--------------------------------------------------------------------------
        | Exclude current location
        |--------------------------------------------------------------------------
        */

        if (
            $excludeId !== null &&
            $excludeId !== ''
        ) {
            $query->where(
                'id',
                '!=',
                (int) $excludeId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Active main zones
        |--------------------------------------------------------------------------
        |
        | Accept NULL status for legacy records.
        |--------------------------------------------------------------------------
        */

        $query->where(function ($q) {
            $q->whereNull('status')
                ->orWhere(
                    'status',
                    CoverageLocation::STATUS_ACTIVE
                );
        });

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        $query->where(function ($q) use ($search) {
            $like = "%{$search}%";

            $q->where('name', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('district', 'like', $like)
                ->orWhere('province', 'like', $like)
                ->orWhere('area', 'like', $like)
                ->orWhere('address', 'like', $like);
        });

        /*
        |--------------------------------------------------------------------------
        | Fetch
        |--------------------------------------------------------------------------
        */

        $locations = $query
            ->orderBy('name')
            ->limit(30)
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Normalize
        |--------------------------------------------------------------------------
        */

        $data = $locations
            ->map(function (CoverageLocation $location) {
                return [
                    'id' => (int) $location->id,

                    'name' => (string) (
                        $location->name ?? ''
                    ),

                    'code' => (string) (
                        $location->code ?? ''
                    ),

                    'type' => (string) (
                        $location->type ?? ''
                    ),

                    'status' => $location->status,

                    'latitude' => $location->latitude !== null
                        ? (float) $location->latitude
                        : null,

                    'longitude' => $location->longitude !== null
                        ? (float) $location->longitude
                        : null,

                    'country' => (string) (
                        $location->country ?? ''
                    ),

                    'province' => (string) (
                        $location->province ?? ''
                    ),

                    'district' => (string) (
                        $location->district ?? ''
                    ),

                    'city' => (string) (
                        $location->city ?? ''
                    ),

                    'area' => (string) (
                        $location->area ?? ''
                    ),
                ];
            })
            ->values();

        return response()->json([
            'data' => $data,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Conversion Options
    |--------------------------------------------------------------------------
    */

    public function conversionOptions(
        CoverageLocation $coverageLocation
    ): JsonResponse {
        if (
            $coverageLocation->type !==
            CoverageLocation::TYPE_MAIN_BRANCH_ZONE
        ) {
            return response()->json([
                'message' =>
                    'Only a main branch zone can be converted to a sub-branch zone.',
            ], 422);
        }

        $coverageLocation->load([
            'children:id,name,code,type,parent_id,latitude,longitude,coverage_radius_km,status,province,district,city,area,address',
            'assignedBranches:id,name,code,type,status,coverage_location_id',
        ]);

        $mainZones = CoverageLocation::query()
            ->where(
                'type',
                CoverageLocation::TYPE_MAIN_BRANCH_ZONE
            )
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhere(
                        'status',
                        CoverageLocation::STATUS_ACTIVE
                    );
            })
            ->where(
                'id',
                '!=',
                $coverageLocation->id
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'code',
                'type',
                'status',
                'latitude',
                'longitude',
                'coverage_radius_km',
                'province',
                'district',
                'city',
                'area',
                'address',
            ]);

        return response()->json([
            'data' => [
                'current' => [
                    'id' => $coverageLocation->id,
                    'name' => $coverageLocation->name,
                    'code' => $coverageLocation->code,
                    'type' => $coverageLocation->type,
                    'latitude' => $coverageLocation->latitude,
                    'longitude' => $coverageLocation->longitude,
                    'coverage_radius_km' =>
                        $coverageLocation->coverage_radius_km,
                    'children_count' =>
                        $coverageLocation->children->count(),
                    'assigned_branches_count' =>
                        $coverageLocation->assignedBranches->count(),
                ],

                'children' =>
                    $coverageLocation->children,

                'assigned_branches' =>
                    $coverageLocation->assignedBranches,

                'main_zones' =>
                    $mainZones,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Convert Main → Sub Branch
    |--------------------------------------------------------------------------
    */

    public function convertToSubBranch(
        Request $request,
        CoverageLocation $coverageLocation
    ): JsonResponse {
        /*
        |--------------------------------------------------------------------------
        | Validate source
        |--------------------------------------------------------------------------
        */

        if (
            $coverageLocation->type !==
            CoverageLocation::TYPE_MAIN_BRANCH_ZONE
        ) {
            return response()->json([
                'message' =>
                    'Only a main branch zone can be converted to a sub-branch zone.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate request
        |--------------------------------------------------------------------------
        */

        $data = $this->validateConversionData(
            $request,
            $coverageLocation
        );

        /*
        |--------------------------------------------------------------------------
        | Lock source
        |--------------------------------------------------------------------------
        */

        $source = CoverageLocation::query()
            ->whereKey($coverageLocation->id)
            ->lockForUpdate()
            ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Prevent self parent
        |--------------------------------------------------------------------------
        */

        if (
            (int) $data['parent_id'] ===
            (int) $source->id
        ) {
            return response()->json([
                'message' =>
                    'A coverage location cannot become a child of itself.',

                'errors' => [
                    'parent_id' => [
                        'Select another main coverage location.',
                    ],
                ],
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate new parent
        |--------------------------------------------------------------------------
        */

        $newParent = CoverageLocation::query()
            ->whereKey($data['parent_id'])
            ->where(
                'type',
                CoverageLocation::TYPE_MAIN_BRANCH_ZONE
            )
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhere(
                        'status',
                        CoverageLocation::STATUS_ACTIVE
                    );
            })
            ->first();

        if (! $newParent) {
            return response()->json([
                'message' =>
                    'Selected parent must be an active main coverage location.',

                'errors' => [
                    'parent_id' => [
                        'Selected parent main coverage location is invalid.',
                    ],
                ],
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Assigned branches
        |--------------------------------------------------------------------------
        */

        if ($source->assignedBranches()->exists()) {
            return response()->json([
                'message' =>
                    'This main coverage location has assigned branches. Remove or transfer those assignments before converting it.',

                'errors' => [
                    'assigned_branches' => [
                        'Transfer assigned branches before conversion.',
                    ],
                ],
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Preserve location fields
        |--------------------------------------------------------------------------
        */

        $locationData = [
            'country' => array_key_exists(
                'country',
                $data
            )
                ? $data['country']
                : $source->country,

            'province' => array_key_exists(
                'province',
                $data
            )
                ? $data['province']
                : $source->province,

            'district' => array_key_exists(
                'district',
                $data
            )
                ? $data['district']
                : $source->district,

            'city' => array_key_exists(
                'city',
                $data
            )
                ? $data['city']
                : $source->city,

            'area' => array_key_exists(
                'area',
                $data
            )
                ? $data['area']
                : $source->area,

            'street' => array_key_exists(
                'street',
                $data
            )
                ? $data['street']
                : $source->street,

            'address' => array_key_exists(
                'address',
                $data
            )
                ? $data['address']
                : $source->address,

            'landmark' => array_key_exists(
                'landmark',
                $data
            )
                ? $data['landmark']
                : $source->landmark,
        ];

        /*
        |--------------------------------------------------------------------------
        | Fallback to original values
        |--------------------------------------------------------------------------
        */

        foreach ($locationData as $field => $value) {
            if (
                $value === null ||
                trim((string) $value) === ''
            ) {
                $locationData[$field] =
                    $source->{$field};
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Required location fields
        |--------------------------------------------------------------------------
        */

        $requiredLocationFields = [
            'country',
            'province',
            'district',
            'city',
            'area',
            'address',
        ];

        foreach ($requiredLocationFields as $field) {
            if (
                $locationData[$field] === null ||
                trim((string) $locationData[$field]) === ''
            ) {
                return response()->json([
                    'message' =>
                        "The source coverage location does not have a valid {$field}.",

                    'errors' => [
                        $field => [
                            "The {$field} is required because the database column cannot be null.",
                        ],
                    ],
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Generate new sub branch code
        |--------------------------------------------------------------------------
        */

        $newCode = $this->generateSubBranchCode(
            $data['name'],
            $newParent
        );

        /*
        |--------------------------------------------------------------------------
        | Transaction
        |--------------------------------------------------------------------------
        */

        $result = DB::transaction(
            function () use (
                $request,
                $source,
                $newParent,
                $data,
                $newCode,
                $locationData
            ) {
                $parent = CoverageLocation::query()
                    ->whereKey($newParent->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                |--------------------------------------------------------------------------
                | Re-check source
                |--------------------------------------------------------------------------
                */

                if (
                    $source->type !==
                    CoverageLocation::TYPE_MAIN_BRANCH_ZONE
                ) {
                    throw new RuntimeException(
                        'Coverage location is no longer a main branch zone.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Transfer existing children
                |--------------------------------------------------------------------------
                */

                $childrenTransferred = $source
                    ->children()
                    ->update([
                        'parent_id' => $parent->id,
                        'updated_at' => now(),
                        'updated_by' =>
                            $request->user()?->id,
                    ]);

                /*
                |--------------------------------------------------------------------------
                | Convert current location
                |--------------------------------------------------------------------------
                */

                $source->update([
                    'type' =>
                        CoverageLocation::TYPE_SUB_BRANCH_ZONE,

                    'parent_id' =>
                        $parent->id,

                    'name' =>
                        $data['name'],

                    'code' =>
                        $newCode,

                    'latitude' =>
                        (float) $data['latitude'],

                    'longitude' =>
                        (float) $data['longitude'],

                    'coverage_radius_km' =>
                        (float) $data['coverage_radius_km'],

                    'country' =>
                        $locationData['country'],

                    'province' =>
                        $locationData['province'],

                    'district' =>
                        $locationData['district'],

                    'city' =>
                        $locationData['city'],

                    'area' =>
                        $locationData['area'],

                    'street' =>
                        $locationData['street'],

                    'address' =>
                        $locationData['address'],

                    'landmark' =>
                        $locationData['landmark'],

                    'updated_by' =>
                        $request->user()?->id,
                ]);

                return [
                    'children_transferred' =>
                        $childrenTransferred,

                    'location' =>
                        $source->fresh([
                            'parent',
                            'children',
                            'branch',
                            'assignedBranches',
                        ]),

                    'parent' =>
                        $parent->fresh([
                            'children',
                        ]),
                ];
            }
        );

        return response()->json([
            'message' =>
                'Main coverage location converted to sub-branch successfully.',

            'data' => [
                'location' =>
                    $result['location'],

                'parent' =>
                    $result['parent'],

                'children_transferred' =>
                    $result['children_transferred'],
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    */

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedData($request);

        /*
        |--------------------------------------------------------------------------
        | Main branch cannot have parent
        |--------------------------------------------------------------------------
        */

        if (
            $data['type'] ===
            CoverageLocation::TYPE_MAIN_BRANCH_ZONE
        ) {
            $data['parent_id'] = null;
        }

        /*
        |--------------------------------------------------------------------------
        | Sub branch requires parent
        |--------------------------------------------------------------------------
        */

        if (
            $data['type'] ===
            CoverageLocation::TYPE_SUB_BRANCH_ZONE &&
            empty($data['parent_id'])
        ) {
            return response()->json([
                'message' =>
                    'Parent main branch zone is required for sub-branch zone.',

                'errors' => [
                    'parent_id' => [
                        'Parent main branch zone is required for sub-branch zone.',
                    ],
                ],
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate parent
        |--------------------------------------------------------------------------
        */

        if (
            $data['type'] ===
            CoverageLocation::TYPE_SUB_BRANCH_ZONE
        ) {
            $parentExists = CoverageLocation::query()
                ->whereKey($data['parent_id'])
                ->where(
                    'type',
                    CoverageLocation::TYPE_MAIN_BRANCH_ZONE
                )
                ->where(function ($query) {
                    $query->whereNull('status')
                        ->orWhere(
                            'status',
                            CoverageLocation::STATUS_ACTIVE
                        );
                })
                ->exists();

            if (! $parentExists) {
                return response()->json([
                    'message' =>
                        'Selected parent must be an active main branch zone.',

                    'errors' => [
                        'parent_id' => [
                            'Selected parent must be an active main branch zone.',
                        ],
                    ],
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Generate code
        |--------------------------------------------------------------------------
        */

        $data['code'] = $this->generateCode(
            $data['name'],
            $data['type'],
            $data['parent_id'] ?? null
        );

        /*
        |--------------------------------------------------------------------------
        | Audit
        |--------------------------------------------------------------------------
        */

        $data['created_by'] =
            $request->user()?->id;

        $data['updated_by'] =
            $request->user()?->id;

        /*
        |--------------------------------------------------------------------------
        | Create
        |--------------------------------------------------------------------------
        */

        $location = CoverageLocation::create($data);

        return response()->json([
            'message' =>
                'Coverage location created successfully.',

            'data' =>
                $location->fresh([
                    'parent',
                    'children',
                    'branch',
                    'assignedBranches',
                ]),
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | Show
    |--------------------------------------------------------------------------
    */

    public function show(
        CoverageLocation $coverageLocation
    ): JsonResponse {
        return response()->json([
            'data' =>
                $coverageLocation->load([
                    'parent',
                    'children',
                    'branch',
                    'assignedBranches',
                ]),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        CoverageLocation $coverageLocation
    ): JsonResponse {
        $data = $this->validatedData(
            $request,
            $coverageLocation
        );

        /*
        |--------------------------------------------------------------------------
        | Main branch cannot have parent
        |--------------------------------------------------------------------------
        */

        if (
            $data['type'] ===
            CoverageLocation::TYPE_MAIN_BRANCH_ZONE
        ) {
            $data['parent_id'] = null;
        }

        /*
        |--------------------------------------------------------------------------
        | Sub branch requires parent
        |--------------------------------------------------------------------------
        */

        if (
            $data['type'] ===
            CoverageLocation::TYPE_SUB_BRANCH_ZONE &&
            empty($data['parent_id'])
        ) {
            return response()->json([
                'message' =>
                    'Parent main branch zone is required for sub-branch zone.',

                'errors' => [
                    'parent_id' => [
                        'Parent main branch zone is required for sub-branch zone.',
                    ],
                ],
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Cannot be own parent
        |--------------------------------------------------------------------------
        */

        if (
            ! empty($data['parent_id']) &&
            (int) $data['parent_id'] ===
            (int) $coverageLocation->id
        ) {
            return response()->json([
                'message' =>
                    'Coverage location cannot be its own parent.',

                'errors' => [
                    'parent_id' => [
                        'Coverage location cannot be its own parent.',
                    ],
                ],
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate parent
        |--------------------------------------------------------------------------
        */

        if (
            $data['type'] ===
            CoverageLocation::TYPE_SUB_BRANCH_ZONE
        ) {
            $validParent = CoverageLocation::query()
                ->whereKey($data['parent_id'])
                ->where(
                    'type',
                    CoverageLocation::TYPE_MAIN_BRANCH_ZONE
                )
                ->where(function ($query) {
                    $query->whereNull('status')
                        ->orWhere(
                            'status',
                            CoverageLocation::STATUS_ACTIVE
                        );
                })
                ->exists();

            if (! $validParent) {
                return response()->json([
                    'message' =>
                        'Sub-branch must belong to an active main branch zone.',

                    'errors' => [
                        'parent_id' => [
                            'Selected parent is not a valid active main branch.',
                        ],
                    ],
                ], 422);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Detect changes
        |--------------------------------------------------------------------------
        */

        $nameChanged =
            trim((string) $coverageLocation->name) !==
            trim((string) $data['name']);

        $parentChanged =
            (int) ($coverageLocation->parent_id ?? 0) !==
            (int) ($data['parent_id'] ?? 0);

        $typeChanged =
            $coverageLocation->type !==
            $data['type'];

        /*
        |--------------------------------------------------------------------------
        | Regenerate code if hierarchy/name/type changed
        |--------------------------------------------------------------------------
        */

        if (
            $nameChanged ||
            $parentChanged ||
            $typeChanged
        ) {
            $data['code'] = $this->generateCode(
                $data['name'],
                $data['type'],
                $data['parent_id'] ?? null,
                $coverageLocation->id
            );
        } else {
            unset($data['code']);
        }

        /*
        |--------------------------------------------------------------------------
        | Audit
        |--------------------------------------------------------------------------
        */

        $data['updated_by'] =
            $request->user()?->id;

        /*
        |--------------------------------------------------------------------------
        | Update
        |--------------------------------------------------------------------------
        */

        $coverageLocation->update($data);

        return response()->json([
            'message' =>
                'Coverage location updated successfully.',

            'data' =>
                $coverageLocation->fresh([
                    'parent',
                    'children',
                    'branch',
                    'assignedBranches',
                ]),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Destroy
    |--------------------------------------------------------------------------
    */

    public function destroy(
        CoverageLocation $coverageLocation
    ): JsonResponse {
        if (
            $coverageLocation
                ->children()
                ->exists()
        ) {
            return response()->json([
                'message' =>
                    'This location has sub-branch zones. Remove or transfer them first.',
            ], 422);
        }

        if (
            $coverageLocation
                ->assignedBranches()
                ->exists()
        ) {
            return response()->json([
                'message' =>
                    'This location is assigned to branch/franchise. Remove assignment first.',
            ], 422);
        }

        $coverageLocation->delete();

        return response()->json([
            'message' =>
                'Coverage location deleted successfully.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Generate Code
    |--------------------------------------------------------------------------
    */

    private function generateCode(
        string $name,
        string $type,
        ?int $parentId = null,
        ?int $ignoreId = null
    ): string {
        $abbrMap = [
            'kathmandu' => 'KTM',
            'lalitpur' => 'LTP',
            'bhaktapur' => 'BKT',
            'kavrepalanchok' => 'KVR',
            'sindhupalchok' => 'SPL',
            'sindhuli' => 'SDL',
            'ramechhap' => 'RMP',
            'dolakha' => 'DLK',
            'nuwakot' => 'NWT',
            'rasuwa' => 'RSW',
            'dhading' => 'DHD',
            'makwanpur' => 'MKP',
            'chitwan' => 'CTW',
            'kaski' => 'PKR',
            'pokhara' => 'PKR',
            'tanahun' => 'TNH',
            'syangja' => 'SYJ',
            'lamjung' => 'LMJ',
            'gorkha' => 'GRK',
            'manang' => 'MNG',
            'mustang' => 'MST',
            'myagdi' => 'MYG',
            'parbat' => 'PRB',
            'baglung' => 'BGL',
            'nawalpur' => 'NWP',
            'morang' => 'MRG',
            'biratnagar' => 'BTN',
            'sunsari' => 'SNS',
            'dhankuta' => 'DNK',
            'terhathum' => 'TRT',
            'sankhuwasabha' => 'SKS',
            'bhojpur' => 'BJP',
            'solukhumbu' => 'SLK',
            'okhaldhunga' => 'OKH',
            'khotang' => 'KHT',
            'udayapur' => 'UDP',
            'taplejung' => 'TPL',
            'panchthar' => 'PCT',
            'ilam' => 'ILM',
            'jhapa' => 'JHP',
            'saptari' => 'SPT',
            'siraha' => 'SRH',
            'dhanusha' => 'DNS',
            'mahottari' => 'MHT',
            'sarlahi' => 'SRL',
            'rautahat' => 'RTH',
            'bara' => 'BRA',
            'parsa' => 'PRS',
            'birgunj' => 'BGJ',
            'rupandehi' => 'RPD',
            'butwal' => 'BTW',
            'bhairahawa' => 'BHW',
            'kapilvastu' => 'KPV',
            'palpa' => 'PLP',
            'arghakhanchi' => 'AGK',
            'gulmi' => 'GLM',
            'dang' => 'DNG',
            'banke' => 'BNK',
            'nepalgunj' => 'NPG',
            'bardiya' => 'BRD',
            'rolpa' => 'RLP',
            'pyuthan' => 'PYT',
            'rukum east' => 'RKE',
            'surkhet' => 'SKT',
            'birendranagar' => 'BRN',
            'dailekh' => 'DLH',
            'jajarkot' => 'JJK',
            'dolpa' => 'DLP',
            'jumla' => 'JML',
            'mugu' => 'MGU',
            'humla' => 'HML',
            'kalikot' => 'KLK',
            'salyan' => 'SLN',
            'rukum west' => 'RKW',
            'kailali' => 'KLL',
            'dhangadhi' => 'DHG',
            'kanchanpur' => 'KCP',
            'mahendranagar' => 'MHN',
            'dadeldhura' => 'DDH',
            'baitadi' => 'BTD',
            'darchula' => 'DCL',
            'achham' => 'ACH',
            'doti' => 'DTI',
            'bajura' => 'BJR',
            'bajhang' => 'BJH',
        ];

        $key = strtolower(trim($name));

        $firstWord = strtolower(
            preg_split('/\s+/', $key)[0] ?? ''
        );

        /*
        |--------------------------------------------------------------------------
        | Abbreviation
        |--------------------------------------------------------------------------
        */

        $abbr = $abbrMap[$key]
            ?? $abbrMap[$firstWord]
            ?? strtoupper(
                substr(
                    preg_replace(
                        '/[^a-z]/i',
                        '',
                        $firstWord
                    ),
                    0,
                    3
                )
            );

        if ($abbr === '') {
            $abbr = 'LOC';
        }

        /*
        |--------------------------------------------------------------------------
        | Words
        |--------------------------------------------------------------------------
        */

        $words = array_values(
            array_filter(
                array_map(
                    static fn ($word) =>
                        strtoupper(
                            preg_replace(
                                '/[^A-Za-z0-9]/',
                                '',
                                $word
                            )
                        ),
                    preg_split(
                        '/\s+/',
                        trim($name)
                    )
                )
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Main Branch
        |--------------------------------------------------------------------------
        */

        if (
            $type ===
            CoverageLocation::TYPE_MAIN_BRANCH_ZONE
        ) {
            $baseCode = "TUK-{$abbr}-MAIN";
        } else {
            /*
            |--------------------------------------------------------------------------
            | Sub Branch
            |--------------------------------------------------------------------------
            */

            $parent = null;

            if ($parentId !== null) {
                $parent = CoverageLocation::query()
                    ->select([
                        'id',
                        'name',
                        'code',
                        'type',
                    ])
                    ->find($parentId);
            }

            if ($parent) {
                $parentCode = strtoupper(
                    (string) $parent->code
                );

                $parts = explode(
                    '-',
                    $parentCode
                );

                $parentAbbr =
                    $parts[1] ?? $abbr;
            } else {
                $parentAbbr = $abbr;
            }

            /*
            |--------------------------------------------------------------------------
            | Build suffix
            |--------------------------------------------------------------------------
            */

            $suffix = implode(
                '-',
                array_slice($words, 1)
            );

            if ($suffix === '') {
                $suffix =
                    $words[0] ?? 'SUB';
            }

            $baseCode =
                "TUK-{$parentAbbr}-SUB-{$suffix}";
        }

        /*
        |--------------------------------------------------------------------------
        | Ensure unique code
        |--------------------------------------------------------------------------
        */

        $code = $baseCode;
        $counter = 2;

        while (
            CoverageLocation::query()
                ->where('code', $code)
                ->when(
                    $ignoreId !== null,
                    fn ($query) =>
                        $query->where(
                            'id',
                            '!=',
                            $ignoreId
                        )
                )
                ->exists()
        ) {
            $code =
                "{$baseCode}-{$counter}";

            $counter++;
        }

        return $code;
    }

    /*
    |--------------------------------------------------------------------------
    | Generate Conversion Code
    |--------------------------------------------------------------------------
    */

    private function generateSubBranchCode(
        string $name,
        CoverageLocation $parent
    ): string {
        return $this->generateCode(
            $name,
            CoverageLocation::TYPE_SUB_BRANCH_ZONE,
            $parent->id
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Conversion Validation
    |--------------------------------------------------------------------------
    */

    private function validateConversionData(
        Request $request,
        CoverageLocation $coverageLocation
    ): array {
        return $request->validate([
            'parent_id' => [
                'required',
                'integer',

                Rule::exists(
                    'coverage_locations',
                    'id'
                )->where(
                    function ($query) use ($coverageLocation) {
                        $query
                            ->where(
                                'type',
                                CoverageLocation::TYPE_MAIN_BRANCH_ZONE
                            )
                            ->where(function ($q) {
                                $q->whereNull('status')
                                    ->orWhere(
                                        'status',
                                        CoverageLocation::STATUS_ACTIVE
                                    );
                            })
                            ->where(
                                'id',
                                '!=',
                                $coverageLocation->id
                            );
                    }
                ),
            ],

            'name' => [
                'required',
                'string',
                'max:150',
            ],

            'latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            'coverage_radius_km' => [
                'required',
                'numeric',
                'min:0.1',
                'max:100',
            ],

            'country' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'province' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'district' => [
                'sometimes',
                'nullable',
                'string',
                'max:100',
            ],

            'city' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
            ],

            'area' => [
                'sometimes',
                'nullable',
                'string',
                'max:120',
            ],

            'street' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],

            'address' => [
                'sometimes',
                'nullable',
                'string',
                'max:1000',
            ],

            'landmark' => [
                'sometimes',
                'nullable',
                'string',
                'max:150',
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Normal Validation
    |--------------------------------------------------------------------------
    */

    private function validatedData(
        Request $request,
        ?CoverageLocation $coverageLocation = null
    ): array {
        return $request->validate([
            'name' => [
                'required',
                'string',
                'max:150',
            ],

            /*
            |--------------------------------------------------------------------------
            | Code
            |--------------------------------------------------------------------------
            |
            | Accepted for backward compatibility.
            | Backend always generates the real code.
            |
            */

            'code' => [
                'nullable',
                'string',
                'max:80',
            ],

            'type' => [
                'required',

                Rule::in([
                    CoverageLocation::TYPE_MAIN_BRANCH_ZONE,
                    CoverageLocation::TYPE_SUB_BRANCH_ZONE,
                ]),
            ],

            'parent_id' => [
                'nullable',
                'integer',
                'exists:coverage_locations,id',
            ],

            'branch_id' => [
                'nullable',
                'integer',
                'exists:branches,id',
            ],

            /*
            |--------------------------------------------------------------------------
            | Location
            |--------------------------------------------------------------------------
            */

            'country' => [
                'nullable',
                'string',
                'max:100',
            ],

            'province' => [
                'nullable',
                'string',
                'max:100',
            ],

            'district' => [
                'nullable',
                'string',
                'max:100',
            ],

            'city' => [
                'nullable',
                'string',
                'max:120',
            ],

            'area' => [
                'nullable',
                'string',
                'max:120',
            ],

            'street' => [
                'nullable',
                'string',
                'max:150',
            ],

            'address' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'landmark' => [
                'nullable',
                'string',
                'max:150',
            ],

            /*
            |--------------------------------------------------------------------------
            | Coordinates
            |--------------------------------------------------------------------------
            */

            'latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            'coverage_radius_km' => [
                'required',
                'numeric',
                'min:0.1',
                'max:100',
            ],

            /*
            |--------------------------------------------------------------------------
            | Other
            |--------------------------------------------------------------------------
            */

            'is_hq_managed' => [
                'nullable',
                'boolean',
            ],

            'status' => [
                'required',

                Rule::in([
                    CoverageLocation::STATUS_ACTIVE,
                    CoverageLocation::STATUS_INACTIVE,
                ]),
            ],

            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);
    }
}