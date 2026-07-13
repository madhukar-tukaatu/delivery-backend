<?php

namespace Modules\Shipment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Services\MerchantApiKeyGuard;
use Modules\Shipment\Services\ShipmentWorkflowService;

class PublicShipmentController extends Controller
{
    public function store(Request $request, MerchantApiKeyGuard $guard, ShipmentWorkflowService $workflow)
    {
        $merchantKey = $guard->resolve($request);
        $merchantId = $merchantKey->merchant_id ?? null;

        $v = $request->validate([
            'quote_id'=>['required','string'],
            'merchant_order_id'=>['required','string','max:100'],
            'customer_name'=>['required','string','max:150'],
            'customer_phone'=>['required','string','max:50'],
            'customer_email'=>['nullable','email','max:150'],
            'pickup_address'=>['required','string','max:255'],
            'pickup_latitude'=>['required','numeric'],
            'pickup_longitude'=>['required','numeric'],
            'delivery_address'=>['required','string','max:255'],
            'delivery_latitude'=>['required','numeric'],
            'delivery_longitude'=>['required','numeric'],
            'parcel_weight'=>['required','numeric','min:0.01'],
            'parcel_value'=>['nullable','numeric','min:0'],
            'payment_type'=>['required','in:cod,prepaid'],
            'cod_amount'=>['nullable','numeric','min:0'],
            'service_type'=>['required','in:standard,express,same_day'],
            'items'=>['nullable','array'],
        ]);

        $quote = DB::table('pricing_quotes')->where('quote_number',$v['quote_id'])->first();
        if (!$quote) throw ValidationException::withMessages(['quote_id'=>'Quote not found.']);
        if ($merchantId && $quote->merchant_id && (int)$quote->merchant_id !== (int)$merchantId) throw ValidationException::withMessages(['quote_id'=>'Quote does not belong to this merchant.']);
        if (!empty($quote->expires_at) && now()->greaterThan($quote->expires_at)) throw ValidationException::withMessages(['quote_id'=>'Quote has expired. Please generate a new quote.']);

        return DB::transaction(function() use ($v,$quote,$merchantId,$workflow) {
            $snapshot = json_decode($quote->snapshot_json ?? '{}', true);
            $tracking = 'TKX-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(5));
            $shipmentId = DB::table('shipments')->insertGetId($this->cols('shipments', [
                'tracking_number'=>$tracking,
                'quote_number'=>$quote->quote_number,
                'pricing_quote_id'=>$quote->id,
                'merchant_id'=>$merchantId,
                'merchant_order_id'=>$v['merchant_order_id'],
                'customer_name'=>$v['customer_name'],
                'customer_phone'=>$v['customer_phone'],
                'customer_email'=>$v['customer_email'] ?? null,
                'pickup_address'=>$v['pickup_address'],
                'pickup_latitude'=>$v['pickup_latitude'],
                'pickup_longitude'=>$v['pickup_longitude'],
                'delivery_address'=>$v['delivery_address'],
                'delivery_latitude'=>$v['delivery_latitude'],
                'delivery_longitude'=>$v['delivery_longitude'],
                'parcel_weight'=>$v['parcel_weight'],
                'parcel_value'=>$v['parcel_value'] ?? 0,
                'payment_type'=>$v['payment_type'],
                'cod_amount'=>$v['cod_amount'] ?? 0,
                'delivery_fee'=>$quote->final_price,
                'pickup_branch_id'=>$quote->pickup_branch_id,
                'delivery_branch_id'=>$quote->delivery_branch_id,
                'service_type_id'=>$quote->service_type_id,
                'service_type'=>$quote->service_type,
                'status'=>'pickup_pending',
                'sla_due_at'=>$quote->sla_due_at,
                'confirmed_at'=>now(),
                'items_json'=>json_encode($v['items'] ?? []),
                'pricing_snapshot_json'=>json_encode($snapshot),
                'created_at'=>now(),
                'updated_at'=>now(),
            ]));

            $shipment = DB::table('shipments')->where('id',$shipmentId)->first();
            $workflow->createPriceBreakdown($shipmentId, $quote->id, $snapshot);
            $workflow->createWorkflow($shipment);
            $shipment = DB::table('shipments')->where('id',$shipmentId)->first();

            return response()->json([
                'success'=>true,
                'shipment_id'=>$shipmentId,
                'tracking_number'=>$tracking,
                'status'=>$shipment->status ?? 'pickup_pending',
                'delivery_fee'=>(float)$quote->final_price,
                'pickup_branch_id'=>$quote->pickup_branch_id,
                'delivery_branch_id'=>$quote->delivery_branch_id,
                'tracking_url'=>url('/tracking?code=' . $tracking),
            ], 201);
        });
    }

    private function cols(string $table, array $data): array
    {
        return collect($data)->filter(fn($value,$column) => Schema::hasColumn($table,$column))->toArray();
    }
}
