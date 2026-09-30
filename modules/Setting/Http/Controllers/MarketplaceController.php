<?php

namespace Modules\Setting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Billing\Services\PaymentGatewayAccountService;
use Modules\Merchant\Models\Merchant;
use Modules\Setting\Models\Marketplace;

/**
 * Admin: multiple marketplaces (api.tukaatu.com, api.fca.com.np, ...).
 * Each marketplace has callback/API URL, optional HamroPay credentials
 * (payment_gateway_accounts owner_type=marketplace), and linked stores.
 */
class MarketplaceController extends Controller
{
    public function __construct(private PaymentGatewayAccountService $accounts)
    {
    }

    protected function assertHq(Request $request): void
    {
        $user = $request->user();
        $ok = method_exists($user, 'isSuperAdmin') && ($user->isSuperAdmin() ?? false);
        $ok = $ok || (method_exists($user, 'hasRole') && $user->hasRole(['super_admin', 'main_admin', 'admin']));
        abort_unless($ok, 403, 'Only HQ admins can manage marketplaces.');
    }

    public function index(Request $request)
    {
        $this->assertHq($request);

        $rows = Marketplace::query()
            ->withCount('merchants')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Marketplace $m) => $this->present($m));

        return ApiResponse::success($rows);
    }

    public function show(Request $request, Marketplace $marketplace)
    {
        $this->assertHq($request);
        $marketplace->loadCount('merchants');

        $hamro = $this->accounts->marketplaceAccount((int) $marketplace->id, 'hamropay');

        return ApiResponse::success([
            'marketplace' => $this->present($marketplace, true),
            'hamropay_account' => $hamro ? $this->accounts->masked($hamro) : null,
            'stores' => Merchant::query()
                ->where('marketplace_id', $marketplace->id)
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'external_store_id', 'external_platform', 'hamropay_merchant_id', 'hamropay_business_id', 'status', 'marketplace_id']),
        ]);
    }

    public function store(Request $request)
    {
        $this->assertHq($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:marketplaces,code'],
            'email' => ['nullable', 'email', 'max:191'],
            'api_base_url' => ['nullable', 'string', 'max:500'],
            'callback_url' => ['nullable', 'string', 'max:500'],
            'callback_secret' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
        ]);

        $marketplace = DB::transaction(function () use ($data) {
            $row = Marketplace::create([
                'name' => $data['name'],
                'code' => Str::lower($data['code']),
                'email' => $data['email'] ?? null,
                'api_base_url' => $data['api_base_url'] ?? null,
                'callback_url' => $data['callback_url'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'is_default' => $data['is_default'] ?? false,
                'meta' => $data['meta'] ?? null,
            ]);

            if (! empty($data['callback_secret'])) {
                $row->callback_secret = $data['callback_secret'];
                $row->save();
            }

            if ($row->is_default) {
                Marketplace::query()->where('id', '!=', $row->id)->update(['is_default' => false]);
            }

            return $row;
        });

        return ApiResponse::success($this->present($marketplace->fresh(), true), 'Marketplace created.', 201);
    }

    public function update(Request $request, Marketplace $marketplace)
    {
        $this->assertHq($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'code' => ['sometimes', 'string', 'max:100', 'alpha_dash', Rule::unique('marketplaces', 'code')->ignore($marketplace->id)],
            'email' => ['nullable', 'email', 'max:191'],
            'api_base_url' => ['nullable', 'string', 'max:500'],
            'callback_url' => ['nullable', 'string', 'max:500'],
            'callback_secret' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($marketplace, $data) {
            if (array_key_exists('callback_secret', $data)) {
                $secret = $data['callback_secret'];
                unset($data['callback_secret']);
                if (is_string($secret) && $secret !== '') {
                    $marketplace->callback_secret = $secret;
                }
            }

            if (isset($data['code'])) {
                $data['code'] = Str::lower($data['code']);
            }

            $marketplace->fill($data);
            $marketplace->save();

            if ($marketplace->is_default) {
                Marketplace::query()->where('id', '!=', $marketplace->id)->update(['is_default' => false]);
            }
        });

        return ApiResponse::success($this->present($marketplace->fresh()->loadCount('merchants'), true), 'Marketplace updated.');
    }

    /**
     * Save HamroPay HQ credentials for this marketplace (owner_type=marketplace).
     */
    public function upsertHamroPay(Request $request, Marketplace $marketplace)
    {
        $this->assertHq($request);

        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:64'],
            'is_enabled' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'credentials' => ['required', 'array'],
        ]);

        $account = $this->accounts->upsert(
            'marketplace',
            (int) $marketplace->id,
            'hamropay',
            $this->accounts->filterCredentials('hamropay', $data['credentials']),
            [
                'label' => $data['label'] ?? 'default',
                'is_enabled' => $data['is_enabled'] ?? true,
                'is_default' => $data['is_default'] ?? true,
            ],
        );

        return ApiResponse::success($this->accounts->masked($account), 'Marketplace HamroPay credentials saved.');
    }

    /**
     * Attach / detach stores to this marketplace.
     */
    public function syncStores(Request $request, Marketplace $marketplace)
    {
        $this->assertHq($request);

        $data = $request->validate([
            'merchant_ids' => ['required', 'array', 'min:1'],
            'merchant_ids.*' => ['integer', 'exists:merchants,id'],
            'action' => ['nullable', 'in:attach,detach'],
        ]);

        $action = $data['action'] ?? 'attach';
        $ids = $data['merchant_ids'];

        if ($action === 'detach') {
            Merchant::query()->whereIn('id', $ids)->where('marketplace_id', $marketplace->id)
                ->update(['marketplace_id' => null]);
        } else {
            Merchant::query()->whereIn('id', $ids)->update(['marketplace_id' => $marketplace->id]);
        }

        return ApiResponse::success([
            'marketplace_id' => $marketplace->id,
            'stores_count' => Merchant::query()->where('marketplace_id', $marketplace->id)->count(),
        ], 'Stores updated.');
    }

    private function present(Marketplace $m, bool $detail = false): array
    {
        $row = [
            'id' => $m->id,
            'name' => $m->name,
            'code' => $m->code,
            'email' => $m->email,
            'api_base_url' => $m->api_base_url,
            'callback_url' => $m->callback_url,
            'callback_secret_set' => filled(data_get($m->getAttributes(), 'callback_secret')),
            'is_active' => (bool) $m->is_active,
            'is_default' => (bool) $m->is_default,
            'merchants_count' => $m->merchants_count ?? null,
            'meta' => $m->meta,
            'updated_at' => $m->updated_at,
        ];

        if ($detail) {
            $row['created_at'] = $m->created_at;
        }

        return $row;
    }
}