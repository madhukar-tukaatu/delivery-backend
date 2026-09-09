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
    public function transferLanes(Request $r)
    {
        // Lanes reference coverage_locations (operational hubs), not the branches table.
        $q = DB::table('branch_transfer_lanes')
            ->leftJoin('coverage_locations as fb', 'fb.id', '=', 'branch_transfer_lanes.from_branch_id')
            ->leftJoin('coverage_locations as tb', 'tb.id', '=', 'branch_transfer_lanes.to_branch_id')
            ->select(
                'branch_transfer_lanes.*',
                'fb.name as from_branch_name',
                'fb.code as from_branch_code',
                'fb.latitude as from_latitude',
                'fb.longitude as from_longitude',
                'tb.name as to_branch_name',
                'tb.code as to_branch_code',
                'tb.latitude as to_latitude',
                'tb.longitude as to_longitude'
            );

        // Search across both branch names/codes.
        if ($r->filled('search')) {
            $search = trim((string) $r->input('search'));
            $q->where(function ($sub) use ($search) {
                $sub->where('fb.name', 'like', "%{$search}%")
                    ->orWhere('tb.name', 'like', "%{$search}%")
                    ->orWhere('fb.code', 'like', "%{$search}%")
                    ->orWhere('tb.code', 'like', "%{$search}%");
            });
        }

        if ($r->filled('from_branch_id')) {
            $q->where('branch_transfer_lanes.from_branch_id', $r->integer('from_branch_id'));
        }

        if ($r->filled('to_branch_id')) {
            $q->where('branch_transfer_lanes.to_branch_id', $r->integer('to_branch_id'));
        }

        if ($r->filled('service_type')) {
            $q->where('branch_transfer_lanes.service_type', $r->input('service_type'));
        }

        if ($r->has('is_active') && $r->input('is_active') !== null && $r->input('is_active') !== '') {
            $q->where('branch_transfer_lanes.is_active', $r->boolean('is_active'));
        }

        $q->orderByDesc('branch_transfer_lanes.id');

        return response()->json([
            'success' => true,
            'data'    => $q->paginate($r->integer('per_page', 25)),
        ]);
    }
    public function saveTransferLane(Request $r) { $v=$r->all(); DB::table('branch_transfer_lanes')->updateOrInsert(['from_branch_id'=>$v['from_branch_id'],'to_branch_id'=>$v['to_branch_id'],'service_type'=>$v['service_type']], $this->cols('branch_transfer_lanes',array_merge($v,['created_at'=>now(),'updated_at'=>now()]))); return response()->json(['success'=>true]); }
    private function cols($table,$data){ return collect($data)->filter(fn($value,$column)=>Schema::hasColumn($table,$column))->toArray(); }
}
