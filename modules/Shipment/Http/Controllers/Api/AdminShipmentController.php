<?php

namespace Modules\Shipment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminShipmentController extends Controller
{
    public function index(Request $request) {
        $q = DB::table('shipments')->leftJoin('branches as pb','pb.id','=','shipments.pickup_branch_id')->leftJoin('branches as db','db.id','=','shipments.delivery_branch_id')->select('shipments.*','pb.name as pickup_branch_name','db.name as delivery_branch_name')->orderByDesc('shipments.id');
        return response()->json(['success'=>true,'data'=>$q->paginate($request->integer('per_page',25))]);
    }
    public function show($id) {
        return response()->json(['success'=>true,'data'=>[
            'shipment'=>DB::table('shipments')->where('id',$id)->first(),
            'price_breakdown'=>DB::table('shipment_price_breakdowns')->where('shipment_id',$id)->first(),
            'tasks'=>DB::table('shipment_tasks')->where('shipment_id',$id)->orderBy('id')->get(),
            'status_logs'=>DB::table('shipment_status_logs')->where('shipment_id',$id)->orderBy('id')->get(),
            'notifications'=>DB::table('staff_notifications')->where('shipment_id',$id)->orderByDesc('id')->get(),
        ]]);
    }
}
