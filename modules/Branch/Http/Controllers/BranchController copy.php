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
use Illuminate\Support\Str;
use Modules\Branch\Services\BranchTeamProvisioner;
use Modules\Branch\Services\BranchAccountInvitationService;

class BranchControllerCopy extends Controller
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

    // public function store(Request $request, BranchVisibilityService $visibility): JsonResponse
    // {
    //     $data = $this->validatedData($request);

    //     $data['type'] = $this->normalizeBranchType($data['type']);

    //     $this->validateHierarchyAccess($request, $visibility, $data);

    //     $this->applyCoverageLocationToBranchPayload($data);

    //     $branch = DB::transaction(function () use ($data) {
    //         $data['status'] = $data['status'] ?? Branch::STATUS_DRAFT;

    //         $branch = Branch::create($data);

    //         if (!empty($data['coverage_location_id'])) {
    //             CoverageLocation::where('id', $data['coverage_location_id'])->update([
    //                 'branch_id' => $branch->id,
    //             ]);
    //         }

    //         return $branch;
    //     });

    //     return response()->json([
    //         'message' => 'Branch created successfully.',
    //         'data' => $branch->load([
    //             'parent',
    //             'coverageLocation',
    //             'manager',
    //             'documents',
    //             'agreements',
    //         ]),
    //     ], 201);
    // }

    public function store(
        Request $request,
        BranchVisibilityService $visibility,
        BranchTeamProvisioner $teamProvisioner
    ): JsonResponse {
        $data = $this->validatedData($request);

        $data['type'] = $this->normalizeBranchType(
            $data['type']
        );

        if (
            $data['type'] === Branch::TYPE_FRANCHISE_BRANCH &&
            blank($data['email'] ?? null)
        ) {
            throw ValidationException::withMessages([
                'email' => [
                    'A manager email is required for a franchise branch account.',
                ],
            ]);
        }

        $this->validateHierarchyAccess(
            $request,
            $visibility,
            $data
        );

        $this->applyCoverageLocationToBranchPayload(
            $data
        );

        /*
     * The branch form currently hides name and code,
     * so generate them safely when they are empty.
     */
        $data['name'] = trim(
            (string) ($data['name'] ?? '')
        ) ?: $data['legal_name'];

        $data['code'] = trim(
            (string) ($data['code'] ?? '')
        ) ?: $this->generateBranchCode(
            $data['name'],
            $data['type']
        );

        $result = DB::transaction(function () use (
            $data,
            $teamProvisioner
        ) {
            $data['status'] = Branch::STATUS_DRAFT;
            $data['manager_user_id'] = null;

            $branch = Branch::create($data);

            if (!empty($data['coverage_location_id'])) {
                CoverageLocation::where(
                    'id',
                    $data['coverage_location_id']
                )->update([
                    'branch_id' => $branch->id,
                ]);
            }

            /*
         * Creates the manager and all prepared
         * position-based login accounts.
         */
            $team = $teamProvisioner->provision(
                $branch,
                $data
            );

            $branch->update([
                'manager_user_id' =>
                $team['manager']->id,
            ]);

            /*
             * The manager account exists now, but no email is sent yet.
             * The invitation is queued only after admin approval.
             */
            $branch->forceFill([
                'account_invitation_status' =>
                'pending_admin_approval',

                'account_invitation_email' =>
                $team['manager']->email,
            ])->save();

            return [
                'branch' => $branch,
                'team' => $team,
            ];
        });

        return response()->json([
            'message' =>
            'Branch and operational team accounts created successfully. The account setup email will be sent after admin approval.',

            'data' => $result['branch']
                ->fresh()
                ->load([
                    'parent',
                    'coverageLocation',
                    'manager',
                    'documents',
                    'agreements',
                ]),

            'team_setup' => [
                'manager' => [
                    'id' =>
                    $result['team']['manager']->id,

                    'name' =>
                    $result['team']['manager']->name,

                    'username' =>
                    $result['team']['manager']->username,

                    'email' =>
                    $result['team']['manager']->email,

                    'role' =>
                    $result['team']['manager']->role,
                ],

                'generated_accounts' =>
                $result['team']['total_accounts'],

                'vacant_positions' =>
                count(
                    $result['team']['staff_accounts']
                ),

                'owner_notification' => 'pending_admin_approval',
            ],
        ], 201);
    }
    private function generateBranchCode(
        string $name,
        string $type
    ): string {
        $prefix = $this->isHeadBranchType($type)
            ? 'BR'
            : 'SUB';

        $slug = Str::of($name)
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '-')
            ->trim('-');

        if ($slug->isEmpty()) {
            $slug = Str::of('BRANCH');
        }

        $baseCode = "{$prefix}-{$slug}";
        $code = (string) $baseCode;
        $suffix = 1;

        while (
            Branch::query()
            ->where('code', $code)
            ->exists()
        ) {
            $code = "{$baseCode}-{$suffix}";
            $suffix++;
        }

        return $code;
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

    public function update(
        Request $request,
        Branch $branch,
        BranchVisibilityService $visibility
    ): JsonResponse {
        $this->abortIfBranchNotVisible(
            $request,
            $branch,
            $visibility
        );

        /*
         * Update validation is partial. Only submitted fields are
         * validated and only changed fields should be sent by the client.
         */
        $data = $this->validatedData($request, $branch);

        if ($data === []) {
            return response()->json([
                'message' => 'No branch changes were submitted.',
                'data' => $branch->fresh()->load([
                    'parent',
                    'children',
                    'coverageLocation',
                    'manager:id,name,email,phone',
                    'documents',
                    'agreements',
                    'approver:id,name,email',
                    'rejecter:id,name,email',
                ]),
                'updated_fields' => [],
            ]);
        }

        if (array_key_exists('type', $data)) {
            $data['type'] = $this->normalizeBranchType(
                $data['type']
            );
        }

        if (array_key_exists('email', $data)) {
            $data['email'] = filled($data['email'])
                ? strtolower(trim((string) $data['email']))
                : null;
        }

        $effectiveType = $this->normalizeBranchType(
            $data['type'] ?? $branch->type
        );

        $effectiveEmail = array_key_exists('email', $data)
            ? $data['email']
            : $branch->email;

        if (
            $effectiveType === Branch::TYPE_FRANCHISE_BRANCH &&
            blank($effectiveEmail)
        ) {
            throw ValidationException::withMessages([
                'email' => [
                    'A manager email is required for a franchise branch account.',
                ],
            ]);
        }

        $this->validateHierarchyAccess(
            $request,
            $visibility,
            $data,
            $branch
        );

        $coverageWasSubmitted = array_key_exists(
            'coverage_location_id',
            $data
        );

        $emailWasSubmitted = array_key_exists(
            'email',
            $data
        );

        $oldCoverageLocationId = $branch->coverage_location_id;

        if ($coverageWasSubmitted) {
            $this->applyCoverageLocationToBranchPayload(
                $data,
                $effectiveType,
                $branch
            );
        }

        DB::transaction(function () use (
            $branch,
            $data,
            $effectiveType,
            $coverageWasSubmitted,
            $emailWasSubmitted,
            $oldCoverageLocationId
        ): void {
            $branch->fill($data);
            $branch->save();

            if ($coverageWasSubmitted) {
                $newCoverageLocationId =
                    $data['coverage_location_id'] ?? null;

                if (
                    $oldCoverageLocationId &&
                    (int) $oldCoverageLocationId !==
                        (int) ($newCoverageLocationId ?: 0)
                ) {
                    CoverageLocation::query()
                        ->where('id', $oldCoverageLocationId)
                        ->where('branch_id', $branch->id)
                        ->update([
                            'branch_id' => null,
                        ]);
                }

                if ($newCoverageLocationId) {
                    CoverageLocation::query()
                        ->where('id', $newCoverageLocationId)
                        ->update([
                            'branch_id' => $branch->id,
                        ]);
                }
            }

            /*
             * The manager email has one source of truth. Updating the
             * franchise email keeps the branch, manager user and future
             * invitation recipient synchronized.
             */
            if (
                $emailWasSubmitted &&
                $effectiveType === Branch::TYPE_FRANCHISE_BRANCH
            ) {
                $manager = $branch
                    ->manager()
                    ->lockForUpdate()
                    ->first();

                if (!$manager) {
                    throw ValidationException::withMessages([
                        'email' => [
                            'The manager email cannot be updated because the Branch Manager account is missing.',
                        ],
                    ]);
                }

                $manager->forceFill([
                    'email' => $data['email'],
                ])->save();

                $branch->forceFill([
                    'email' => $data['email'],
                    'account_invitation_email' => $data['email'],
                ])->save();
            }
        }, 3);

        return response()->json([
            'message' => 'Branch updated successfully.',
            'data' => $branch
                ->fresh()
                ->load([
                    'parent',
                    'children',
                    'coverageLocation',
                    'manager:id,name,email,phone',
                    'documents',
                    'agreements',
                    'approver:id,name,email',
                    'rejecter:id,name,email',
                ]),
            'updated_fields' => array_values(array_keys($data)),
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

    // public function approve(
    //     Request $request,
    //     Branch $branch,
    //     BranchVisibilityService $visibility,
    //     BranchAccountInvitationService $invitationService
    // ): JsonResponse {
    //     $this->abortIfBranchNotVisible(
    //         $request,
    //         $branch,
    //         $visibility
    //     );

    //     $branch->loadMissing('manager');

    //     $isFranchise =
    //         $this->normalizeBranchType($branch->type) ===
    //         Branch::TYPE_FRANCHISE_BRANCH;

    //     if ($isFranchise && !$branch->manager_user_id) {
    //         return response()->json([
    //             'message' =>
    //                 'The franchise cannot be approved because its manager account is missing.',

    //             'errors' => [
    //                 'manager_user_id' => [
    //                     'A registered branch manager is required before approval.',
    //                 ],
    //             ],
    //         ], 422);
    //     }

    //     if (
    //         $isFranchise &&
    //         blank($branch->manager?->email)
    //     ) {
    //         return response()->json([
    //             'message' =>
    //                 'The franchise cannot be approved because the manager email is missing.',

    //             'errors' => [
    //                 'manager_email' => [
    //                     'A valid manager email is required before approval.',
    //                 ],
    //             ],
    //         ], 422);
    //     }

    //     $wasAlreadyApproved = in_array(
    //         $branch->status,
    //         [
    //             Branch::STATUS_APPROVED,
    //             Branch::STATUS_ACTIVE,
    //         ],
    //         true
    //     );

    //     DB::transaction(function () use (
    //         $request,
    //         $branch
    //     ): void {
    //         $branch->update([
    //             /*
    //              * Do not downgrade an active branch back to approved.
    //              */
    //             'status' =>
    //                 $branch->status === Branch::STATUS_ACTIVE
    //                     ? Branch::STATUS_ACTIVE
    //                     : Branch::STATUS_APPROVED,

    //             'approved_by' =>
    //                 $branch->approved_by
    //                 ?: $request->user()?->id,

    //             'approved_at' =>
    //                 $branch->approved_at
    //                 ?: now(),

    //             'rejected_by' => null,
    //             'rejected_at' => null,
    //             'rejection_reason' => null,
    //         ]);
    //     });

    //     $freshBranch = $branch
    //         ->fresh()
    //         ->load([
    //             'parent',
    //             'coverageLocation',
    //             'manager',
    //         ]);

    //     $invitation = [
    //         'status' => 'not_required',
    //         'email' => null,
    //         'queued_at' => null,
    //         'sent_at' => null,
    //     ];

    //     if ($isFranchise) {
    //         $invitation = $invitationService->queue(
    //             branch: $freshBranch,
    //             force: false
    //         );
    //     }

    //     return response()->json([
    //         'message' => match ($invitation['status']) {
    //             'queued' =>
    //                 'Franchise approved successfully. The branch manager account setup email has been queued.',

    //             'sent' =>
    //                 'Franchise approved successfully. The account setup email had already been sent.',

    //             default => $wasAlreadyApproved
    //                 ? 'Branch was already approved.'
    //                 : 'Branch approved successfully.',
    //         },

    //         'data' => $freshBranch,

    //         'account_invitation' => $invitation,
    //     ]);
    // }

    public function approve(
        Request $request,
        Branch $branch,
        BranchVisibilityService $visibility,
        BranchAccountInvitationService $invitationService
    ): JsonResponse {
        $this->abortIfBranchNotVisible(
            $request,
            $branch,
            $visibility
        );

        $branch->loadMissing('manager');

        $isFranchise =
            $this->normalizeBranchType(
                $branch->type
            ) === Branch::TYPE_FRANCHISE_BRANCH;

        if ($isFranchise) {
            if (
                !$branch->manager_user_id ||
                !$branch->manager
            ) {
                return response()->json([
                    'message' =>
                    'The franchise cannot be approved because its Branch Manager account is missing.',

                    'errors' => [
                        'manager_user_id' => [
                            'A registered Branch Manager is required before approval.',
                        ],
                    ],
                ], 422);
            }

            if (blank($branch->manager->email)) {
                return response()->json([
                    'message' =>
                    'The franchise cannot be approved because the registered manager email is missing.',

                    'errors' => [
                        'manager_email' => [
                            'A valid Branch Manager email is required before approval.',
                        ],
                    ],
                ], 422);
            }
        }

        $wasAlreadyApproved = in_array(
            $branch->status,
            [
                Branch::STATUS_APPROVED,
                Branch::STATUS_ACTIVE,
            ],
            true
        );

        DB::transaction(
            function () use (
                $request,
                $branch
            ): void {
                $branch->update([
                    /*
                 * Never downgrade an active branch.
                 */
                    'status' =>
                    $branch->status ===
                        Branch::STATUS_ACTIVE
                        ? Branch::STATUS_ACTIVE
                        : Branch::STATUS_APPROVED,

                    'approved_by' =>
                    $branch->approved_by
                        ?: $request->user()?->id,

                    'approved_at' =>
                    $branch->approved_at
                        ?: now(),

                    'rejected_by' =>
                    null,

                    'rejected_at' =>
                    null,

                    'rejection_reason' =>
                    null,
                ]);
            },
            3
        );

        $freshBranch = $branch
            ->fresh()
            ->load([
                'parent',
                'coverageLocation',
                'manager',
            ]);

        $invitation = [
            'status' =>
            'not_required',

            'email' =>
            null,

            'queued_at' =>
            null,

            'sent_at' =>
            null,

            'failed_at' =>
            null,

            'attempt' =>
            0,

            'error' =>
            null,
        ];

        if ($isFranchise) {
            $invitation =
                $invitationService->queue(
                    branch: $freshBranch,
                    force: false
                );
        }

        return response()->json([
            'message' => match ($invitation['status']) {
                BranchAccountInvitationService::STATUS_QUEUED =>
                'Franchise approved successfully. The Branch Manager account setup email has been queued.',

                BranchAccountInvitationService::STATUS_SENT =>
                'Franchise approved successfully. The account setup email has already been sent.',

                BranchAccountInvitationService::STATUS_ACCOUNT_CONFIGURED =>
                'Franchise approved successfully. The Branch Manager account is already configured.',

                default =>
                $wasAlreadyApproved
                    ? 'Branch was already approved.'
                    : 'Branch approved successfully.',
            },

            'data' =>
            $freshBranch->fresh([
                'parent',
                'coverageLocation',
                'manager',
            ]),

            'account_invitation' =>
            $invitation,
        ]);
    }

    // public function resendAccountInvitation(
    //     Request $request,
    //     Branch $branch,
    //     BranchVisibilityService $visibility,
    //     BranchAccountInvitationService $invitationService
    // ): JsonResponse {
    //     $this->abortIfBranchNotVisible(
    //         $request,
    //         $branch,
    //         $visibility
    //     );

    //     if (
    //         $this->normalizeBranchType($branch->type) !==
    //         Branch::TYPE_FRANCHISE_BRANCH
    //     ) {
    //         return response()->json([
    //             'message' =>
    //             'Account invitations are currently configured for franchise branches only.',
    //         ], 422);
    //     }

    //     $invitation = $invitationService->queue(
    //         branch: $branch->fresh(['manager']),
    //         force: true
    //     );

    //     return response()->json([
    //         'message' =>
    //         'A new branch manager account setup email has been queued.',

    //         'account_invitation' => $invitation,
    //     ]);
    // }

    public function resendAccountInvitation(
        Request $request,
        Branch $branch,
        BranchVisibilityService $visibility,
        BranchAccountInvitationService $invitationService
    ): JsonResponse {
        $this->abortIfBranchNotVisible(
            $request,
            $branch,
            $visibility
        );

        if (
            $this->normalizeBranchType(
                $branch->type
            ) !== Branch::TYPE_FRANCHISE_BRANCH
        ) {
            return response()->json([
                'message' =>
                'Account invitations are available for franchise branches only.',
            ], 422);
        }

        $invitation =
            $invitationService->queue(
                branch: $branch->fresh([
                    'manager',
                ]),

                force: true
            );

        return response()->json([
            'message' => match ($invitation['status']) {
                BranchAccountInvitationService::STATUS_ACCOUNT_CONFIGURED =>
                'The Branch Manager account is already configured.',

                BranchAccountInvitationService::STATUS_QUEUED =>
                'A new Branch Manager account setup email has been queued.',

                default =>
                'The account invitation request was processed.',
            },

            'account_invitation' =>
            $invitation,
        ]);
    }

    public function activate(Request $request, Branch $branch, BranchVisibilityService $visibility): JsonResponse
    {
        $this->abortIfBranchNotVisible($request, $branch, $visibility);

        $isFranchise =
            $this->normalizeBranchType(
                $branch->type
            ) === Branch::TYPE_FRANCHISE_BRANCH;

        if (
            $isFranchise &&
            $branch->account_invitation_status !==
            BranchAccountInvitationService::STATUS_ACCOUNT_CONFIGURED
        ) {
            return response()->json([
                'message' =>
                'The branch cannot be activated until the Branch Manager finishes account setup.',

                'errors' => [
                    'account_setup' => [
                        'The Branch Manager must create a password before operational activation.',
                    ],
                ],
            ], 422);
        }

        $errors = [];

        foreach (
            [
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
            ] as $field
        ) {
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

    private function validatedData(
        Request $request,
        ?Branch $branch = null
    ): array {
        $branchId = $branch?->id;
        $managerUserId = $branch?->manager_user_id;
        $isUpdating = $branch !== null;

        $effectiveType = $this->normalizeBranchType(
            $request->input('type', $branch?->type)
        );

        $partial = $isUpdating
            ? ['sometimes']
            : [];

        $emailRules = [
            ...$partial,
            'nullable',
            'email',
            'max:255',
        ];

        if ($effectiveType === Branch::TYPE_FRANCHISE_BRANCH) {
            $emailRules[] = Rule::unique(
                'users',
                'email'
            )->ignore($managerUserId);
        }

        return $request->validate([
            'parent_id' => [
                ...$partial,
                'nullable',
                'integer',
                'exists:branches,id',
            ],

            'coverage_location_id' => [
                ...$partial,
                'nullable',
                'integer',
                'exists:coverage_locations,id',
            ],

            'type' => $isUpdating
                ? [
                    'sometimes',
                    Rule::in($this->allowedBranchTypes()),
                ]
                : [
                    'required',
                    Rule::in($this->allowedBranchTypes()),
                ],

            'name' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'code' => [
                ...$partial,
                'nullable',
                'string',
                'max:80',
                Rule::unique('branches', 'code')
                    ->ignore($branchId),
            ],

            'legal_name' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'owner_name' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'contact_person' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'email' => $emailRules,

            'phone' => [
                ...$partial,
                'nullable',
                'string',
                'max:50',
            ],

            'alternative_phone' => [
                ...$partial,
                'nullable',
                'string',
                'max:50',
            ],

            'pan_vat_number' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'registration_number' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'business_type' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            /*
             * Status and manager_user_id are intentionally not accepted
             * here. Approval, activation, suspension and rejection have
             * dedicated workflow endpoints.
             */

            'country' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'province' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'district' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'city' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'area' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'address' => [
                ...$partial,
                'nullable',
                'string',
                'max:1000',
            ],

            'landmark' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'latitude' => [
                ...$partial,
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                ...$partial,
                'nullable',
                'numeric',
                'between:-180,180',
            ],

            'coverage_radius_km' => [
                ...$partial,
                'nullable',
                'numeric',
                'min:0',
            ],

            'office_address' => [
                ...$partial,
                'nullable',
                'string',
                'max:1000',
            ],

            'office_city' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'office_area' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'office_street' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'office_landmark' => [
                ...$partial,
                'nullable',
                'string',
                'max:255',
            ],

            'office_latitude' => [
                ...$partial,
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'office_longitude' => [
                ...$partial,
                'nullable',
                'numeric',
                'between:-180,180',
            ],

            'covered_areas' => [
                ...$partial,
                'nullable',
                'array',
            ],

            'covered_areas.*' => [
                'nullable',
                'string',
                'max:255',
            ],

            'opening_time' => [
                ...$partial,
                'nullable',
                'date_format:H:i',
            ],

            'closing_time' => [
                ...$partial,
                'nullable',
                'date_format:H:i',
            ],

            'operating_days' => [
                ...$partial,
                'nullable',
                'array',
            ],

            'daily_shipment_capacity' => [
                ...$partial,
                'nullable',
                'integer',
                'min:0',
            ],

            'pickup_enabled' => [
                ...$partial,
                'boolean',
            ],

            'delivery_enabled' => [
                ...$partial,
                'boolean',
            ],

            'pod_enabled' => [
                ...$partial,
                'boolean',
            ],

            'return_enabled' => [
                ...$partial,
                'boolean',
            ],
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
            if (
                $isCreating ||
                array_key_exists('type', $data) ||
                array_key_exists('parent_id', $data)
            ) {
                $data['parent_id'] = null;
            }

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
            ->map(fn($id) => (int) $id)
            ->all();

        if (!in_array((int) $parentId, $allowedParentIds, true)) {
            throw ValidationException::withMessages([
                'parent_id' => ['You are not allowed to create or move a branch under this parent.'],
            ]);
        }

        $data['parent_id'] = (int) $parentId;
    }

    private function applyCoverageLocationToBranchPayload(
        array &$data,
        ?string $fallbackType = null,
        ?Branch $existingBranch = null
    ): void {
        if (!array_key_exists('coverage_location_id', $data)) {
            return;
        }

        if (blank($data['coverage_location_id'])) {
            $data['coverage_location_id'] = null;
            $data['latitude'] = null;
            $data['longitude'] = null;
            $data['coverage_radius_km'] = null;

            return;
        }

        $coverageLocation = CoverageLocation::query()->find(
            $data['coverage_location_id']
        );

        if (!$coverageLocation) {
            throw ValidationException::withMessages([
                'coverage_location_id' => [
                    'Coverage location not found.',
                ],
            ]);
        }

        if (
            $coverageLocation->branch_id &&
            (int) $coverageLocation->branch_id !==
                (int) ($existingBranch?->id ?? 0)
        ) {
            throw ValidationException::withMessages([
                'coverage_location_id' => [
                    'This coverage location is already assigned to another branch.',
                ],
            ]);
        }

        $type = $this->normalizeBranchType(
            $data['type'] ??
            $fallbackType ??
            $existingBranch?->type
        );

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

        if (
            $isMainBranch &&
            $coverageLocation->type !==
                CoverageLocation::TYPE_MAIN_BRANCH_ZONE
        ) {
            throw ValidationException::withMessages([
                'coverage_location_id' => [
                    'Main/head/franchise branch must be assigned to a main branch coverage zone.',
                ],
            ]);
        }

        if (
            $isSubBranch &&
            $coverageLocation->type !==
                CoverageLocation::TYPE_SUB_BRANCH_ZONE
        ) {
            throw ValidationException::withMessages([
                'coverage_location_id' => [
                    'Sub branch, pickup point, or delivery hub must be assigned to a sub-branch coverage zone.',
                ],
            ]);
        }

        /*
         * Routing and pricing coordinates continue to come from the
         * selected coverage allocation.
         */
        $data['latitude'] = $coverageLocation->latitude;
        $data['longitude'] = $coverageLocation->longitude;
        $data['coverage_radius_km'] =
            $coverageLocation->coverage_radius_km;

        /*
         * Populate normal address fields only when the request did not
         * submit them and the existing branch value is empty.
         */
        foreach ([
            'country',
            'province',
            'district',
            'city',
            'area',
            'address',
            'landmark',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                continue;
            }

            if (filled($existingBranch?->{$field})) {
                continue;
            }

            if (filled($coverageLocation->{$field})) {
                $data[$field] = $coverageLocation->{$field};
            }
        }
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
