<?php

namespace Modules\Rate\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Merchant\Services\MerchantApiKeyGuard;
use Modules\Rate\Services\PricingEngineService;

class PublicPricingQuoteController extends Controller
{
    public function store(Request $request, MerchantApiKeyGuard $guard, PricingEngineService $pricingEngine)
    {
        // dd('here');
        $merchantKey = $guard->resolve($request);
        $validated = $request->validate([
            'pickup_address'=>['nullable','string','max:255'],
            'pickup_latitude'=>['required','numeric'],
            'pickup_longitude'=>['required','numeric'],
            'delivery_address'=>['nullable','string','max:255'],
            'delivery_latitude'=>['required','numeric'],
            'delivery_longitude'=>['required','numeric'],
            'parcel_weight'=>['required','numeric','min:0.01'],
            'parcel_value'=>['nullable','numeric','min:0'],
            'payment_type'=>['required','in:cod,prepaid'],
            'cod_amount'=>['nullable','numeric','min:0'],
            'service_type'=>['required','in:standard,express,same_day'],
        ]);

        $merchantId = $merchantKey->merchant_id ?? null;
        $quote = $pricingEngine->calculate($validated, $merchantId);
        $quoteNumber = 'QT-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(5));

        $quoteId = DB::table('pricing_quotes')->insertGetId($this->cols('pricing_quotes', [
            'quote_number'=>$quoteNumber,
            'merchant_id'=>$merchantId,
            'pickup_branch_id'=>$quote['pickup_branch']['id'],
            'delivery_branch_id'=>$quote['delivery_branch']['id'],
            'pickup_address'=>$validated['pickup_address'] ?? null,
            'pickup_latitude'=>$validated['pickup_latitude'],
            'pickup_longitude'=>$validated['pickup_longitude'],
            'delivery_address'=>$validated['delivery_address'] ?? null,
            'delivery_latitude'=>$validated['delivery_latitude'],
            'delivery_longitude'=>$validated['delivery_longitude'],
            'parcel_weight'=>$validated['parcel_weight'],
            'parcel_value'=>$validated['parcel_value'] ?? 0,
            'payment_type'=>$validated['payment_type'],
            'cod_amount'=>$validated['cod_amount'] ?? 0,
            'service_type'=>$quote['service_type']['code'],
            'service_type_id'=>$quote['service_type']['id'],
            'final_price'=>$quote['final_price'],
            'sla_due_at'=>$quote['sla_due_at'],
            'expires_at'=>$quote['valid_until'],
            'snapshot_json'=>json_encode($quote),
            'created_at'=>now(),
            'updated_at'=>now(),
        ]));

        return response()->json([
            'success'=>true,
            'quote_id'=>$quoteNumber,
            'pricing_quote_id'=>$quoteId,
            'currency'=>$quote['currency'],
            'service_type'=>$quote['service_type'],
            'final_delivery_fee'=>$quote['final_price'],
            'sla_due_at'=>$quote['sla_due_at']->toIso8601String(),
            'valid_until'=>$quote['valid_until']->toIso8601String(),
            'pickup_branch'=>$quote['pickup_branch'],
            'delivery_branch'=>$quote['delivery_branch'],
            'estimated_hours'=>$quote['estimated_hours'],
            'breakdown'=>$quote['breakdown'],
        ]);
    }

    private function cols(string $table, array $data): array
    {
        return collect($data)->filter(fn($value,$column) => Schema::hasColumn($table,$column))->toArray();
    }
}
