<?php

namespace Modules\Shipment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminShipmentController extends Controller
{
    public function index(Request $request) {
        $q = DB::table('shipments')->leftJoin('branches as pb','pb.id','=','shipments.pickup_branch_id')->leftJoin('branches as db','db.id','=','shipments.delivery_branch_id')->select('shipments.*','pb.name as pickup_branch_name','db.name as delivery_branch_name')->orderByDesc('shipments.id');
        return response()->json(['success'=>true,'data'=>$q->paginate($request->integer('per_page',25))]);
    }

    public function show($id) {
        $shipment = DB::table('shipments')->where('id', $id)->first();

        if (!$shipment) {
            return response()->json([
                'success' => false,
                'message' => 'Shipment not found.',
            ], 404);
        }

        return response()->json(['success' => true, 'data' => [
            'shipment' => $shipment,
            'price_breakdown' => $this->optionalFirst('shipment_price_breakdowns', 'shipment_id', $id),
            'tasks' => $this->optionalGet('shipment_tasks', 'shipment_id', $id, 'id'),
            'status_logs' => $this->optionalGet('shipment_status_logs', 'shipment_id', $id, 'id'),
            'notifications' => $this->optionalGet('staff_notifications', 'shipment_id', $id, 'id', true),
        ]]);
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