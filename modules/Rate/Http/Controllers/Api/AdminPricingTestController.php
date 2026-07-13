<?php
namespace Modules\Rate\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Rate\Services\PricingEngineService;
class AdminPricingTestController extends Controller
{
    public function test(Request $request, PricingEngineService $service) {
        $v=$request->validate(['merchant_id'=>['nullable','integer'],'pickup_latitude'=>['required','numeric'],'pickup_longitude'=>['required','numeric'],'delivery_latitude'=>['required','numeric'],'delivery_longitude'=>['required','numeric'],'parcel_weight'=>['required','numeric','min:0.01'],'parcel_value'=>['nullable','numeric'],'payment_type'=>['required','in:cod,prepaid'],'cod_amount'=>['nullable','numeric'],'service_type'=>['required','in:standard,express,same_day']]);
        return response()->json(['success'=>true,'data'=>$service->calculate($v,$v['merchant_id']??null)]);
    }
}
