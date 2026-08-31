<?php

declare(strict_types=1);

namespace Modules\Shipment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

final class AdminStaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branchId = $request->attributes->get('branch_id');

        $query = User::query()
            ->with([
                'roles',
                'branch',
            ]);

        /*
         * Branch manager:
         *
         * Only their branch.
         */
        if ($branchId) {
            $query->where(
                'branch_id',
                $branchId
            );
        }

        /*
         * Search.
         */
        if ($request->filled('q')) {
            $search = trim(
                (string) $request->input('q')
            );

            $query->where(function ($q) use ($search) {
                $q
                    ->where(
                        'name',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'email',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'phone',
                        'like',
                        "%{$search}%"
                    );
            });
        }

        /*
         * Only operational staff roles.
         */
        $query->whereHas(
            'roles',
            function ($roleQuery) {
                $roleQuery->whereIn(
                    'name',
                    [
                        'pickup_staff',
                        'delivery_staff',
                        'rider',
                    ]
                );
            }
        );

        $users = $query
            ->latest('id')
            ->paginate(
                $request->integer(
                    'per_page',
                    20
                )
            );

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    public function show(
        Request $request,
        User $staff
    ): JsonResponse {
        $branchId =
            $request->attributes->get(
                'branch_id'
            );

        if (
            $branchId &&
            (int) $staff->branch_id !==
                (int) $branchId
        ) {
            abort(404);
        }

        $staff->load([
            'roles',
            'branch',
        ]);

        return response()->json([
            'success' => true,
            'data' => $staff,
        ]);
    }

    public function store(
        Request $request
    ): JsonResponse {
        $branchId =
            $request->attributes->get(
                'branch_id'
            );

        abort_unless(
            $branchId,
            403,
            'Branch context is required.'
        );

        $validated =
            $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'email' => [
                    'nullable',
                    'email',
                    'max:255',
                    'unique:users,email',
                ],

                'phone' => [
                    'required',
                    'string',
                    'max:30',
                ],

                'password' => [
                    'required',
                    'string',
                    'min:8',
                ],

                'role' => [
                    'required',
                    'string',
                    Rule::in([
                        'pickup_staff',
                        'delivery_staff',
                        'rider',
                    ]),
                ],
            ]);

        $user = User::create([
            'name' =>
                $validated['name'],

            'email' =>
                $validated['email']
                    ?? null,

            'phone' =>
                $validated['phone'],

            'password' =>
                Hash::make(
                    $validated['password']
                ),

            /*
             * IMPORTANT:
             *
             * branch comes from authenticated
             * manager context.
             */
            'branch_id' =>
                $branchId,

            'is_active' => true,
        ]);

        /*
         * Spatie Permission example.
         */
        $user->syncRoles([
            $validated['role'],
        ]);

        $user->load([
            'roles',
            'branch',
        ]);

        return response()->json([
            'success' => true,
            'message' =>
                'Staff created successfully.',
            'data' => $user,
        ], 201);
    }

    public function update(
        Request $request,
        User $staff
    ): JsonResponse {
        $branchId =
            $request->attributes->get(
                'branch_id'
            );

        if (
            $branchId &&
            (int) $staff->branch_id !==
                (int) $branchId
        ) {
            abort(404);
        }

        $validated =
            $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                ],

                'email' => [
                    'nullable',
                    'email',
                    'max:255',
                    Rule::unique(
                        'users',
                        'email'
                    )->ignore(
                        $staff->id
                    ),
                ],

                'phone' => [
                    'required',
                    'string',
                    'max:30',
                ],

                'password' => [
                    'nullable',
                    'string',
                    'min:8',
                ],

                'role' => [
                    'required',
                    'string',
                    Rule::in([
                        'pickup_staff',
                        'delivery_staff',
                        'rider',
                    ]),
                ],
            ]);

        $staff->name =
            $validated['name'];

        $staff->email =
            $validated['email']
                ?? null;

        $staff->phone =
            $validated['phone'];

        if (
            !empty(
                $validated['password']
            )
        ) {
            $staff->password =
                Hash::make(
                    $validated['password']
                );
        }

        /*
         * Never change branch_id here.
         */
        $staff->save();

        $staff->syncRoles([
            $validated['role'],
        ]);

        $staff->load([
            'roles',
            'branch',
        ]);

        return response()->json([
            'success' => true,
            'message' =>
                'Staff updated successfully.',
            'data' => $staff,
        ]);
    }

    public function destroy(
        Request $request,
        User $staff
    ): JsonResponse {
        $branchId =
            $request->attributes->get(
                'branch_id'
            );

        if (
            $branchId &&
            (int) $staff->branch_id !==
                (int) $branchId
        ) {
            abort(404);
        }

        /*
         * Prefer deactivation instead
         * of physically deleting users.
         */
        $staff->is_active = false;
        $staff->save();

        return response()->json([
            'success' => true,
            'message' =>
                'Staff deactivated successfully.',
        ]);
    }

    public function toggle(
        Request $request,
        User $staff
    ): JsonResponse {
        $branchId =
            $request->attributes->get(
                'branch_id'
            );

        if (
            $branchId &&
            (int) $staff->branch_id !==
                (int) $branchId
        ) {
            abort(404);
        }

        $staff->is_active =
            ! $staff->is_active;

        $staff->save();

        return response()->json([
            'success' => true,
            'message' =>
                'Staff status updated.',
            'data' => $staff,
        ]);
    }
}