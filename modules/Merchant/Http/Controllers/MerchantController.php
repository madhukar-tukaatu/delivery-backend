<?php
namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Merchant\Models\Merchant;

class MerchantController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Merchant::with([
            'defaultBranch',
            'defaultSubBranch',
        ])->latest();

        /*
        |--------------------------------------------------------------------------
        | Branch Scope
        |--------------------------------------------------------------------------
        |
        | Super admin has global access.
        |
        | Every other authenticated admin user must have a branch_id.
        |
        */

        $isSuperAdmin =
        $user?->is_super_admin === true ||
        in_array(
            strtolower((string) $user?->role),
            [
                'super_admin',
                'super-admin',
                'superadmin',
                'admin',
                'web',
            ],
            true
        );

        if (! $isSuperAdmin) {
            /*
             * Determine the user's branch.
             */
            $branchId =
            $user?->branch_id ??
            $user?->default_branch_id ??
            $user?->branch?->id ??
            $user?->default_branch?->id;

            /*
             * No branch means no merchants.
             *
             * This is safer than accidentally returning
             * the global merchant list.
             */
            if (! $branchId) {
                return ApiResponse::success(
                    [
                        'data'         => [],
                        'current_page' => 1,
                        'per_page'     => (int) $request->get('per_page', 20),
                        'total'        => 0,
                        'last_page'    => 1,
                    ],
                    'No branch is assigned to this account.'
                );
            }

            /*
             * Branch-scoped merchant query.
             */
            $query->where(
                'default_branch_id',
                $branchId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Status Filter
        |--------------------------------------------------------------------------
        */

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->status
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        |
        | Support both:
        |
        | ?search=Kathmandu
        |
        | and the existing:
        |
        | ?q=Kathmandu
        |
        */

        $search = $request->input(
            'search',
            $request->input('q')
        );

        if (
            is_string($search) &&
            trim($search) !== ''
        ) {
            $search = trim($search);

            $query->where(function ($q) use ($search) {
                $q->where(
                    'name',
                    'like',
                    "%{$search}%"
                )
                    ->orWhere(
                        'code',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'phone',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'email',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'owner_name',
                        'like',
                        "%{$search}%"
                    );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $perPage = (int) $request->get(
            'per_page',
            20
        );

        /*
         * Prevent unreasonable page sizes.
         */
        $perPage = max(
            1,
            min($perPage, 100)
        );

        $merchants = $query->paginate(
            $perPage
        );

        return ApiResponse::success(
            $merchants
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'                  => ['required', 'string'],
            'code'                  => ['nullable', 'string', 'unique:merchants,code'],
            'owner_name'            => ['nullable', 'string'],
            'contact_person'        => ['nullable', 'string'],
            'phone'                 => ['nullable', 'string'],
            'email'                 => ['nullable', 'email'],
            'website_url'           => ['nullable', 'string'],
            'business_type'         => ['nullable', 'string'],
            'pan_vat_number'        => ['nullable', 'string'],
            'address'               => ['nullable', 'string'],
            'default_branch_id'     => ['nullable', 'exists:branches,id'],
            'default_sub_branch_id' => ['nullable', 'exists:branches,id'],
            'create_login'          => ['nullable', 'boolean'],
            'password'              => ['nullable', 'string', 'min:6'],
        ]);
        $data['code'] = $data['code'] ?? 'MER-' . Str::upper(Str::random(6));
        $merchant     = Merchant::create($data);

        if (($data['create_login'] ?? true) && ! empty($data['email'])) {
            $merchantUser = User::firstOrCreate(['email' => $data['email']], [
                'name'        => $data['contact_person'] ?: $data['name'],
                'phone'       => $data['phone'] ?? null,
                'role'        => 'merchant',
                'merchant_id' => $merchant->id,
                'password'    => Hash::make($data['password'] ?? 'password'),
                'is_active'   => true,
            ]);
            try { $merchantUser->syncRoles(['merchant']);} catch (\Throwable $e) {}
        }

        return ApiResponse::success($merchant->load('users'), 'Merchant created.', 201);
    }

    public function show(Merchant $merchant)
    {
        return ApiResponse::success($merchant->load(['users', 'pickupLocations', 'apiKeys', 'webhooks']));
    }

    public function update(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            'name'                  => ['sometimes', 'string'],
            'code'                  => ['sometimes', 'string', 'unique:merchants,code,' . $merchant->id],
            'owner_name'            => ['nullable', 'string'],
            'contact_person'        => ['nullable', 'string'],
            'phone'                 => ['nullable', 'string'],
            'email'                 => ['nullable', 'email'],
            'website_url'           => ['nullable', 'string'],
            'business_type'         => ['nullable', 'string'],
            'pan_vat_number'        => ['nullable', 'string'],
            'address'               => ['nullable', 'string'],
            'default_branch_id'     => ['nullable', 'exists:branches,id'],
            'default_sub_branch_id' => ['nullable', 'exists:branches,id'],
            'status'                => ['nullable', 'in:pending,active,suspended,rejected'],
        ]);
        $merchant->update($data);
        return ApiResponse::success($merchant->fresh(), 'Merchant updated.');
    }

    public function destroy(Merchant $merchant)
    {
        $merchant->delete();
        return ApiResponse::success(null, 'Merchant deleted.');
    }

    public function approve(Merchant $merchant)
    {
        $merchant->update(['status' => 'active']);
        return ApiResponse::success($merchant, 'Merchant approved.');
    }

    public function suspend(Merchant $merchant)
    {
        $merchant->update(['status' => 'suspended']);
        return ApiResponse::success($merchant, 'Merchant suspended.');
    }
}
