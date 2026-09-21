<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Billing\Models\PaymentGatewayAccount;
use Modules\Billing\Services\PaymentGatewayAccountService;
use Modules\Billing\Support\PaymentGatewayCatalog;

class PaymentGatewayAccountController extends Controller
{
    public function __construct(private PaymentGatewayAccountService $accounts)
    {
    }

    protected function assertCanManageCompany(Request $request): void
    {
        $user = $request->user();
        $ok = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $ok = $ok || (method_exists($user, 'hasRole') && $user->hasRole(['super_admin', 'main_admin']));
        abort_unless($ok, 403, 'Only superadmin can manage company payment accounts.');
    }

    protected function isHqUser($user): bool
    {
        $ok = method_exists($user, 'isSuperAdmin') && ($user->isSuperAdmin() ?? false);
        $ok = $ok || (method_exists($user, 'hasRole') && $user->hasRole(['super_admin', 'main_admin', 'admin']));

        return (bool) $ok;
    }

    protected function canManageCompany($user): bool
    {
        $ok = method_exists($user, 'isSuperAdmin') && ($user->isSuperAdmin() ?? false);
        $ok = $ok || (method_exists($user, 'hasRole') && $user->hasRole(['super_admin', 'main_admin']));

        return (bool) $ok;
    }

    public function catalog(Request $request)
    {
        return ApiResponse::success([
            'gateways' => PaymentGatewayCatalog::gateways(),
            'owners' => PaymentGatewayCatalog::owners(),
            'can_manage_company' => $this->canManageCompany($request->user()),
            'viewer' => [
                'is_hq' => $this->isHqUser($request->user()),
                'branch_id' => $request->user()->branch_id ?? null,
            ],
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $q = PaymentGatewayAccount::query()->orderByDesc('id');

        if ($request->filled('owner_type')) {
            $q->where('owner_type', $request->owner_type);
        }
        if ($request->filled('owner_id')) {
            $q->where('owner_id', $request->owner_id);
        }
        if ($request->filled('gateway')) {
            $q->where('gateway', strtolower((string) $request->gateway));
        }

        // Branch-scoped users only see their branch accounts
        if (! $this->isHqUser($user)) {
            $branchId = $user->branch_id ?? null;
            abort_unless($branchId, 403, 'Branch context required.');
            $q->where('owner_type', 'branch')->where('owner_id', $branchId);
        }

        $rows = $q->get()->map(fn ($a) => $this->accounts->masked($a));

        return ApiResponse::success($rows);
    }

    public function upsertCompany(Request $request)
    {
        $this->assertCanManageCompany($request);

        $codes = implode(',', PaymentGatewayCatalog::codes());

        $data = $request->validate([
            'gateway' => ['required', 'string', 'in:'.$codes],
            'label' => ['nullable', 'string', 'max:64'],
            'is_enabled' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'credentials' => ['required', 'array'],
        ]);

        $account = $this->accounts->upsert(
            'company',
            null,
            $data['gateway'],
            $this->accounts->filterCredentials($data['gateway'], $data['credentials']),
            [
                'label' => $data['label'] ?? 'default',
                'is_enabled' => $data['is_enabled'] ?? true,
                'is_default' => $data['is_default'] ?? true,
            ],
        );

        return ApiResponse::success($this->accounts->masked($account), 'Company payment account saved.');
    }

    public function upsertBranch(Request $request, int $branchId)
    {
        $user = $request->user();
        if (! $this->isHqUser($user)) {
            abort_unless((int) ($user->branch_id ?? 0) === (int) $branchId, 403, 'You can only manage your own branch account.');
        }

        $codes = implode(',', PaymentGatewayCatalog::codes());

        $data = $request->validate([
            'gateway' => ['required', 'string', 'in:'.$codes],
            'label' => ['nullable', 'string', 'max:64'],
            'is_enabled' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'credentials' => ['required', 'array'],
        ]);

        $account = $this->accounts->upsert(
            'branch',
            $branchId,
            $data['gateway'],
            $this->accounts->filterCredentials($data['gateway'], $data['credentials']),
            [
                'label' => $data['label'] ?? 'default',
                'is_enabled' => $data['is_enabled'] ?? true,
                'is_default' => $data['is_default'] ?? true,
            ],
        );

        return ApiResponse::success($this->accounts->masked($account), 'Branch payment account saved.');
    }
}
