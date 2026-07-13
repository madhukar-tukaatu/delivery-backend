<?php

namespace Modules\Shipment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Shipment\Services\ShipmentWorkflowService;

class AdminShipmentTaskController extends Controller
{
    public function index(Request $request) {
        $q = DB::table('shipment_tasks')->leftJoin('shipments','shipments.id','=','shipment_tasks.shipment_id')->leftJoin('branches','branches.id','=','shipment_tasks.branch_id')->select('shipment_tasks.*','shipments.tracking_number','shipments.customer_name','shipments.customer_phone','branches.name as branch_name')->orderByDesc('shipment_tasks.id');
        return response()->json(['success'=>true,'data'=>$q->paginate($request->integer('per_page',25))]);
    }
    public function assign(Request $request, $id, ShipmentWorkflowService $workflow) {
        $v=$request->validate(['assigned_staff_id'=>['nullable','integer'],'assigned_rider_id'=>['nullable','integer'],'assigned_user_id'=>['nullable','integer']]);
        return response()->json(['success'=>true,'data'=>$workflow->assignTask((int)$id,$v['assigned_staff_id']??null,$v['assigned_rider_id']??null,$v['assigned_user_id']??null)]);
    }
    public function updateStatus(Request $request, $id, ShipmentWorkflowService $workflow) {
        $v=$request->validate(['status'=>['required','in:pending,assigned,accepted,in_progress,completed,failed,cancelled'],'note'=>['nullable','string']]);
        return response()->json(['success'=>true,'data'=>$workflow->updateTaskStatus((int)$id,$v['status'],$v['note']??null)]);
    }
}
