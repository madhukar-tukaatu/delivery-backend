<?php

namespace Modules\Shipment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminNotificationController extends Controller
{
    public function index(Request $request) {
        $q=DB::table('staff_notifications')->leftJoin('branches','branches.id','=','staff_notifications.branch_id')->leftJoin('shipments','shipments.id','=','staff_notifications.shipment_id')->select('staff_notifications.*','branches.name as branch_name','shipments.tracking_number')->orderByDesc('staff_notifications.id');
        return response()->json(['success'=>true,'data'=>$q->paginate($request->integer('per_page',25))]);
    }
    public function markRead($id) { DB::table('staff_notifications')->where('id',$id)->update(['is_read'=>true,'read_at'=>now(),'updated_at'=>now()]); return response()->json(['success'=>true]); }
    public function markAllRead() { DB::table('staff_notifications')->where('is_read',false)->update(['is_read'=>true,'read_at'=>now(),'updated_at'=>now()]); return response()->json(['success'=>true]); }
}
