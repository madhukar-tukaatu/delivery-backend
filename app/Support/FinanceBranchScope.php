<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Modules\Billing\Models\Invoice;
use Modules\Branch\Services\BranchVisibilityService;
use Modules\POD\Models\PodRecord;
use Modules\Settlement\Models\MerchantSettlement;
use Modules\Shipment\Models\Shipment;

/**
 * Branch visibility for finance data (settlements, invoices / delivery charges,
 * POD, HQ commissions, finance report totals).
 *
 * Who is scoped:
 *  - HQ (super_admin, main_admin, admin): no filter. Same roles the Billing
 *    controllers already treat as HQ (BranchCommissionController::isHq,
 *    PaymentGatewayAccountController::isHqUser).
 *  - Merchant users: not branch-scoped here; controllers keep filtering by merchant_id.
 *  - Everyone else (branch_manager, sub_branch_manager, accounts_staff, ...):
 *    users.branch_id plus its child branches via BranchVisibilityService.
 *    No branch => sees nothing.
 *
 * Which branch owns a finance row:
 *  - Shipment money owner = COALESCE(destination_branch_id, current_branch_id, origin_branch_id),
 *    the same order MerchantDeliveryBillingService uses for invoices.branch_id and
 *    SettlementHamroPayService uses to pick the paying branch. destination_sub_branch_id
 *    also matches so sub-branch users see their deliveries.
 *  - shipment_branch_shares: branch_id (a branch sees only its own share rows).
 *  - inter_branch_statements: from_branch_id or to_branch_id.
 *  - invoices: invoices.branch_id = ORIGIN (billing) branch. Legacy rows with NULL branch_id fall
 *    back to shipments.origin_branch_id. Transit/destination branches never see the merchant bill;
 *    they get paid via shipment_branch_shares and inter_branch_statements.
 *  - merchant_settlements: no branch column; visible when any item shipment is owned by the branch.
 *  - pod_records: deposited_to_branch_id, or the shipment owner before deposit.
 *  - branch_commission_bills / branch_commission_settlements: branch_id.
 */
class FinanceBranchScope
{
    public const HQ_ROLES = ['super_admin', 'main_admin', 'admin'];

