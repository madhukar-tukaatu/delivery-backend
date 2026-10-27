<?php

namespace Modules\Shipment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Shipment\Models\Shipment;

class AdminShipmentController extends Controller
{
    public function index(Request $request) {
        $q = DB::table('shipments')->leftJoin('branches as pb','pb.id','=','shipments.pickup_branch_id')->leftJoin('branches as db','db.id','=','shipments.delivery_branch_id')->select('shipments.*','pb.name as pickup_branch_name','db.name as delivery_branch_name')->orderByDesc('shipments.id');
        return response()->json(['success'=>true,'data'=>$q->paginate($request->integer('per_page',25))]);
    }

    public function show(Request $request, $id) {
        $shipment = DB::table('shipments')->where('id', $id)->first();

        if (!$shipment) {
            return response()->json([
                'success' => false,
                'message' => 'Shipment not found.',
            ], 404);
        }

        // Same branch scope as the shipments list (ShipmentController@index).
        if (!$this->canViewShipment($request->user(), $shipment)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to this shipment.',
            ], 403);
        }

        $this->attachListFields($shipment);

        return response()->json(['success' => true, 'data' => [
            'shipment' => $shipment,
            'price_breakdown' => $this->optionalFirst('shipment_price_breakdowns', 'shipment_id', $id),
            'tasks' => $this->optionalGet('shipment_tasks', 'shipment_id', $id, 'id'),
            'status_logs' => $this->optionalGet('shipment_status_logs', 'shipment_id', $id, 'id'),
            'notifications' => $this->optionalGet('staff_notifications', 'shipment_id', $id, 'id', true),
        ]]);
    }

    /**
     * Add the same branch names and computed fields the shipments list uses, so the
     * drawer shows current branch names (not the stale path_text snapshot) and the
     * same transfer / collect values as the table. Additive only.
     */
    private function attachListFields(object $shipment): void
    {
        $keys = ['origin', 'destination', 'current', 'next_hop'];
        $ids = array_values(array_filter(array_map(
            fn ($key) => $shipment->{$key . '_branch_id'} ?? null,
            $keys
        )));
        $branches = $ids
            ? DB::table('branches')->whereIn('id', $ids)->get(['id', 'name', 'code'])->keyBy('id')
            : collect();

        foreach ($keys as $key) {
            $branchId = $shipment->{$key . '_branch_id'} ?? null;
            $branch = $branchId ? $branches->get($branchId) : null;
            $shipment->{$key . '_branch'} = $branch;
            $shipment->{$key . '_branch_name'} = $branch->name ?? null;
        }

        try {
            $model = Shipment::find($shipment->id);
            if ($model) {
                $shipment->is_transfer = $model->is_transfer;
                $shipment->transfer_stage_label = $model->transfer_stage_label;
                $shipment->collect_amount = $model->collect_amount;
            }
        } catch (\Throwable) {
            // computed fields are optional extras
        }
    }

    private function canViewShipment($user, object $shipment): bool
    {
        if (!$user) {
            return false;
        }

        $isGlobalAdmin = (bool) (
            $user->is_super_admin
            || $user->role === 'super_admin'
            || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (method_exists($user, 'hasRole') && $user->hasRole('main_admin'))
        );
        if ($isGlobalAdmin) {
            return true;
        }

        $branchId = (int) ($user->branch_id ?? 0);
        if (!$branchId) {
            return false;
        }

        return in_array($branchId, array_map('intval', [
            $shipment->origin_branch_id ?? 0,
            $shipment->origin_sub_branch_id ?? 0,
            $shipment->destination_branch_id ?? 0,
            $shipment->destination_sub_branch_id ?? 0,
            $shipment->current_branch_id ?? 0,
            $shipment->current_sub_branch_id ?? 0,
        ]), true);
    }
    private function optionalFirst(string $table, string $fk, mixed $id): mixed
    {
        if (!Schema::hasTable($table)) {
            return null;
        }

        return DB::table($table)->where($fk, $id)->first();
    }

    private function optionalGet(
        string $table,
        string $fk,
        mixed $id,
        string $orderCol,
        bool $desc = false
    ): array {
        if (!Schema::hasTable($table)) {
            return [];
        }

        $q = DB::table($table)->where($fk, $id);
        $q = $desc ? $q->orderByDesc($orderCol) : $q->orderBy($orderCol);

        return $q->get()->all();
    }
}