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
    | Determine Super Admin
    |--------------------------------------------------------------------------
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

        /*
    |--------------------------------------------------------------------------
    | Branch Scope
    |--------------------------------------------------------------------------
    |
    | Super admin:
    |     sees ALL merchants.
    |
    | Branch manager:
    |     sees ONLY merchants whose
    |     default_branch_id matches
    |     the authenticated user's branch.
    |
    */

        if (! $isSuperAdmin) {
            $branchId =
            $user?->branch_id ??
            $user?->default_branch_id ??
            $user?->branch?->id ??
            $user?->default_branch?->id;

            /*
        |--------------------------------------------------------------------------
        | No branch
        |--------------------------------------------------------------------------
        |
        | Never expose the global merchant list.
        |
        */

            if (! $branchId) {
                $perPage = max(
                    1,
                    min(
                        (int) $request->get(
                            'per_page',
                            20
                        ),
                        100
                    )
                );

                return ApiResponse::success(
                    $query
                        ->whereRaw('1 = 0')
                        ->paginate($perPage)
                );
            }

            /*
        |--------------------------------------------------------------------------
        | Branch restriction
        |--------------------------------------------------------------------------
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
                $request->input('status')
            );
        }

        /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | Supports:
    |
    | ?search=abc
    | ?q=abc
    |
    */

        $search =
        $request->input('search') ??
        $request->input('q');

        if (
            is_string($search) &&
            trim($search) !== ''
        ) {
            $search = trim($search);

            $query->where(
                function ($q) use ($search) {

                    $q
                        ->where(
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
                        );
                }
            );
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

        $perPage = max(
            1,
            min(
                $perPage,
                100
            )
        );

        $merchants =
        $query->paginate(
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
            'payment_qr_code'       => ['nullable', 'string', 'max:500000'],
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
            'payment_qr_code'       => ['nullable', 'string', 'max:500000'],
            'business_type'         => ['nullable', 'string'],
            'pan_vat_number'        => ['nullable', 'string'],
            'address'               => ['nullable', 'string'],
            'default_branch_id'     => ['nullable', 'exists:branches,id'],
            'default_sub_branch_id' => ['nullable', 'exists:branches,id'],
            'status'                => ['nullable', 'in:pending,active,suspended,rejected'],

            // Phase 6 POD / HamroPay store sub-merchant (admin-editable; not from .env)
            'marketplace_id'          => ['nullable', 'integer', 'exists:marketplaces,id'],
            'external_store_id'       => ['nullable', 'string', 'max:100'],
            'external_platform'       => ['nullable', 'string', 'max:64'],
            'hamropay_merchant_id'    => ['nullable', 'string', 'max:100'],
            'hamropay_business_id'    => ['nullable', 'string', 'max:100'],
            'hamropay_qr_payload'     => ['nullable', 'string', 'max:5000'],

            /*
            | Bulk pickup discount (per store partner).
            | threshold = min packets in one pickup to trigger the discount.
            | amount    = flat amount off that pickup's delivery charge.
            | Send null/0 to disable.
            */
            'bulk_pickup_discount_threshold' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'bulk_pickup_discount_amount'    => ['nullable', 'numeric', 'min:0'],
        ]);
        $previousMarketplaceId = $merchant->marketplace_id;
        $merchant->update($data);

        // Moving a store to another marketplace (e.g. Tukaatu -> FCA) also
        // moves its open shipments, so Online POD calls the new marketplace.
        if (array_key_exists('marketplace_id', $data)
            && (int) ($previousMarketplaceId ?? 0) !== (int) ($merchant->marketplace_id ?? 0)) {
            app(\Modules\Setting\Services\MarketplaceShipmentResync::class)->forMerchants([$merchant->id]);
        }

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
