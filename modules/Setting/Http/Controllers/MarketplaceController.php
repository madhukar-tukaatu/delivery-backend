<?php

namespace Modules\Setting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Billing\Services\PaymentGatewayAccountService;
use Modules\Merchant\Models\Merchant;
use Modules\Setting\Models\Marketplace;
use Modules\Setting\Services\MarketplaceApiKeyIssuer;
use Modules\Setting\Services\MarketplaceShipmentResync;

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

    protected function assertCan(Request $request, string $permission): void
    {
        $user = $request->user();
        $ok = $user && method_exists($user, 'isSuperAdmin') && ($user->isSuperAdmin() ?? false);
        $ok = $ok || ($user && method_exists($user, 'hasRole') && $user->hasRole('super_admin'));
        $ok = $ok || ($user && method_exists($user, 'can') && $user->can($permission));
        abort_unless($ok, 403, 'Missing permission: '.$permission);
    }

    public function index(Request $request)
    {
        $this->assertCan($request, 'marketplaces.view');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'is_active' => ['nullable', 'boolean'],
            'has_api_url' => ['nullable', 'boolean'],
        ]);

        $query = Marketplace::query()->withCount('merchants');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like);
            });
        }

        if ($request->exists('is_active') && $request->input('is_active') !== null && $request->input('is_active') !== '') {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->exists('has_api_url') && $request->input('has_api_url') !== null && $request->input('has_api_url') !== '') {
            if ($request->boolean('has_api_url')) {
                $query->whereNotNull('api_base_url')->where('api_base_url', '!=', '');
            } else {
                $query->where(function ($inner) {
                    $inner->whereNull('api_base_url')->orWhere('api_base_url', '');
                });
            }
        }

        $rows = $query
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Marketplace $m) => $this->present($m));

        return ApiResponse::success($rows);
    }

    public function show(Request $request, Marketplace $marketplace)
    {
        $this->assertCan($request, 'marketplaces.view');
        $marketplace->loadCount('merchants');

        $hamro = $this->accounts->marketplaceAccount((int) $marketplace->id, 'hamropay');

        $shipmentCount = $this->effectiveShipmentCount((int) $marketplace->id);

        return ApiResponse::success([
            'marketplace' => $this->present($marketplace, true),
            'hamropay_account' => $hamro ? $this->accounts->masked($hamro) : null,
            'shipments_count' => $shipmentCount,
            'stores' => Merchant::query()
                ->where('marketplace_id', $marketplace->id)
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'external_store_id', 'external_platform', 'hamropay_merchant_id', 'hamropay_business_id', 'status', 'marketplace_id']),
        ]);
    }

    public function store(Request $request)
    {
        $this->assertCan($request, 'marketplaces.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'code' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:marketplaces,code'],
            'email' => ['nullable', 'email', 'max:191'],
            'api_base_url' => ['nullable', 'string', 'max:500'],
            'callback_url' => ['nullable', 'string', 'max:500'],
            'callback_secret' => ['nullable', 'string', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'api_secret' => ['nullable', 'string', 'max:500'],
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

            foreach (['callback_secret', 'api_key', 'api_secret'] as $secretField) {
                if (! empty($data[$secretField])) {
                    $row->{$secretField} = $data[$secretField];
                }
            }
            $row->save();

            if ($row->is_default) {
                Marketplace::query()->where('id', '!=', $row->id)->update(['is_default' => false]);
            }

            return $row;
        });

        return ApiResponse::success($this->present($marketplace->fresh(), true), 'Marketplace created.', 201);
    }

    public function update(Request $request, Marketplace $marketplace)
    {
        $this->assertCan($request, 'marketplaces.update');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:191'],
            'code' => ['sometimes', 'string', 'max:100', 'alpha_dash', Rule::unique('marketplaces', 'code')->ignore($marketplace->id)],
            'email' => ['nullable', 'email', 'max:191'],
            'api_base_url' => ['nullable', 'string', 'max:500'],
            'callback_url' => ['nullable', 'string', 'max:500'],
            'callback_secret' => ['nullable', 'string', 'max:500'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'api_secret' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($marketplace, $data) {
            foreach (['callback_secret', 'api_key', 'api_secret'] as $secretField) {
                if (! array_key_exists($secretField, $data)) {
                    continue;
                }
                $secret = $data[$secretField];
                unset($data[$secretField]);
                if (is_string($secret) && $secret !== '') {
                    $marketplace->{$secretField} = $secret;
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
        $this->assertCan($request, 'marketplaces.hamropay');

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
        $this->assertCan($request, 'marketplaces.stores');

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

        // Open shipments follow the store, so Online POD uses the new marketplace.
        $resynced = app(MarketplaceShipmentResync::class)->forMerchants($ids);

        return ApiResponse::success([
            'marketplace_id' => $marketplace->id,
            'stores_count' => Merchant::query()->where('marketplace_id', $marketplace->id)->count(),
            'shipments_resynced' => MarketplaceShipmentResync::total($resynced),
        ], 'Stores updated.');
    }



    /**
     * Delete a marketplace that has no stores or shipments.
     * Issued API keys for that marketplace are removed with it.
     */
    public function destroy(Request $request, Marketplace $marketplace)
    {
        $this->assertCan($request, 'marketplaces.delete');

        $merchantCount = Merchant::query()->where('marketplace_id', $marketplace->id)->count();
        $shipmentCount = 0;
        if (Schema::hasTable('shipments') && Schema::hasColumn('shipments', 'marketplace_id')) {
            $shipmentCount = (int) DB::table('shipments')->where('marketplace_id', $marketplace->id)->count();
        }

        if ($merchantCount > 0 || $shipmentCount > 0) {
            return ApiResponse::error(
                'Cannot delete this marketplace while stores or shipments are still attached. Move them first.',
                422,
                [
                    'merchants' => $merchantCount,
                    'shipments' => $shipmentCount,
                ]
            );
        }

        $id = (int) $marketplace->id;

        DB::transaction(function () use ($marketplace) {
            DB::table('marketplace_api_keys')->where('marketplace_id', $marketplace->id)->delete();
            $marketplace->delete();
        });

        return ApiResponse::success(['id' => $id], 'Marketplace deleted.');
    }

    /**
     * Reissue marketplace API key (inbound + outbound POD).
     * Returns the new public key and secret ONCE. Old active keys are revoked.
     * Partners must update their stored Express credentials after this.
     */
    public function reissueApiKey(Request $request, Marketplace $marketplace)
    {
        $this->assertCan($request, 'marketplaces.update');

        $data = $request->validate([
            'environment' => ['nullable', 'in:test,live'],
            'name' => ['nullable', 'string', 'max:191'],
        ]);

        $result = app(MarketplaceApiKeyIssuer::class)->reissue(
            $marketplace,
            $data['environment'] ?? 'test',
            $data['name'] ?? null
        );

        return ApiResponse::success([
            'api_key_id' => $result['api_key_id'],
            'key_prefix' => $result['key_prefix'],
            'public_key' => $result['public_key'],
            'secret' => $result['secret'],
            'revoked_ids' => $result['revoked_ids'],
            'warning' => 'Copy the public_key and secret now. They are shown only once. Share them with the marketplace partner; inbound Express calls and Express outbound POD both use this pair.',
        ], 'Marketplace API key reissued. Copy credentials now — they will not be shown again.');
    }

    /**
     * Shipments that resolve to this marketplace the same way Online POD does:
     * the store's marketplace, or the shipment's own copy when the store has none.
     */
    private function effectiveShipmentCount(int $marketplaceId): int
    {
        if (! Schema::hasTable('shipments') || ! Schema::hasColumn('shipments', 'marketplace_id')) {
            return 0;
        }

        return (int) DB::table('shipments')
            ->leftJoin('merchants', 'merchants.id', '=', 'shipments.merchant_id')
            ->where(function ($q) use ($marketplaceId) {
                $q->where('merchants.marketplace_id', $marketplaceId)
                    ->orWhere(function ($inner) use ($marketplaceId) {
                        $inner->whereNull('merchants.marketplace_id')
                            ->where('shipments.marketplace_id', $marketplaceId);
                    });
            })
            ->count();
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
            'has_api_key' => filled(data_get($m->getAttributes(), 'api_key')),
            'has_api_secret' => filled(data_get($m->getAttributes(), 'api_secret')),
            'api_key_set' => filled(data_get($m->getAttributes(), 'api_key')),
            'api_secret_set' => filled(data_get($m->getAttributes(), 'api_secret')),
            'pod_payment_request_url' => $m->podPaymentRequestUrl(),
            'is_active' => (bool) $m->is_active,
            'is_default' => (bool) $m->is_default,
            'merchants_count' => $m->merchants_count ?? null,
            'meta' => $m->meta,
            'updated_at' => $m->updated_at,
        ];

        $issued = app(MarketplaceApiKeyIssuer::class)->presentActiveKey((int) $m->id);
        $row['issued_api_key'] = $issued;
        $row['issued_key_prefix'] = $issued['key_prefix'] ?? null;
        $row['can_outbound_pod'] = (bool) ($issued['can_outbound_pod'] ?? false);

        if ($detail) {
            $row['created_at'] = $m->created_at;
        }

        return $row;
    }
}