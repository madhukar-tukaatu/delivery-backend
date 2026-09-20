<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Billing\Models\PaymentGatewayAccount;
use Modules\Billing\Services\PaymentGatewayAccountService;

class PaymentGatewayAccountController extends Controller
{
    public function __construct(private PaymentGatewayAccountService $accounts)
    {
    }

    protected function assertCanManageCompany(Request $request): void
    {
        $user = $request->user();
        $ok = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $ok = $ok || $user->hasRole(['super_admin', 'main_admin']);
        abort_unless($ok, 403, 'Only superadmin can manage company payment accounts.');
    }

    public function catalog()
    {
        return ApiResponse::success([
            'gateways' => [
                ['code' => 'hamropay', 'label' => 'HamroPay', 'status' => 'live'],
                ['code' => 'esewa', 'label' => 'eSewa', 'status' => 'planned'],
                ['code' => 'khalti', 'label' => 'Khalti', 'status' => 'planned'],
                ['code' => 'connectips', 'label' => 'ConnectIPS', 'status' => 'planned'],
            ],
            'owners' => [
                ['type' => 'company', 'label' => 'Tukaatu Express (superadmin)'],
                ['type' => 'branch', 'label' => 'Branch'],
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
            $q->where('gateway', $request->gateway);
        }

        // Branch managers only see their branch
        if (! ($user->isSuperAdmin() ?? false) && ! $user->hasRole(['super_admin', 'main_admin', 'admin'])) {
            $branchId = $user->branch_id ?? null;
            abort_unless($branchId, 403);
            $q->where('owner_type', 'branch')->where('owner_id', $branchId);
        }

        $rows = $q->get()->map(fn ($a) => $this->accounts->masked($a));

        return ApiResponse::success($rows);
    }

    public function upsertCompany(Request $request)
    {
        $this->assertCanManageCompany($request);

        $data = $request->validate([
            'gateway' => ['required', 'string', 'in:hamropay,esewa,khalti,connectips'],
            'label' => ['nullable', 'string', 'max:64'],
            'is_enabled' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'credentials' => ['required', 'array'],
        ]);

        $account = $this->accounts->upsert(
            'company',
            null,
            $data['gateway'],
            $data['credentials'],
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
        $isHq = ($user->isSuperAdmin() ?? false) || $user->hasRole(['super_admin', 'main_admin', 'admin']);
        if (! $isHq) {
            abort_unless((int) ($user->branch_id ?? 0) === (int) $branchId, 403);
        }

        $data = $request->validate([
            'gateway' => ['required', 'string', 'in:hamropay,esewa,khalti,connectips'],
            'label' => ['nullable', 'string', 'max:64'],
            'is_enabled' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'credentials' => ['required', 'array'],
        ]);

        $account = $this->accounts->upsert(
            'branch',
            $branchId,
            $data['gateway'],
            $data['credentials'],
            [
                'label' => $data['label'] ?? 'default',
                'is_enabled' => $data['is_enabled'] ?? true,
                'is_default' => $data['is_default'] ?? true,
            ],
        );

        return ApiResponse::success($this->accounts->masked($account), 'Branch payment account saved.');
    }
}
