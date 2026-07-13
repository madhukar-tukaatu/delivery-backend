<?php
namespace Modules\Rate\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminSetupCrudController extends Controller
{
    public function serviceTypes(Request $r) { return response()->json(['success'=>true,'data'=>DB::table('service_types')->orderBy('id')->paginate($r->integer('per_page',25))]); }
    public function saveServiceType(Request $r) { $v=$r->all(); DB::table('service_types')->updateOrInsert(['code'=>$v['code']], $this->cols('service_types',array_merge($v,['created_at'=>now(),'updated_at'=>now()]))); return response()->json(['success'=>true]); }
    public function branchPricing(Request $r) { $q=DB::table('branch_pricing_rules')->leftJoin('branches','branches.id','=','branch_pricing_rules.branch_id')->leftJoin('service_types','service_types.id','=','branch_pricing_rules.service_type_id')->select('branch_pricing_rules.*','branches.name as branch_name','service_types.code as service_type_code')->orderByDesc('branch_pricing_rules.id'); return response()->json(['success'=>true,'data'=>$q->paginate($r->integer('per_page',25))]); }
    public function saveBranchPricing(Request $r) { $v=$r->all(); DB::table('branch_pricing_rules')->updateOrInsert(['branch_id'=>$v['branch_id'],'service_type_id'=>$v['service_type_id']], $this->cols('branch_pricing_rules',array_merge($v,['created_at'=>now(),'updated_at'=>now()]))); return response()->json(['success'=>true]); }
    public function transferLanes(Request $r) { $q=DB::table('branch_transfer_lanes')->leftJoin('branches as fb','fb.id','=','branch_transfer_lanes.from_branch_id')->leftJoin('branches as tb','tb.id','=','branch_transfer_lanes.to_branch_id')->leftJoin('service_types','service_types.id','=','branch_transfer_lanes.service_type_id')->select('branch_transfer_lanes.*','fb.name as from_branch_name','tb.name as to_branch_name','service_types.code as service_type_code')->orderByDesc('branch_transfer_lanes.id'); return response()->json(['success'=>true,'data'=>$q->paginate($r->integer('per_page',25))]); }
    public function saveTransferLane(Request $r) { $v=$r->all(); DB::table('branch_transfer_lanes')->updateOrInsert(['from_branch_id'=>$v['from_branch_id'],'to_branch_id'=>$v['to_branch_id'],'service_type_id'=>$v['service_type_id']], $this->cols('branch_transfer_lanes',array_merge($v,['created_at'=>now(),'updated_at'=>now()]))); return response()->json(['success'=>true]); }
    private function cols($table,$data){ return collect($data)->filter(fn($value,$column)=>Schema::hasColumn($table,$column))->toArray(); }
}