    public static function isHq(?Authenticatable $user): bool
    {
        if (! $user) {
            return false;
        }

        if (($user->is_super_admin ?? false) === true || ($user->role ?? null) === 'super_admin') {
            return true;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        try {
            return method_exists($user, 'hasAnyRole') && $user->hasAnyRole(self::HQ_ROLES);
        } catch (\Throwable) {
            return in_array($user->role ?? null, self::HQ_ROLES, true);
        }
    }

    public static function isMerchant(?Authenticatable $user): bool
    {
        if (! $user) {
            return false;
        }

        if (($user->role ?? null) === 'merchant') {
            return true;
        }

        try {
            return method_exists($user, 'hasRole') && $user->hasRole('merchant') && ! empty($user->merchant_id);
        } catch (\Throwable) {
            return false;
        }
    }

    /** True when the branch filter must be applied to this user. */
    public static function applies(?Authenticatable $user): bool
    {
        return $user !== null && ! self::isHq($user) && ! self::isMerchant($user);
    }

    /**
     * Branch ids this user may see. Empty array = nothing.
     *
     * @return list<int>
     */
    public static function branchIds(?Authenticatable $user): array
    {
        if (! $user || empty($user->branch_id)) {
            return [];
        }

        $ids = [(int) $user->branch_id];

        try {
            if ($user instanceof \App\Models\User) {
                $ids = array_merge($ids, app(BranchVisibilityService::class)->visibleBranchIds($user));
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return array_values(array_unique(array_map('intval', array_filter($ids))));
    }

    /**
     * Branch id to use for a write (e.g. POD deposit). Branch users always get
     * their own branch; HQ may pass one.
     */
    public static function writeBranchId(?Authenticatable $user, $requested = null): ?int
    {
        if (self::applies($user)) {
            return $user?->branch_id ? (int) $user->branch_id : null;
        }

        if ($requested !== null && $requested !== '') {
            return (int) $requested;
        }

        return $user?->branch_id ? (int) $user->branch_id : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Query scopes
    |--------------------------------------------------------------------------
    */

    /** Shipment money owner. $table is the shipments table name or alias. */
    public static function whereShipmentOwnedBy($query, array $branchIds, string $table = 'shipments'): void
    {
        if ($branchIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $marks = implode(',', array_fill(0, count($branchIds), '?'));
        $query->where(function ($q) use ($table, $marks, $branchIds) {
            $q->whereRaw(
                "COALESCE({$table}.destination_branch_id, {$table}.current_branch_id, {$table}.origin_branch_id) IN ({$marks})",
                $branchIds
            )->orWhereIn("{$table}.destination_sub_branch_id", $branchIds);
        });
    }

    public static function scopeShipments(Builder $query, ?Authenticatable $user): Builder
    {
        if (self::applies($user)) {
            self::whereShipmentOwnedBy($query, self::branchIds($user), $query->getModel()->getTable());
        }

        return $query;
    }

    public static function scopeInvoices(Builder $query, ?Authenticatable $user): Builder
    {
        if (! self::applies($user)) {
            return $query;
        }

        $ids = self::branchIds($user);
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        $table = $query->getModel()->getTable();

        // Delivery bills belong to the ORIGIN (billing) branch only. Transit and
        // destination branches see their money via shares / inter-branch statements.
        return $query->where(function ($q) use ($ids, $table) {
            $q->whereIn("{$table}.branch_id", $ids)
                ->orWhere(function ($legacy) use ($ids, $table) {
                    $legacy->whereNull("{$table}.branch_id")
                        ->whereExists(function (QueryBuilder $s) use ($ids, $table) {
                            $s->selectRaw('1')
                                ->from('shipments as fs')
                                ->whereColumn('fs.id', "{$table}.shipment_id")
                                ->whereIn('fs.origin_branch_id', $ids);
                        });
                });
        });
    }

    public static function scopeSettlements(Builder $query, ?Authenticatable $user): Builder
    {
        if (! self::applies($user)) {
            return $query;
        }

        $ids = self::branchIds($user);
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        $table = $query->getModel()->getTable();

        return $query->whereExists(function (QueryBuilder $s) use ($ids, $table) {
            $s->selectRaw('1')
                ->from('merchant_settlement_items as fsi')
                ->join('shipments as fs', 'fs.id', '=', 'fsi.shipment_id')
                ->whereColumn('fsi.merchant_settlement_id', "{$table}.id");
            self::whereShipmentOwnedBy($s, $ids, 'fs');
        });
    }

    public static function scopePodRecords(Builder $query, ?Authenticatable $user): Builder
    {
        if (! self::applies($user)) {
            return $query;
        }

        $ids = self::branchIds($user);
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        $table = $query->getModel()->getTable();

        return $query->where(function ($q) use ($ids, $table) {
            $q->whereIn("{$table}.deposited_to_branch_id", $ids)
                ->orWhereExists(function (QueryBuilder $s) use ($ids, $table) {
                    $s->selectRaw('1')
                        ->from('shipments as fs')
                        ->whereColumn('fs.id', "{$table}.shipment_id");
                    self::whereShipmentOwnedBy($s, $ids, 'fs');
                });
        });
    }

    /** branch_commission_bills / branch_commission_settlements and other tables with a branch_id owner column. */
    public static function scopeBranchColumn(Builder $query, ?Authenticatable $user, string $column = 'branch_id'): Builder
    {
        if (! self::applies($user)) {
            return $query;
        }

        $ids = self::branchIds($user);
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($query->getModel()->getTable().'.'.$column, $ids);
    }

    /** shipment_branch_shares: only the user's own branch rows. */
    public static function scopeShares(Builder $query, ?Authenticatable $user): Builder
    {
        return self::scopeBranchColumn($query, $user, 'branch_id');
    }

    /** inter_branch_statements: user's branch is the payer or the receiver. */
    public static function scopeStatements(Builder $query, ?Authenticatable $user): Builder
    {
        if (! self::applies($user)) {
            return $query;
        }

        $ids = self::branchIds($user);
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        $table = $query->getModel()->getTable();

        return $query->where(function ($q) use ($ids, $table) {
            $q->whereIn("{$table}.from_branch_id", $ids)
                ->orWhereIn("{$table}.to_branch_id", $ids);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Single-record checks (404 when outside the branch)
    |--------------------------------------------------------------------------
    */

    public static function canSeeInvoice(?Authenticatable $user, Invoice $invoice): bool
    {
        if (self::isMerchant($user)) {
            return (int) $user->merchant_id === (int) $invoice->merchant_id;
        }

        return self::recordVisible($user, Invoice::query(), $invoice->getKey(), 'scopeInvoices');
    }

    public static function canSeeSettlement(?Authenticatable $user, MerchantSettlement $settlement): bool
    {
        if (self::isMerchant($user)) {
            return (int) $user->merchant_id === (int) $settlement->merchant_id;
        }

        return self::recordVisible($user, MerchantSettlement::query(), $settlement->getKey(), 'scopeSettlements');
    }

    public static function canSeePod(?Authenticatable $user, PodRecord $pod): bool
    {
        if (self::isMerchant($user)) {
            return (int) $user->merchant_id === (int) $pod->merchant_id;
        }

        return self::recordVisible($user, PodRecord::query(), $pod->getKey(), 'scopePodRecords');
    }

    public static function canSeeShipment(?Authenticatable $user, Shipment $shipment): bool
    {
        if (self::isMerchant($user)) {
            return (int) $user->merchant_id === (int) $shipment->merchant_id;
        }

        return self::recordVisible($user, Shipment::query(), $shipment->getKey(), 'scopeShipments');
    }

    public static function canSeeBranch(?Authenticatable $user, $branchId): bool
    {
        if (! self::applies($user)) {
            return true;
        }

        return $branchId !== null && in_array((int) $branchId, self::branchIds($user), true);
    }

    private static function recordVisible(?Authenticatable $user, Builder $query, $key, string $scope): bool
    {
        if (! self::applies($user)) {
            return $user !== null;
        }

        return call_user_func([self::class, $scope], $query->whereKey($key), $user)->exists();
    }
}
