<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery bills belong to the ORIGIN branch (it bills the merchant / marketplace
 * and pays transit/destination branches their shares). Before the origin-billing
 * change, invoices.branch_id was stamped with the destination branch, so the
 * delivering branch saw the bill. Re-stamp every not-yet-paid delivery bill to
 * the shipment's origin branch (falling back to the merchant's default branch).
 *
 * Paid bills are left untouched: their money already moved through that branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'branch_id') || ! Schema::hasColumn('invoices', 'shipment_id')) {
            return;
        }

        $paidStatuses = ['paid', 'settled', 'cancelled', 'void'];

        $rows = DB::table('invoices as i')
            ->join('shipments as s', 's.id', '=', 'i.shipment_id')
            ->leftJoin('merchants as m', 'm.id', '=', 's.merchant_id')
            ->where('i.type', 'delivery_charges')
            ->whereNotIn('i.status', $paidStatuses)
            ->selectRaw('i.id, i.branch_id, COALESCE(s.origin_branch_id, m.default_branch_id) as origin_id')
            ->get();

        foreach ($rows as $row) {
            if ($row->origin_id && (int) $row->origin_id !== (int) $row->branch_id) {
                DB::table('invoices')->where('id', $row->id)->update([
                    'branch_id' => (int) $row->origin_id,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Data correction only; previous (destination) stamping is not restored.
    }
};
